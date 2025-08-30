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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build WHERE clause for invoices (received orders)
$where = [];
$params = [];

// Always filter for received orders (invoices)
$where[] = "io.status = 'received'";

if (!empty($search)) {
    $where[] = "(io.order_number LIKE :search OR io.invoice_number LIKE :search OR s.name LIKE :search OR u.username LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "io.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($date_from)) {
    $where[] = "io.received_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "io.received_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE io.status = \'received\'';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_invoices = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_invoices / $per_page);

// Get invoices with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT io.*,
           s.name as supplier_name, s.contact_person,
           u.username as created_by_name,
           COALESCE(SUM(ioi.received_quantity), 0) as total_received_items,
           COUNT(ioi.id) as total_items_count,
           COALESCE(SUM(ioi.received_quantity * ioi.cost_price), 0) as total_amount
    FROM inventory_orders io
    LEFT JOIN suppliers s ON io.supplier_id = s.id
    LEFT JOIN users u ON io.user_id = u.id
    LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id AND ioi.received_quantity > 0
    $where_clause
    GROUP BY io.id, s.name, s.contact_person, u.username
    ORDER BY io.received_date DESC, io.updated_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoice statistics
$stats = [];
$stmt = $conn->query("
    SELECT COUNT(*) as count,
           COALESCE(SUM(total_amount), 0) as total_amount
    FROM (
        SELECT io.id, COALESCE(SUM(ioi.received_quantity * ioi.cost_price), 0) as total_amount
        FROM inventory_orders io
        LEFT JOIN inventory_order_items ioi ON io.id = ioi.order_id AND ioi.received_quantity > 0
        WHERE io.status = 'received'
        GROUP BY io.id
    ) as invoice_totals
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['total'] = $result;

// Handle bulk actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $invoice_ids = $_POST['invoice_ids'] ?? [];

    if (!empty($invoice_ids)) {
        try {
            $conn->beginTransaction();

            $placeholders = str_repeat('?,', count($invoice_ids) - 1) . '?';

            switch ($action) {
                case 'print_invoices':
                    // Validate that selected invoices are received
                    $stmt = $conn->prepare("
                        SELECT COUNT(*) as count FROM inventory_orders
                        WHERE id IN ($placeholders) AND status = 'received'
                    ");
                    $stmt->execute($invoice_ids);
                    $received_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

                    if ($received_count > 0) {
                        if ($received_count === count($invoice_ids)) {
                            // All selected invoices are received - redirect to bulk print
                            $invoice_ids_string = implode(',', $invoice_ids);
                            header("Location: print_bulk_invoices.php?ids=" . urlencode($invoice_ids_string));
                            exit();
                        } else {
                            $message = "Only $received_count out of " . count($invoice_ids) . " selected invoices can be printed";
                            $message_type = 'warning';
                        }
                    } else {
                        $message = "No valid invoices selected for printing";
                        $message_type = 'warning';
                    }
                    break;
            }

            // Log activity
            logActivity($conn, $user_id, 'bulk_invoice_action', "Bulk action: $action on " . count($invoice_ids) . " invoices");

            $conn->commit();
            $message_type = 'success';

        } catch (PDOException $e) {
            $conn->rollBack();
            $message = "Error performing bulk action: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Invoices - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-received {
            background-color: #d1edff;
            color: #0c5460;
        }

        .filters-section {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.05);
        }

        .bulk-actions {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .invoice-number {
            font-weight: 600;
            color: var(--primary-color);
        }

        .invoice-number:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .filters-section .row > div {
                margin-bottom: 1rem;
            }
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
                    <h1>Purchase Invoices</h1>
                    <p class="header-subtitle">
                        View and manage all received purchase invoices
                    </p>
                </div>
                <div class="header-actions">
                    <a href="view_orders.php" class="btn btn-outline-secondary">
                        <i class="bi bi-list me-2"></i>All Orders
                    </a>
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Purchase Invoices:</strong> This page shows all received orders that have been converted to invoices. Use the print buttons to generate invoice PDFs or select multiple invoices for bulk printing.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo $stats['total']['count'] ?? 0; ?></h3>
                                <small>Total Invoices</small>
                            </div>
                            <i class="bi bi-receipt fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($stats['total']['total_amount'] ?? 0, 2); ?></h3>
                                <small>Total Value</small>
                            </div>
                            <i class="bi bi-cash fs-1 opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search Invoices</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Order #, Invoice #, Supplier, User...">
                    </div>
                    <div class="col-md-3">
                        <label for="supplier" class="form-label">Supplier</label>
                        <select class="form-select" id="supplier" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>"
                                    <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">Received From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from"
                               value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">Received To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to"
                               value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-2"></i>Search
                        </button>
                        <a href="view_invoices.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="bulk-actions">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label fw-semibold" for="selectAll">
                                    Select All Invoices
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <select name="bulk_action" class="form-select d-inline-block w-auto me-2" required>
                                <option value="">Choose Action</option>
                                <option value="print_invoices">Print Invoices</option>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Apply
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="40">
                                    <input class="form-check-input" type="checkbox" id="selectAllHeader">
                                </th>
                                <th>Invoice/Order Number</th>
                                <th>Supplier</th>
                                <th>Received Date</th>
                                <th>Status</th>
                                <th class="text-end">Items</th>
                                <th class="text-end">Total Amount</th>
                                <th>Created By</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="bi bi-receipt-x fs-1 text-muted"></i>
                                    <p class="mt-2 text-muted">No invoices found matching your criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <input class="form-check-input invoice-checkbox" type="checkbox"
                                               name="invoice_ids[]" value="<?php echo $invoice['id']; ?>">
                                    </td>
                                    <td>
                                        <div>
                                            <a href="view_invoice.php?id=<?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?>"
                                               class="invoice-number">
                                                <?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?>
                                            </a>
                                            <?php if ($invoice['supplier_invoice_number']): ?>
                                            <br><small class="text-muted">Supplier: <?php echo htmlspecialchars($invoice['supplier_invoice_number']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($invoice['received_date'] ?? $invoice['updated_at'])); ?></td>
                                    <td>
                                        <span class="status-badge status-received">
                                            Invoice
                                        </span>
                                    </td>
                                    <td class="text-end"><?php echo $invoice['total_received_items']; ?></td>
                                    <td class="text-end">
                                        <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>
                                        <?php echo number_format($invoice['total_amount'], 2); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['created_by_name'] ?? 'System'); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_invoice.php?id=<?php echo htmlspecialchars($invoice['invoice_number'] ?? $invoice['order_number']); ?>"
                                               class="btn btn-sm btn-outline-primary" title="View Invoice">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="generate_pdf.php?id=<?php echo urlencode($invoice['order_number']); ?>&download=1"
                                               class="btn btn-sm btn-success" title="Download Invoice" target="_blank">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-primary" title="Print Invoice"
                                                    onclick="printInvoice('<?php echo urlencode($invoice['order_number']); ?>')">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <a href="invoice_print.php?id=<?php echo urlencode($invoice['order_number']); ?>"
                                               class="btn btn-sm btn-info" title="Invoice Print" target="_blank">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                            <a href="order_print_preview.php?id=<?php echo urlencode($invoice['order_number']); ?>"
                                               class="btn btn-sm btn-outline-secondary" title="Order Print" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Invoices pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>

                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                <?php echo $total_pages; ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        document.getElementById('selectAllHeader').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
            document.getElementById('selectAll').checked = this.checked;
        });

        // Print invoice functionality
        window.printInvoice = function(orderId) {
            // Open invoice print layout in new window for printing
            const printWindow = window.open('invoice_print.php?id=' + encodeURIComponent(orderId) + '&print=1', '_blank');
            if (printWindow) {
                // Wait for page to load then trigger print
                printWindow.onload = function() {
                    setTimeout(function() {
                        printWindow.print();
                    }, 1000);
                };
            } else {
                alert('Please allow popups for this website to print invoices.');
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
