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

// Get payment ID
$payment_id = (int)($_GET['id'] ?? 0);
if ($payment_id <= 0) {
    $_SESSION['error'] = 'Invalid payment ID.';
    header("Location: payments.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get existing payment data
$stmt = $conn->prepare("
    SELECT sp.*,
           s.name as supplier_name,
           io.invoice_number,
           COALESCE(io.total_amount, 0) as invoice_total,
           COALESCE(io.paid_amount, 0) as invoice_paid,
           (COALESCE(io.total_amount, 0) - COALESCE(io.paid_amount, 0)) as invoice_balance
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN inventory_orders io ON sp.invoice_id = io.id
    WHERE sp.id = :id
");
$stmt->bindParam(':id', $payment_id);
$stmt->execute();
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    $_SESSION['error'] = 'Payment not found.';
    header("Location: payments.php");
    exit();
}

// Check if payment can be edited (only pending payments)
if ($payment['status'] !== 'pending') {
    $_SESSION['error'] = 'Only pending payments can be edited.';
    header("Location: payments.php");
    exit();
}

$message = '';
$message_type = '';

// Get suppliers for dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unpaid invoices for the selected supplier
$unpaid_invoices = [];
if ($payment['supplier_id']) {
    $stmt = $conn->prepare("
        SELECT io.id, io.invoice_number, io.order_number,
               COALESCE(io.total_amount, 0) as total_amount,
               COALESCE(io.paid_amount, 0) as paid_amount,
               (COALESCE(io.total_amount, 0) - COALESCE(io.paid_amount, 0)) as balance_due,
               io.invoice_date
        FROM inventory_orders io
        WHERE io.supplier_id = ? AND io.status = 'received'
          AND (COALESCE(io.total_amount, 0) - COALESCE(io.paid_amount, 0)) > 0
        ORDER BY io.invoice_date DESC
    ");
    $stmt->execute([$payment['supplier_id']]);
    $unpaid_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

    if (empty($payment_date)) {
        $errors[] = 'Please select a payment date';
    }

    if (empty($payment_method)) {
        $errors[] = 'Please select a payment method';
    }

    if ($payment_amount <= 0) {
        $errors[] = 'Payment amount must be greater than 0';
    }

    // Validate payment amount against invoice balance if invoice is selected
    if ($invoice_id) {
        $stmt = $conn->prepare("
            SELECT (COALESCE(total_amount, 0) - COALESCE(paid_amount, 0)) as balance_due
            FROM inventory_orders
            WHERE id = ?
        ");
        $stmt->execute([$invoice_id]);
        $invoice_balance = $stmt->fetch(PDO::FETCH_ASSOC)['balance_due'] ?? 0;

        // Add back the current payment amount to calculate the new balance
        $available_balance = $invoice_balance + $payment['payment_amount'];

        if ($payment_amount > $available_balance) {
            $errors[] = "Payment amount cannot exceed invoice balance of " . formatCurrency($available_balance, $settings);
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Update payment record
            $stmt = $conn->prepare("
                UPDATE supplier_payments
                SET supplier_id = ?, invoice_id = ?, payment_date = ?,
                    payment_method = ?, payment_amount = ?, reference_number = ?,
                    notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $supplier_id,
                $invoice_id ?: null,
                $payment_date,
                $payment_method,
                $payment_amount,
                $reference_number,
                $notes,
                $payment_id
            ]);

            // If invoice changed, we need to adjust paid amounts
            if ($invoice_id != $payment['invoice_id']) {
                // Remove payment from old invoice
                if ($payment['invoice_id']) {
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET paid_amount = GREATEST(0, paid_amount - ?)
                        WHERE id = ?
                    ");
                    $stmt->execute([$payment['payment_amount'], $payment['invoice_id']]);
                }

                // Add payment to new invoice
                if ($invoice_id) {
                    $stmt = $conn->prepare("
                        UPDATE inventory_orders
                        SET paid_amount = paid_amount + ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$payment_amount, $invoice_id]);
                }
            } else if ($invoice_id && $payment_amount != $payment['payment_amount']) {
                // Same invoice, different amount - adjust the difference
                $amount_difference = $payment_amount - $payment['payment_amount'];
                $stmt = $conn->prepare("
                    UPDATE inventory_orders
                    SET paid_amount = paid_amount + ?
                    WHERE id = ?
                ");
                $stmt->execute([$amount_difference, $invoice_id]);
            }

            // Log transaction
            logPayableTransaction($conn, 'payment_updated', $payment_id, 'supplier_payments',
                $payment['payment_amount'], $payment_amount,
                "Payment updated: " . formatCurrency($payment_amount, $settings), $user_id);

            $conn->commit();

            $message = 'Payment updated successfully.';
            $message_type = 'success';

            // Refresh payment data
            $stmt = $conn->prepare("
                SELECT sp.*,
                       s.name as supplier_name,
                       io.invoice_number
                FROM supplier_payments sp
                LEFT JOIN suppliers s ON sp.supplier_id = s.id
                LEFT JOIN inventory_orders io ON sp.invoice_id = io.id
                WHERE sp.id = :id
            ");
            $stmt->bindParam(':id', $payment_id);
            $stmt->execute();
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error updating payment: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

$page_title = "Edit Payment";
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
                        <h1 class="mb-0"><i class="mdi mdi-pencil me-2"></i>Edit Payment</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item"><a href="payments.php" style="color: rgba(255,255,255,0.8);">Payments</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Edit Payment</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Modify payment details and information</p>
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
                                                    <?php echo $supplier['id'] == $payment['supplier_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="invoice_search" class="form-label">Invoice</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="invoice_search"
                                               value="<?php echo htmlspecialchars($payment['invoice_number'] ?? 'General Payment'); ?>" readonly>
                                        <input type="hidden" id="invoice_id" name="invoice_id"
                                               value="<?php echo $payment['invoice_id'] ?? ''; ?>">
                                        <button class="btn btn-outline-secondary" type="button" id="search_invoice_btn">
                                            <i class="mdi mdi-magnify"></i> Change
                                        </button>
                                    </div>
                                    <small class="form-text text-muted">Click change to select a different invoice</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="payment_date" name="payment_date"
                                           value="<?php echo $payment['payment_date']; ?>" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                        <option value="">Select Method</option>
                                        <option value="cash" <?php echo $payment['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                        <option value="check" <?php echo $payment['payment_method'] == 'check' ? 'selected' : ''; ?>>Check</option>
                                        <option value="bank_transfer" <?php echo $payment['payment_method'] == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="credit_card" <?php echo $payment['payment_method'] == 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                        <option value="other" <?php echo $payment['payment_method'] == 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_amount" class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? '$'; ?></span>
                                        <input type="number" class="form-control" id="payment_amount" name="payment_amount"
                                               step="0.01" min="0.01" value="<?php echo number_format($payment['payment_amount'], 2, '.', ''); ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="reference_number" class="form-label">Reference Number</label>
                                    <input type="text" class="form-control" id="reference_number" name="reference_number"
                                           value="<?php echo htmlspecialchars($payment['reference_number']); ?>"
                                           placeholder="Check number, transaction ID, etc.">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      placeholder="Additional notes about this payment"><?php echo htmlspecialchars($payment['notes']); ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="mdi mdi-content-save"></i> Update Payment
                            </button>
                            <a href="payments.php" class="btn btn-secondary">
                                <i class="mdi mdi-arrow-left"></i> Back to Payments
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Payment Info Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Payment Information</h5>
                    <div class="mb-3">
                        <strong>Payment Number:</strong><br>
                        <span class="text-primary"><?php echo htmlspecialchars($payment['payment_number']); ?></span>
                    </div>
                    <div class="mb-3">
                        <strong>Status:</strong><br>
                        <span class="badge bg-warning"><?php echo ucfirst($payment['status']); ?></span>
                    </div>
                    <div class="mb-3">
                        <strong>Created:</strong><br>
                        <?php echo date('M d, Y H:i', strtotime($payment['created_at'])); ?>
                    </div>
                    <div class="mb-3">
                        <strong>Last Updated:</strong><br>
                        <?php echo date('M d, Y H:i', strtotime($payment['updated_at'] ?? $payment['created_at'])); ?>
                    </div>

                    <?php if ($payment['invoice_id']): ?>
                    <hr>
                    <h6>Invoice Details</h6>
                    <div class="mb-2">
                        <strong>Invoice:</strong> <?php echo htmlspecialchars($payment['invoice_number']); ?>
                    </div>
                    <div class="mb-2">
                        <strong>Total:</strong> <?php echo formatCurrency($payment['invoice_total'], $settings); ?>
                    </div>
                    <div class="mb-2">
                        <strong>Previously Paid:</strong> <?php echo formatCurrency($payment['invoice_paid'] - $payment['payment_amount'], $settings); ?>
                    </div>
                    <div class="mb-2">
                        <strong>Current Balance:</strong> <?php echo formatCurrency($payment['invoice_balance'] + $payment['payment_amount'], $settings); ?>
                    </div>
                    <?php endif; ?>
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
                        <i class="mdi mdi-magnify me-2"></i>Select Invoice
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
                                    <th>Date</th>
                                    <th>Total Amount</th>
                                    <th>Paid Amount</th>
                                    <th>Balance</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="invoice_search_results">
                                <!-- Invoice results will be loaded here -->
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
            // Load unpaid invoices for selected supplier when modal opens
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

    // When supplier changes, clear invoice selection
    supplierSelect.addEventListener('change', function() {
        invoiceIdInput.value = '';
        invoiceSearchInput.value = '';
    });

    // Function to load unpaid invoices automatically
    function loadUnpaidInvoices(supplierId = null) {
        // Show loading
        invoiceSearchResults.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
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
                            <td colspan="6" class="text-center text-muted py-4">
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
                        <td colspan="6" class="text-center text-danger py-4">
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
                <td colspan="6" class="text-center text-muted py-4">
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
                            <td colspan="6" class="text-center text-muted py-4">
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
                        <td colspan="6" class="text-center text-danger py-4">
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
                <td class="fw-semibold">${invoice.invoice_number || invoice.order_number || 'N/A'}</td>
                <td>${invoice.invoice_date || 'N/A'}</td>
                <td class="fw-bold">${parseFloat(invoice.total_amount || 0).toFixed(2)}</td>
                <td>${parseFloat(invoice.paid_amount || 0).toFixed(2)}</td>
                <td class="fw-bold text-danger">${parseFloat(invoice.balance_due || 0).toFixed(2)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="selectInvoice(${invoice.id}, '${(invoice.invoice_number || invoice.order_number || '').replace(/'/g, "\\'")}', ${invoice.balance_due || 0})">
                        <i class="mdi mdi-check me-1"></i>Select
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // Function to select invoice (global scope)
    window.selectInvoice = function(id, invoiceNumber, balanceDue) {
        invoiceIdInput.value = id;
        invoiceSearchInput.value = invoiceNumber;
        invoiceSearchModal.hide();
    };

    // Clear invoice selection
    invoiceSearchInput.addEventListener('click', function() {
        if (this.value) {
            this.value = '';
            invoiceIdInput.value = '';
        }
    });
});
</script>
</body>
</html>
