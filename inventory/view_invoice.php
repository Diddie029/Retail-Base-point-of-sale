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

// Get invoice status history
$status_timeline = [
    [
        'status' => 'pending',
        'date' => $invoice['created_at'],
        'user' => $invoice['created_by_name'] ?? 'System',
        'description' => 'Order created'
    ]
];

// Add current status if different from created
if ($invoice['status'] !== 'pending' && $invoice['updated_at'] !== $invoice['created_at']) {
    $status_timeline[] = [
        'status' => $invoice['status'],
        'date' => $invoice['updated_at'],
        'user' => $username,
        'description' => 'Status changed to ' . ucfirst($invoice['status'])
    ];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Invoice <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .invoice-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .status-received {
            background-color: #d1edff;
            color: #0c5460;
        }

        .timeline {
            position: relative;
            padding: 1rem 0;
        }

        .timeline-item {
            position: relative;
            padding-left: 2.5rem;
            margin-bottom: 1rem;
        }

        .timeline-item:before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0.25rem;
            width: 0.75rem;
            height: 0.75rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: 0.125rem solid white;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.1);
        }

        .timeline-item:after {
            content: '';
            position: absolute;
            left: 1rem;
            top: 2rem;
            width: 0.125rem;
            height: calc(100% - 1rem);
            background-color: #e9ecef;
        }

        .timeline-item:last-child:after {
            display: none;
        }

        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        .action-buttons .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .small {
            font-size: 0.875rem;
        }

        .form-label.small {
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h2>Invoice <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?></h2>
                    <p class="header-subtitle small">View invoice details and manage invoice actions</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-outline-secondary" onclick="viewInvoicePrint()">
                        <i class="bi bi-printer me-2"></i>Invoice Print
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="viewOrderPrint()">
                        <i class="bi bi-printer me-2"></i>Order Print
                    </button>
                    <a href="view_invoices.php" class="btn btn-outline-primary">
                        <i class="bi bi-list me-2"></i>All Invoices
                    </a>
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Invoice Header -->
            <div class="invoice-header">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1">Invoice <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?></h4>
                        <p class="mb-0 opacity-75 small">Received on <?php echo date('M j, Y g:i A', strtotime($invoice['received_date'] ?? $invoice['updated_at'])); ?></p>
                        <?php if ($invoice['supplier_invoice_number']): ?>
                        <p class="mb-0 opacity-75 small">Supplier Invoice: <?php echo htmlspecialchars($invoice['supplier_invoice_number']); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="status-badge status-received">
                            Invoice Received
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Invoice Details -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Invoice Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="row invoice-details-row">
                                <div class="col-md-6">
                                    <div class="mb-3 company-details">
                                        <label class="form-label fw-semibold small">Company Details</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($settings['company_name'] ?? 'Liza Point Of Sale'); ?></div>
                                        <?php if ($settings['company_address']): ?>
                                        <small class="text-muted d-block"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($settings['company_phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($settings['company_email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($settings['company_website']): ?>
                                        <small class="text-muted d-block">Website: <?php echo htmlspecialchars($settings['company_website']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Supplier Information</label>
                                        <div class="fw-bold"><?php echo htmlspecialchars($invoice['supplier_name'] ?? 'N/A'); ?></div>
                                        <?php if ($invoice['contact_person']): ?>
                                        <small class="text-muted d-block">Contact: <?php echo htmlspecialchars($invoice['contact_person']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($invoice['phone']): ?>
                                        <small class="text-muted d-block">Phone: <?php echo htmlspecialchars($invoice['phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($invoice['email']): ?>
                                        <small class="text-muted d-block">Email: <?php echo htmlspecialchars($invoice['email']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($invoice['address']): ?>
                                        <small class="text-muted d-block">Address: <?php echo nl2br(htmlspecialchars($invoice['address'])); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold small">Invoice Details</label>
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted">Invoice #:</small>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Order Date:</small>
                                                <div><?php echo date('M j, Y', strtotime($invoice['order_date'])); ?></div>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <small class="text-muted">Received Date:</small>
                                                <div><?php echo date('M j, Y', strtotime($invoice['received_date'] ?? $invoice['updated_at'])); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Created By:</small>
                                                <div><?php echo htmlspecialchars($invoice['created_by_name'] ?? 'System'); ?></div>
                                            </div>
                                        </div>
                                        <?php if ($invoice['supplier_invoice_number']): ?>
                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <small class="text-muted">Supplier Invoice:</small>
                                                <div><?php echo htmlspecialchars($invoice['supplier_invoice_number']); ?></div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Total Items:</small>
                                                <div><?php echo $invoice['total_received_items']; ?></div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <small class="text-muted">Total Items:</small>
                                                <div><?php echo $invoice['total_received_items']; ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>



                            <?php if ($invoice['invoice_notes'] || $invoice['notes']): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Invoice Notes</label>
                                <div><?php echo nl2br(htmlspecialchars($invoice['invoice_notes'] ?? $invoice['notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Invoice Items -->
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Invoice Items</h5>
                            <span class="badge bg-primary"><?php echo count($invoice['items']); ?> items</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th class="text-center">Received</th>
                                            <th class="text-end">Cost Price</th>
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoice['items'] as $item): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($item['image_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                         class="product-image me-3">
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                        <?php if ($item['category_name']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['category_name']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                                            <td class="text-center">
                                                <span class="fw-semibold"><?php echo $item['received_quantity']; ?></span>
                                            </td>
                                            <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                            <td class="text-end"><?php echo formatCurrency($item['received_quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Invoice Summary -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Invoice Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6">Items Received:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo $invoice['total_received_items']; ?></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">Total Amount:</div>
                                <div class="col-6 text-end fw-semibold"><?php echo formatCurrency($invoice['total_received_amount'], $settings['currency_symbol'] ?? 'KES'); ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-6 fw-semibold">Invoice Total:</div>
                                <div class="col-6 text-end fw-bold">
                                    <?php echo formatCurrency($invoice['total_received_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Actions</h5>
                        </div>
                        <div class="card-body">
                                                         <div class="action-buttons">
                                 <button type="button" class="btn btn-success" onclick="downloadInvoice()">
                                     <i class="bi bi-file-earmark-pdf me-2"></i>Download Invoice
                                 </button>
                                 <button type="button" class="btn btn-primary" onclick="printInvoice()">
                                     <i class="bi bi-printer me-2"></i>Print Invoice
                                 </button>
                                 <button type="button" class="btn btn-outline-secondary" onclick="viewInvoicePrint()">
                                     <i class="bi bi-eye me-2"></i>Invoice Print
                                 </button>
                                 <button type="button" class="btn btn-outline-secondary" onclick="viewOrderPrint()">
                                     <i class="bi bi-eye me-2"></i>Order Print
                                 </button>
                             </div>
                        </div>
                    </div>

                    <!-- Order Timeline -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Order Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($status_timeline as $timeline_item): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($timeline_item['description']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($timeline_item['user']); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j, g:i A', strtotime($timeline_item['date'])); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Print invoice functionality
        window.printInvoice = function() {
            // Open the invoice print page with auto-print enabled
            const printUrl = 'invoice_print.php?id=<?php echo urlencode($invoice_id); ?>&print=1';
            
            try {
                // Try to open in new window first
                const printWindow = window.open(printUrl, '_blank');
                if (!printWindow) {
                    // If popup blocked, redirect in same window
                    window.location.href = printUrl;
                }
            } catch (error) {
                console.error('Print error:', error);
                // Fallback: redirect to print page
                window.location.href = printUrl;
            }
        };

        // Download invoice functionality
        window.downloadInvoice = function() {
            const downloadUrl = 'generate_pdf.php?id=<?php echo urlencode($invoice_id); ?>&download=1&type=invoice';

            try {
                // Create a temporary link element and trigger download
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.style.display = 'none';
                link.target = '_blank'; // Open in new tab as fallback
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-info alert-dismissible fade show position-fixed';
                alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
                alertDiv.innerHTML = `
                    <i class="bi bi-info-circle me-2"></i>
                    Download started. If download doesn\'t start, check your popup blocker.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.body.appendChild(alertDiv);

                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (document.body.contains(alertDiv)) {
                        document.body.removeChild(alertDiv);
                    }
                }, 5000);

            } catch (error) {
                console.error('Download error:', error);
                // Fallback: redirect to download URL in same window
                window.location.href = downloadUrl;
            }
        };

        // View invoice print functionality
        window.viewInvoicePrint = function() {
            const printUrl = 'invoice_print.php?id=<?php echo urlencode($invoice_id); ?>';
            
            try {
                // Open in new window for viewing
                const printWindow = window.open(printUrl, '_blank');
                if (!printWindow) {
                    // If popup blocked, redirect in same window
                    window.location.href = printUrl;
                }
            } catch (error) {
                console.error('View invoice print error:', error);
                // Fallback: redirect to print page
                window.location.href = printUrl;
            }
        };

                // View order print functionality
        window.viewOrderPrint = function() {
            <?php if ($invoice['status'] !== 'received'): ?>
            // Show error message if order is not received
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-warning alert-dismissible fade show position-fixed';
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                <i class="bi bi-exclamation-triangle me-2"></i>
                Order print is only available for received orders.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (document.body.contains(alertDiv)) {
                    document.body.removeChild(alertDiv);
                }
            }, 5000);
            return;
            <?php endif; ?>

            const printUrl = 'view_order.php?id=<?php echo urlencode($invoice_id); ?>';

            try {
                // Open in new window for viewing
                const printWindow = window.open(printUrl, '_blank');
                if (!printWindow) {
                    // If popup blocked, redirect in same window
                    window.location.href = printUrl;
                }
            } catch (error) {
                console.error('View order print error:', error);
                // Fallback: redirect to print page
                window.location.href = printUrl;
            }
        };

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
