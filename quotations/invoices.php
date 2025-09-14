<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters
$filters = [];
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['customer_name']) && !empty($_GET['customer_name'])) {
    $filters['customer_name'] = $_GET['customer_name'];
}
if (isset($_GET['invoice_number']) && !empty($_GET['invoice_number'])) {
    $filters['invoice_number'] = $_GET['invoice_number'];
}
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;

// Get invoices
$invoicesResult = getInvoices($conn, $filters, $page, $perPage);
$invoices = $invoicesResult['invoices'];
$total = $invoicesResult['total'];
$pages = $invoicesResult['pages'];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $invoice_id = (int)$_POST['invoice_id'];
    $status = $_POST['status'];

    try {
        $stmt = $conn->prepare("UPDATE invoices SET invoice_status = :status WHERE id = :invoice_id");
        $result = $stmt->execute([':status' => $status, ':invoice_id' => $invoice_id]);

        if ($result) {
            $_SESSION['success_message'] = "Invoice status updated successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to update invoice status.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    }

    header("Location: invoices.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        body {
            background-color: #f8fafc;
            font-family: 'Inter', sans-serif;
            padding-bottom: 4rem;
        }

        .main-content {
            padding-bottom: 4rem;
        }

        .container-fluid {
            margin-bottom: 3rem;
        }

        .invoices-header {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .invoices-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-sent {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .btn-outline-secondary {
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
        }

        .pagination {
            justify-content: center;
            margin-top: 2rem;
        }

        .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: 1px solid #e2e8f0;
        }

        .page-item.active .page-link {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .create-invoice-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, #8b5cf6 100%);
            color: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .create-invoice-section h5 {
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .create-invoice-section p {
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }

        .btn-create-invoice {
            background: white;
            color: var(--primary-color);
            border: none;
            border-radius: 8px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-create-invoice:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -8px rgba(255, 255, 255, 0.3);
        }

        .search-form {
            position: relative;
        }

        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            max-height: 400px;
            overflow-y: auto;
        }

        .search-result-item {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .search-result-item:hover {
            background: #f8fafc;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .sale-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sale-details h6 {
            margin: 0;
            font-weight: 600;
            color: #374151;
        }

        .sale-details p {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .sale-amount {
            text-align: right;
        }

        .sale-amount .amount {
            font-weight: 700;
            font-size: 1.1rem;
            color: #059669;
        }

        .sale-amount .date {
            font-size: 0.8rem;
            color: #6b7280;
        }

        .invoice-status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .invoice-status.has-invoice {
            background: #d1fae5;
            color: #065f46;
        }

        .invoice-status.no-invoice {
            background: #fef3c7;
            color: #92400e;
        }

        .search-loading {
            text-align: center;
            padding: 1rem;
            color: #6b7280;
        }

        .search-no-results {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .btn-generate-invoice {
            background: #f59e0b;
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-generate-invoice:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .btn-generate-invoice:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Create Invoice Section -->
        <div class="create-invoice-section">
            <h5><i class="bi bi-receipt"></i> Generate Invoice from Sale</h5>
            <p>Search for any completed sale by receipt number, transaction ID, or customer name</p>
            
            <!-- Search Form -->
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="search-form">
                        <div class="input-group">
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   id="saleSearchInput" 
                                   placeholder="Search by receipt number (#000001), transaction ID, or customer name..."
                                   autocomplete="off">
                            <button class="btn btn-light" type="button" id="searchSalesBtn">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="search-suggestions mt-2" id="searchSuggestions" style="display: none;">
                            <!-- Search results will appear here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-3">
                <a href="../pos/sale.php" class="btn btn-outline-light me-2">
                    <i class="bi bi-eye"></i> View All Sales
                </a>
                <a href="quotation.php?action=create" class="btn btn-outline-light">
                    <i class="bi bi-file-earmark-text"></i> Create Quotation
                </a>
            </div>
        </div>

        <!-- Header -->
        <div class="invoices-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-receipt"></i> Invoices</h1>
                    <p class="text-muted mb-0">Manage and track all invoices</p>
                </div>
                <div>
                    <span class="badge bg-primary fs-6 px-3 py-2">
                        <?php echo $total; ?> Total
                    </span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo ($filters['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo ($filters['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        <option value="paid" <?php echo ($filters['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="overdue" <?php echo ($filters['status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="customer_name" class="form-label">Customer Name</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name"
                           value="<?php echo htmlspecialchars($filters['customer_name'] ?? ''); ?>" placeholder="Search by customer">
                </div>
                <div class="col-md-2">
                    <label for="invoice_number" class="form-label">Invoice #</label>
                    <input type="text" class="form-control" id="invoice_number" name="invoice_number"
                           value="<?php echo htmlspecialchars($filters['invoice_number'] ?? ''); ?>" placeholder="Search by number">
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                           value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to"
                           value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="invoices.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Invoices Table -->
        <div class="invoices-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Total</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                                    <div class="mt-3">
                                        <h5>No Invoices Found</h5>
                                        <p class="text-muted">Try adjusting your filters or create an invoice from a sale.</p>
                                        <a href="../pos/sale.php" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> View Sales
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
                                        <?php if ($invoice['sale_id']): ?>
                                            <br><small class="text-muted">From Sale #<?php echo $invoice['sale_id']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($invoice['invoice_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $invoice['invoice_status']; ?>">
                                            <?php echo ucfirst($invoice['invoice_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($invoice['due_date'])); ?></td>
                                    <td>
                                        <strong>KES <?php echo number_format($invoice['final_amount'], 2); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['created_by_name']); ?></td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="invoice.php?invoice_id=<?php echo $invoice['id']; ?>"
                                               class="btn btn-sm btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown" title="More Actions">
                                                    <i class="bi bi-three-dots"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                            <input type="hidden" name="status" value="sent">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="bi bi-send"></i> Mark as Sent
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                            <input type="hidden" name="status" value="paid">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="bi bi-check-circle"></i> Mark as Paid
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <nav aria-label="Invoices pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($filters) ? '&' . http_build_query($filters) : ''; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>

        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);

            // Add loading animation for filter submissions
            document.addEventListener('submit', function(e) {
                if (e.target.matches('form[method="GET"]')) {
                    const submitBtn = e.target.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="bi bi-arrow-clockwise bi-spin"></i>';
                        submitBtn.disabled = true;
                    }
                }
            });

            // Initialize search functionality
            initializeSearch();
        });

        // Search functionality
        let searchTimeout;
        const searchInput = document.getElementById('saleSearchInput');
        const searchBtn = document.getElementById('searchSalesBtn');
        const searchSuggestions = document.getElementById('searchSuggestions');

        function initializeSearch() {
            // Search on input with debounce
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        searchSales(query);
                    }, 300);
                } else {
                    hideSuggestions();
                }
            });

            // Search on button click
            searchBtn.addEventListener('click', function() {
                const query = searchInput.value.trim();
                if (query.length >= 2) {
                    searchSales(query);
                }
            });

            // Search on Enter key
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const query = this.value.trim();
                    if (query.length >= 2) {
                        searchSales(query);
                    }
                }
            });

            // Hide suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-form')) {
                    hideSuggestions();
                }
            });
        }

        function searchSales(query) {
            showLoading();
            
            fetch(`../api/search_sales.php?q=${encodeURIComponent(query)}&limit=10`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySearchResults(data.sales);
                    } else {
                        showError(data.error || 'Search failed');
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    showError('Search failed. Please try again.');
                });
        }

        function showLoading() {
            searchSuggestions.innerHTML = `
                <div class="search-loading">
                    <i class="bi bi-arrow-clockwise bi-spin"></i> Searching sales...
                </div>
            `;
            searchSuggestions.style.display = 'block';
        }

        function displaySearchResults(sales) {
            if (sales.length === 0) {
                searchSuggestions.innerHTML = `
                    <div class="search-no-results">
                        <i class="bi bi-search"></i>
                        <div class="mt-2">No sales found matching your search</div>
                    </div>
                `;
            } else {
                const resultsHtml = sales.map(sale => {
                    const saleDate = new Date(sale.sale_date).toLocaleDateString();
                    const saleTime = new Date(sale.created_at).toLocaleTimeString();
                    const customerName = sale.customer_name || 'Walk-in Customer';
                    const hasInvoice = sale.has_invoice;
                    
                    return `
                        <div class="search-result-item" data-sale-id="${sale.id}">
                            <div class="sale-info">
                                <div class="sale-details">
                                    <h6>
                                        Receipt #${sale.receipt_number}
                                        ${hasInvoice ? `<span class="invoice-status has-invoice ms-2">Invoice: ${sale.invoice_number}</span>` : '<span class="invoice-status no-invoice ms-2">No Invoice</span>'}
                                    </h6>
                                    <p>
                                        <strong>${customerName}</strong>
                                        ${sale.customer_phone ? ` • ${sale.customer_phone}` : ''}
                                        <br>
                                        <small>${saleDate} at ${saleTime} • ${sale.payment_method} • Cashier: ${sale.cashier_name}</small>
                                    </p>
                                </div>
                                <div class="sale-amount">
                                    <div class="amount">KES ${parseFloat(sale.final_amount).toFixed(2)}</div>
                                    <div class="date">${saleDate}</div>
                                </div>
                            </div>
                            <div class="mt-2 text-end">
                                ${hasInvoice ? 
                                    `<button class="btn btn-sm btn-outline-primary" onclick="viewInvoice('${sale.invoice_number}')">
                                        <i class="bi bi-eye"></i> View Invoice
                                    </button>` :
                                    `<button class="btn btn-sm btn-generate-invoice" onclick="generateInvoice(${sale.id})">
                                        <i class="bi bi-receipt"></i> Generate Invoice
                                    </button>`
                                }
                            </div>
                        </div>
                    `;
                }).join('');
                
                searchSuggestions.innerHTML = resultsHtml;
            }
            
            searchSuggestions.style.display = 'block';
        }

        function showError(message) {
            searchSuggestions.innerHTML = `
                <div class="search-no-results">
                    <i class="bi bi-exclamation-triangle text-danger"></i>
                    <div class="mt-2">${message}</div>
                </div>
            `;
            searchSuggestions.style.display = 'block';
        }

        function hideSuggestions() {
            searchSuggestions.style.display = 'none';
        }

        function generateInvoice(saleId) {
            // Show loading state
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-arrow-clockwise bi-spin"></i> Generating...';
            button.disabled = true;
            
            // Redirect to invoice creation
            window.location.href = `invoice.php?action=create_from_sale&sale_id=${saleId}`;
        }

        function viewInvoice(invoiceNumber) {
            // Find the invoice ID and redirect to view
            window.location.href = `invoice.php?invoice_number=${invoiceNumber}`;
        }

        // Add spin animation for loading buttons
        const style = document.createElement('style');
        style.textContent = `
            .bi-spin {
                animation: spin 1s linear infinite;
            }

            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
