<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';
require_once __DIR__ . '/../../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
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

$message = '';
$message_type = '';

// Get invoice details if invoice_id is provided
$invoice = null;
$invoice_id = $_GET['invoice_id'] ?? '';
if ($invoice_id) {
    $stmt = $conn->prepare("
        SELECT 
            io.*,
            s.name as supplier_name,
            s.contact_person,
            s.phone,
            s.email,
            COALESCE(io.paid_amount, 0) as paid_amount,
            (io.total_amount - COALESCE(io.paid_amount, 0)) as balance_due,
            COALESCE(io.invoice_number, io.order_number) as display_number
        FROM inventory_orders io
        LEFT JOIN suppliers s ON io.supplier_id = s.id
        WHERE io.id = ? AND io.status = 'received'
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get suppliers for dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = sanitizeProductInput($_POST['supplier_id'] ?? '');
    $invoice_id = sanitizeProductInput($_POST['invoice_id'] ?? '');
    $payment_date = sanitizeProductInput($_POST['payment_date'] ?? '');
    $payment_method = sanitizeProductInput($_POST['payment_method'] ?? '');
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $reference_number = sanitizeProductInput($_POST['reference_number'] ?? '');
    $notes = sanitizeProductInput($_POST['notes'] ?? '', 'text');

    // Validation
    $errors = [];
    
    if (empty($supplier_id)) {
        $errors[] = 'Please select a supplier';
    }
    
    if (empty($invoice_id)) {
        $errors[] = 'Please select an invoice';
    }
    
    if (empty($payment_date)) {
        $errors[] = 'Please select a payment date';
    }
    
    if (empty($payment_method)) {
        $errors[] = 'Please select a payment method';
    }
    
    if ($payment_amount <= 0) {
        $errors[] = 'Payment amount must be greater than 0';
    }
    
    // Validate payment amount against invoice balance
    if ($invoice_id && $invoice) {
        $max_payment = $invoice['balance_due'];
        if ($payment_amount > $max_payment) {
            $errors[] = "Payment amount cannot exceed invoice balance of " . formatCurrency($max_payment, $settings);
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Generate payment number
            $payment_number = generatePaymentNumber($conn, $settings);
            
            // Insert payment record
            // Note: invoice_id is set to NULL because we're working with inventory_orders,
            // not supplier_invoices. The foreign key constraint expects supplier_invoices.id
            $stmt = $conn->prepare("
                INSERT INTO supplier_payments 
                (payment_number, supplier_id, invoice_id, payment_date, payment_method, 
                 payment_amount, reference_number, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $payment_number,
                $supplier_id,
                null, // Set to NULL since we're using inventory_orders, not supplier_invoices
                $payment_date,
                $payment_method,
                $payment_amount,
                $reference_number,
                $notes,
                $user_id
            ]);
            
            $payment_id = $conn->lastInsertId();
            
            // Update inventory order if specified
            // Note: We update inventory_orders.paid_amount, not supplier_invoices
            if ($invoice_id && $invoice) {
                $new_paid_amount = $invoice['paid_amount'] + $payment_amount;
                
                $stmt = $conn->prepare("
                    UPDATE inventory_orders 
                    SET paid_amount = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$new_paid_amount, $invoice_id]);
                
                // Log transaction
                logPayableTransaction($conn, 'payment_made', $payment_id, 'supplier_payments', 
                    $invoice['paid_amount'], $new_paid_amount, 
                    "Payment of " . formatCurrency($payment_amount, $settings) . " recorded", $user_id);
            }
            
            // Log payment transaction
            logPayableTransaction($conn, 'payment_made', $payment_id, 'supplier_payments', 
                0, $payment_amount, "Payment recorded", $user_id);
            
            $conn->commit();
            
            $message = 'Payment recorded successfully. Payment Number: ' . $payment_number;
            $message_type = 'success';
            
            // Clear form if not staying on same page
            if (!isset($_POST['add_another'])) {
                $invoice = null;
                $invoice_id = '';
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error recording payment: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

$page_title = "Record Payment";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="header-content">
                <div class="header-title">
                    <div class="d-flex align-items-center mb-2">
                        <a href="payments.php" class="btn btn-outline-light btn-sm me-3">
                            <i class="mdi mdi-arrow-left me-1"></i>Back to Payments
                        </a>
                        <h1 class="mb-0"><i class="mdi mdi-credit-card me-2"></i>Record Payment</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item"><a href="payments.php" style="color: rgba(255,255,255,0.8);">Payments</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Record Payment</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Record payments made to suppliers</p>
                </div>
            </div>
        </header>

        <main class="content">

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="paymentForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" 
                                                    <?php echo ($invoice && $invoice['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="invoice_search" class="form-label">Invoice <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="invoice_search" 
                                               placeholder="Search by invoice number..." readonly>
                                        <input type="hidden" id="invoice_id" name="invoice_id" required>
                                        <button class="btn btn-outline-secondary" type="button" id="search_invoice_btn">
                                            <i class="mdi mdi-magnify"></i> Search
                                        </button>
                                    </div>
                                    <small class="form-text text-muted">Click search to find and select an invoice</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="payment_date" name="payment_date" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                        <option value="">Select Method</option>
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="credit_card">Credit Card</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_amount" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="payment_amount" name="payment_amount" 
                                           step="0.01" min="0.01" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="reference_number" class="form-label">Reference Number</label>
                                    <input type="text" class="form-control" id="reference_number" name="reference_number" 
                                           placeholder="Check number, transaction ID, etc.">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Additional notes about this payment"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="mdi mdi-content-save"></i> Record Payment
                            </button>
                            <button type="submit" name="add_another" value="1" class="btn btn-success">
                                <i class="mdi mdi-plus"></i> Save & Add Another
                            </button>
                            <a href="payments.php" class="btn btn-secondary">
                                <i class="mdi mdi-arrow-left"></i> Back to Payments
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Invoice Details (if invoice selected) -->
        <div class="col-lg-4" id="invoiceDetails" style="display: none;">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Invoice Details</h5>
                    <div id="invoiceInfo">
                        <!-- Invoice details will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>

    <!-- Invoice Search Modal -->
    <div class="modal fade" id="invoiceSearchModal" tabindex="-1" aria-labelledby="invoiceSearchModalLabel">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="invoiceSearchModalLabel">
                        <i class="mdi mdi-magnify me-2"></i>Search and Select Invoice
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <input type="text" class="form-control" id="invoice_search_input" 
                                   placeholder="Search by invoice number, supplier name, or order number...">
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-primary w-100" id="search_invoices_btn">
                                <i class="mdi mdi-magnify me-1"></i>Search
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-hover">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th>Total Amount</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="invoice_search_results">
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="mdi mdi-credit-card display-6 mb-2"></i>
                                        <p class="mb-0">Loading unpaid invoices...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const supplierSelect = document.getElementById('supplier_id');
    const invoiceSearchInput = document.getElementById('invoice_search');
    const invoiceIdInput = document.getElementById('invoice_id');
    const searchInvoiceBtn = document.getElementById('search_invoice_btn');
    const invoiceSearchModal = new bootstrap.Modal(document.getElementById('invoiceSearchModal'));
    const invoiceSearchInputField = document.getElementById('invoice_search_input');
    const searchInvoicesBtn = document.getElementById('search_invoices_btn');
    const invoiceSearchResults = document.getElementById('invoice_search_results');
    const invoiceDetails = document.getElementById('invoiceDetails');
    const invoiceInfo = document.getElementById('invoiceInfo');
    const paymentAmount = document.getElementById('payment_amount');

    // Open invoice search modal
    searchInvoiceBtn.addEventListener('click', function() {
        const selectedSupplierId = supplierSelect.value;
        
        if (!selectedSupplierId) {
            alert('Please select a supplier first');
            return;
        }
        
        invoiceSearchModal.show();
        // Focus after modal is fully shown
        invoiceSearchModal._element.addEventListener('shown.bs.modal', function() {
            invoiceSearchInputField.focus();
            // Auto-load unpaid invoices for selected supplier when modal opens
            loadUnpaidInvoices(selectedSupplierId);
        }, { once: true });
    });

    // Search invoices
    searchInvoicesBtn.addEventListener('click', function() {
        searchInvoices();
    });

    // Search on Enter key
    invoiceSearchInputField.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchInvoices();
        }
    });

    // Function to load unpaid invoices automatically
    function loadUnpaidInvoices(supplierId = null) {
        // Show loading
        invoiceSearchResults.innerHTML = `
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Loading unpaid invoices${supplierId ? ' for selected supplier' : ''}...
                </td>
            </tr>
        `;

        const url = supplierId ? `get_unpaid_invoices.php?supplier_id=${supplierId}` : `get_unpaid_invoices.php`;
        
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Unpaid invoices:', data);
                
                if (!Array.isArray(data)) {
                    throw new Error('Invalid response format');
                }
                
                if (data.length === 0) {
                    invoiceSearchResults.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="mdi mdi-check-circle display-6 mb-2 text-success"></i>
                                <p class="mb-0">No unpaid invoices found${supplierId ? ' for this supplier' : ''}</p>
                            </td>
                        </tr>
                    `;
                } else {
                    displayInvoiceResults(data);
                }
            })
            .catch(error => {
                console.error('Error loading unpaid invoices:', error);
                invoiceSearchResults.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="mdi mdi-alert-circle display-6 mb-2"></i>
                            <p class="mb-0">Error loading invoices: ${error.message}</p>
                        </td>
                    </tr>
                `;
            });
    }

    // Function to search invoices
    function searchInvoices() {
        const searchTerm = invoiceSearchInputField.value.trim();
        const selectedSupplierId = supplierSelect.value;
        
        if (!searchTerm) {
            // If no search term, load unpaid invoices for selected supplier
            loadUnpaidInvoices(selectedSupplierId);
            return;
        }

        // Show loading
        invoiceSearchResults.innerHTML = `
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    Searching invoices...
                </td>
            </tr>
        `;

        const searchUrl = selectedSupplierId ? 
            `search_invoices.php?q=${encodeURIComponent(searchTerm)}&supplier_id=${selectedSupplierId}` :
            `search_invoices.php?q=${encodeURIComponent(searchTerm)}`;
            
        fetch(searchUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                
                if (!Array.isArray(data)) {
                    throw new Error('Invalid response format');
                }
                
                if (data.length === 0) {
                    invoiceSearchResults.innerHTML = `
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="mdi mdi-magnify display-6 mb-2"></i>
                                <p class="mb-0">No invoices found matching "${searchTerm}"</p>
                            </td>
                        </tr>
                    `;
                } else {
                    displayInvoiceResults(data);
                }
            })
            .catch(error => {
                console.error('Error searching invoices:', error);
                invoiceSearchResults.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="mdi mdi-alert-circle display-6 mb-2"></i>
                            <p class="mb-0">Error searching invoices: ${error.message}</p>
                        </td>
                    </tr>
                `;
            });
    }

    // Function to display invoice results
    function displayInvoiceResults(data) {
        invoiceSearchResults.innerHTML = data.map(invoice => `
            <tr>
                <td class="fw-semibold">${invoice.invoice_number || 'N/A'}</td>
                <td>${invoice.supplier_name || 'N/A'}</td>
                <td>${invoice.invoice_date || 'N/A'}</td>
                <td class="fw-bold">${parseFloat(invoice.total_amount || 0).toFixed(2)}</td>
                <td>${parseFloat(invoice.paid_amount || 0).toFixed(2)}</td>
                <td class="fw-bold text-danger">${parseFloat(invoice.balance_due || 0).toFixed(2)}</td>
                <td>
                    <span class="badge bg-${getStatusColor(invoice.status)}">${invoice.status || 'unknown'}</span>
                </td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="selectInvoice(${invoice.id}, '${(invoice.invoice_number || '').replace(/'/g, "\\'")}', ${invoice.balance_due || 0})">
                        <i class="mdi mdi-check me-1"></i>Select
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // Function to get status color
    function getStatusColor(status) {
        switch(status) {
            case 'paid': return 'success';
            case 'partial': return 'warning';
            case 'overdue': return 'danger';
            default: return 'secondary';
        }
    }

    // Function to select invoice (global scope)
    window.selectInvoice = function(id, invoiceNumber, balanceDue) {
        invoiceIdInput.value = id;
        invoiceSearchInput.value = invoiceNumber;
        invoiceSearchModal.hide();
        
        // Load invoice details
        loadInvoiceDetails(id);
    };

    // Function to load invoice details
    function loadInvoiceDetails(invoiceId) {
        fetch(`get_invoice_details.php?invoice_id=${invoiceId}`)
            .then(response => response.json())
            .then(data => {
                invoiceInfo.innerHTML = `
                    <p><strong>Invoice #:</strong> ${data.invoice_number}</p>
                    <p><strong>Date:</strong> ${data.invoice_date}</p>
                    <p><strong>Due Date:</strong> ${data.due_date}</p>
                    <p><strong>Total Amount:</strong> ${data.total_amount}</p>
                    <p><strong>Paid Amount:</strong> ${data.paid_amount}</p>
                    <p><strong>Balance Due:</strong> <span class="text-danger fw-bold">${data.balance_due}</span></p>
                    <p><strong>Status:</strong> <span class="badge bg-${data.status === 'paid' ? 'success' : (data.status === 'overdue' ? 'danger' : 'warning')}">${data.status}</span></p>
                `;
                invoiceDetails.style.display = 'block';
                
                // Set max payment amount and default value
                paymentAmount.max = data.balance_due;
                paymentAmount.value = data.balance_due;
            })
            .catch(error => console.error('Error loading invoice details:', error));
    }

    // Clear invoice selection
    invoiceSearchInput.addEventListener('click', function() {
        if (this.value) {
            this.value = '';
            invoiceIdInput.value = '';
            invoiceDetails.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
