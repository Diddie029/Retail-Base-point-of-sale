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

// Get credit ID
$credit_id = $_GET['credit_id'] ?? 0;

if (!$credit_id) {
    header("Location: credits.php");
    exit();
}

// Get credit details
$sql = "
    SELECT 
        sc.*,
        s.name as supplier_name
    FROM supplier_credits sc
    LEFT JOIN suppliers s ON sc.supplier_id = s.id
    WHERE sc.id = :credit_id
";

$stmt = $conn->prepare($sql);
$stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
$stmt->execute();
$credit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$credit) {
    header("Location: credits.php");
    exit();
}

// Check if credit is available for application
if ($credit['status'] == 'fully_applied' || $credit['available_amount'] <= 0) {
    $_SESSION['error_message'] = "This credit is not available for application.";
    header("Location: view_credit.php?id=" . $credit_id);
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $applied_amount = floatval($_POST['applied_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Validate input
    $errors = [];
    
    if (!$invoice_id) {
        $errors[] = "Please select an invoice.";
    }
    
    if ($applied_amount <= 0) {
        $errors[] = "Applied amount must be greater than zero.";
    }
    
    if ($applied_amount > $credit['available_amount']) {
        $errors[] = "Applied amount cannot exceed available credit amount.";
    }
    
    // Get invoice details for validation
    if ($invoice_id) {
        $invoice_sql = "SELECT * FROM supplier_invoices WHERE id = :invoice_id AND supplier_id = :supplier_id";
        $invoice_stmt = $conn->prepare($invoice_sql);
        $invoice_stmt->bindValue(':invoice_id', $invoice_id, PDO::PARAM_INT);
        $invoice_stmt->bindValue(':supplier_id', $credit['supplier_id'], PDO::PARAM_INT);
        $invoice_stmt->execute();
        $invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invoice) {
            $errors[] = "Invalid invoice selected.";
        } else {
            // Check if invoice has enough balance for credit application
            if ($applied_amount > $invoice['balance_due']) {
                $errors[] = "Applied amount cannot exceed invoice balance due.";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Insert credit application
            $application_sql = "
                INSERT INTO credit_applications (credit_id, invoice_id, applied_amount, applied_date, applied_by, notes)
                VALUES (:credit_id, :invoice_id, :applied_amount, :applied_date, :applied_by, :notes)
            ";
            
            $application_stmt = $conn->prepare($application_sql);
            $application_stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
            $application_stmt->bindValue(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $application_stmt->bindValue(':applied_amount', $applied_amount, PDO::PARAM_STR);
            $application_stmt->bindValue(':applied_date', date('Y-m-d'), PDO::PARAM_STR);
            $application_stmt->bindValue(':applied_by', $user_id, PDO::PARAM_INT);
            $application_stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
            $application_stmt->execute();
            
            // Update credit applied amount
            $update_credit_sql = "
                UPDATE supplier_credits 
                SET applied_amount = applied_amount + :applied_amount,
                    status = CASE 
                        WHEN (applied_amount + :applied_amount) >= credit_amount THEN 'fully_applied'
                        WHEN (applied_amount + :applied_amount) > 0 THEN 'partially_applied'
                        ELSE 'available'
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :credit_id
            ";
            
            $update_credit_stmt = $conn->prepare($update_credit_sql);
            $update_credit_stmt->bindValue(':applied_amount', $applied_amount, PDO::PARAM_STR);
            $update_credit_stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
            $update_credit_stmt->execute();
            
            // Update invoice credit applied amount
            $update_invoice_sql = "
                UPDATE supplier_invoices 
                SET credit_applied = credit_applied + :applied_amount,
                    balance_due = balance_due - :applied_amount,
                    status = CASE 
                        WHEN (balance_due - :applied_amount) <= 0 THEN 'paid'
                        WHEN (balance_due - :applied_amount) < total_amount THEN 'partial'
                        ELSE status
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :invoice_id
            ";
            
            $update_invoice_stmt = $conn->prepare($update_invoice_sql);
            $update_invoice_stmt->bindValue(':applied_amount', $applied_amount, PDO::PARAM_STR);
            $update_invoice_stmt->bindValue(':invoice_id', $invoice_id, PDO::PARAM_INT);
            $update_invoice_stmt->execute();
            
            // Log transaction
            $log_sql = "
                INSERT INTO payable_transactions (transaction_type, reference_id, reference_table, old_value, new_value, description, user_id)
                VALUES ('credit_applied', :credit_id, 'supplier_credits', :old_applied, :new_applied, :description, :user_id)
            ";
            
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
            $log_stmt->bindValue(':old_applied', $credit['applied_amount'], PDO::PARAM_STR);
            $log_stmt->bindValue(':new_applied', $credit['applied_amount'] + $applied_amount, PDO::PARAM_STR);
            $log_stmt->bindValue(':description', "Applied credit of " . formatCurrency($applied_amount, $settings) . " to invoice #" . $invoice['invoice_number'], PDO::PARAM_STR);
            $log_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $log_stmt->execute();
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Credit successfully applied to invoice.";
            header("Location: view_credit.php?id=" . $credit_id);
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Error applying credit: " . $e->getMessage();
        }
    }
}

// Get credit applications for this credit
$applications = [];
try {
    $applications_sql = "
        SELECT 
            ca.*,
            si.invoice_number,
            si.invoice_date,
            u.username as applied_by_name
        FROM credit_applications ca
        LEFT JOIN supplier_invoices si ON ca.invoice_id = si.id
        LEFT JOIN users u ON ca.applied_by = u.id
        WHERE ca.credit_id = :credit_id
        ORDER BY ca.applied_date DESC
    ";

    $stmt = $conn->prepare($applications_sql);
    $stmt->bindValue(':credit_id', $credit_id, PDO::PARAM_INT);
    $stmt->execute();
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If credit_applications table doesn't exist or has issues, just show empty applications
    $applications = [];
}

// Get available invoices for this supplier
$invoices_sql = "
    SELECT 
        id,
        invoice_number,
        invoice_date,
        total_amount,
        balance_due,
        status
    FROM supplier_invoices 
    WHERE supplier_id = :supplier_id 
    AND balance_due > 0
    AND status IN ('pending', 'partial', 'overdue')
    ORDER BY invoice_date DESC, invoice_number
";

$invoices_stmt = $conn->prepare($invoices_sql);
$invoices_stmt->bindValue(':supplier_id', $credit['supplier_id'], PDO::PARAM_INT);
$invoices_stmt->execute();
$invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Apply Credit - " . $credit['credit_number'];
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
                        <a href="view_credit.php?id=<?php echo $credit_id; ?>" class="btn btn-outline-light btn-sm me-3">
                            <i class="bi bi-arrow-left me-1"></i>Back to Credit
                        </a>
                        <h1 class="mb-0"><i class="bi bi-check-circle me-2"></i>Apply Credit</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item"><a href="credits.php" style="color: rgba(255,255,255,0.8);">Credits</a></li>
                            <li class="breadcrumb-item"><a href="view_credit.php?id=<?php echo $credit_id; ?>" style="color: rgba(255,255,255,0.8);"><?php echo htmlspecialchars($credit['credit_number']); ?></a></li>
                            <li class="breadcrumb-item active" style="color: white;">Apply Credit</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="card-title mb-0">Apply Credit to Invoice</h4>
                            </div>

                            <!-- Credit Summary and History -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="card border-info">
                                        <div class="card-header bg-info text-white">
                                            <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Credit Information</h5>
                                        </div>
                                        <div class="card-body">
                                            <table class="table table-borderless table-sm">
                                                <tr>
                                                    <td class="fw-bold">Credit Number:</td>
                                                    <td><?php echo htmlspecialchars($credit['credit_number']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Supplier:</td>
                                                    <td><?php echo htmlspecialchars($credit['supplier_name']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Total Amount:</td>
                                                    <td class="fw-bold"><?php echo formatCurrency($credit['credit_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Applied Amount:</td>
                                                    <td class="text-warning"><?php echo formatCurrency($credit['applied_amount'], $settings); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-bold">Available Amount:</td>
                                                    <td class="fw-bold text-success"><?php echo formatCurrency($credit['available_amount'], $settings); ?></td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Credit Applied History -->
                                <div class="col-md-6">
                                    <div class="card border-success">
                                        <div class="card-header bg-success text-white">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Credit Applied History</h5>
                                                <span class="badge bg-light text-dark"><?php echo count($applications); ?> Application(s)</span>
                                            </div>
                                        </div>
                                        <div class="card-body p-0">
                                            <?php if (empty($applications)): ?>
                                                <div class="text-center text-muted py-4">
                                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                                    <p class="mt-2 mb-0">No applications yet</p>
                                                    <small>This credit has not been applied to any invoices.</small>
                                                </div>
                                            <?php else: ?>
                                                <div style="max-height: 300px; overflow-y: auto;">
                                                    <div class="list-group list-group-flush">
                                                        <?php foreach ($applications as $index => $application): ?>
                                                        <div class="list-group-item border-0">
                                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                                <div class="flex-grow-1">
                                                                    <div class="d-flex align-items-center mb-1">
                                                                        <span class="badge bg-primary me-2">#<?php echo $index + 1; ?></span>
                                                                        <h6 class="mb-0">
                                                                            <a href="../invoices/view_invoice.php?id=<?php echo $application['invoice_id']; ?>" 
                                                                               class="text-decoration-none text-info">
                                                                                <?php echo htmlspecialchars($application['invoice_number']); ?>
                                                                            </a>
                                                                        </h6>
                                                                    </div>
                                                                    <p class="mb-1 text-muted small">
                                                                        <i class="bi bi-calendar me-1"></i>
                                                                        <?php echo date('M d, Y g:i A', strtotime($application['applied_date'])); ?>
                                                                    </p>
                                                                    <p class="mb-1 text-muted small">
                                                                        <i class="bi bi-person me-1"></i>
                                                                        Applied by: <?php echo htmlspecialchars($application['applied_by_name']); ?>
                                                                    </p>
                                                                    <?php if (!empty($application['notes'])): ?>
                                                                    <p class="mb-0 text-muted small">
                                                                        <i class="bi bi-chat-text me-1"></i>
                                                                        <?php echo htmlspecialchars($application['notes']); ?>
                                                                    </p>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="text-end">
                                                                    <span class="badge bg-success fs-6">
                                                                        <?php echo formatCurrency($application['applied_amount'], $settings); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                
                                                <!-- Summary Notice -->
                                                <div class="border-top bg-light p-3">
                                                    <div class="row text-center">
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Total Applied</small>
                                                            <strong class="text-success"><?php echo formatCurrency($credit['applied_amount'], $settings); ?></strong>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block">Remaining</small>
                                                            <strong class="text-info"><?php echo formatCurrency($credit['available_amount'], $settings); ?></strong>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($credit['status'] == 'fully_applied'): ?>
                                                    <div class="alert alert-warning mt-2 mb-0 py-2">
                                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                                        <small><strong>Notice:</strong> This credit has been fully applied and cannot be used further.</small>
                                                    </div>
                                                    <?php elseif ($credit['status'] == 'partially_applied'): ?>
                                                    <div class="alert alert-info mt-2 mb-0 py-2">
                                                        <i class="bi bi-info-circle me-1"></i>
                                                        <small><strong>Notice:</strong> This credit is partially applied. You can apply the remaining amount to other invoices.</small>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Error Messages -->
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Please correct the following errors:</h6>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Application Form -->
                            <form method="POST" class="needs-validation" novalidate>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="selected_invoice" class="form-label">Selected Invoice <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="text" 
                                                       class="form-control" 
                                                       id="selected_invoice" 
                                                       name="selected_invoice" 
                                                       placeholder="Click to search for an invoice..." 
                                                       readonly 
                                                       required>
                                                <button type="button" 
                                                        class="btn btn-outline-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#invoiceSearchModal">
                                                    <i class="bi bi-search"></i> Search
                                                </button>
                                            </div>
                                            <input type="hidden" id="invoice_id" name="invoice_id" value="<?php echo htmlspecialchars($_POST['invoice_id'] ?? ''); ?>">
                                            <div class="invalid-feedback">
                                                Please select an invoice.
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="applied_amount" class="form-label">Amount to Apply <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? '$'; ?></span>
                                                <input type="number" 
                                                       class="form-control" 
                                                       id="applied_amount" 
                                                       name="applied_amount" 
                                                       step="0.01" 
                                                       min="0.01" 
                                                       max="<?php echo $credit['available_amount']; ?>"
                                                       value="<?php echo htmlspecialchars($_POST['applied_amount'] ?? ''); ?>"
                                                       required>
                                            </div>
                                            <div class="form-text">
                                                Maximum: <?php echo formatCurrency($credit['available_amount'], $settings); ?>
                                            </div>
                                            <div class="invalid-feedback">
                                                Please enter a valid amount.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="notes" class="form-label">Notes (Optional)</label>
                                            <textarea class="form-control" 
                                                      id="notes" 
                                                      name="notes" 
                                                      rows="3" 
                                                      placeholder="Add any notes about this credit application..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2">
                                    <a href="view_credit.php?id=<?php echo $credit_id; ?>" class="btn btn-secondary">
                                        <i class="bi bi-x-circle me-1"></i>Cancel
                                    </a>
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle me-1"></i>Apply Credit
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Invoice Search Modal -->
    <div class="modal fade" id="invoiceSearchModal" tabindex="-1" aria-labelledby="invoiceSearchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="invoiceSearchModalLabel">
                        <i class="bi bi-search me-2"></i>Search Invoices
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" 
                                       class="form-control" 
                                       id="invoiceSearchInput" 
                                       placeholder="Search by invoice number or notes...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-primary w-100" id="searchInvoicesBtn">
                                <i class="bi bi-search me-1"></i>Search
                            </button>
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-secondary w-100" id="showAllInvoicesBtn">
                                <i class="bi bi-list me-1"></i>Show All
                            </button>
                        </div>
                    </div>
                    
                    <div id="invoiceSearchResults">
                        <div class="text-center text-muted py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading recent invoices...</p>
                        </div>
                    </div>
                    
                    <div id="invoiceSearchPagination" class="d-none">
                        <nav aria-label="Invoice search pagination">
                            <ul class="pagination justify-content-center" id="invoicePaginationList">
                            </ul>
                        </nav>
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
        // Form validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();

        // Invoice search functionality
        let currentPage = 1;
        let currentSearch = '';
        let selectedInvoiceData = null;

        // Search invoices
        function searchInvoices(page = 1) {
            const searchTerm = document.getElementById('invoiceSearchInput').value.trim();
            currentSearch = searchTerm;
            currentPage = page;

            const params = new URLSearchParams({
                supplier_id: <?php echo $credit['supplier_id']; ?>,
                search: searchTerm,
                page: page
            });

            fetch(`search_invoices.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    displayInvoiceResults(data.invoices);
                    displayPagination(data.pagination);
                })
                .catch(error => {
                    console.error('Error searching invoices:', error);
                    document.getElementById('invoiceSearchResults').innerHTML = 
                        '<div class="alert alert-danger">Error searching invoices. Please try again.</div>';
                });
        }

        // Display invoice results
        function displayInvoiceResults(invoices) {
            const resultsDiv = document.getElementById('invoiceSearchResults');
            
            if (invoices.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mt-2">No invoices found</p>
                    </div>
                `;
                return;
            }

            let html = '<div class="list-group">';
            invoices.forEach(invoice => {
                const statusClass = invoice.status === 'overdue' ? 'danger' : 
                                  invoice.status === 'partial' ? 'warning' : 'secondary';
                const statusText = invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1);
                
                html += `
                    <div class="list-group-item list-group-item-action invoice-item" 
                         data-invoice-id="${invoice.id}" 
                         data-balance="${invoice.balance_due}"
                         data-invoice-number="${invoice.invoice_number}"
                         style="cursor: pointer;">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${invoice.invoice_number}</h6>
                            <small class="text-muted">${new Date(invoice.invoice_date).toLocaleDateString()}</small>
                        </div>
                        <div class="d-flex w-100 justify-content-between align-items-center">
                            <div>
                                <p class="mb-1">Balance Due: <strong>${formatCurrency(invoice.balance_due)}</strong></p>
                                <small class="text-muted">Due: ${new Date(invoice.due_date).toLocaleDateString()}</small>
                            </div>
                            <span class="badge bg-${statusClass}">${statusText}</span>
                        </div>
                        ${invoice.notes ? `<small class="text-muted">${invoice.notes}</small>` : ''}
                    </div>
                `;
            });
            html += '</div>';

            resultsDiv.innerHTML = html;

            // Add click handlers
            document.querySelectorAll('.invoice-item').forEach(item => {
                item.addEventListener('click', function() {
                    selectInvoice(this);
                });
            });
        }

        // Display pagination
        function displayPagination(pagination) {
            const paginationDiv = document.getElementById('invoiceSearchPagination');
            const paginationList = document.getElementById('invoicePaginationList');
            
            if (pagination.total_pages <= 1) {
                paginationDiv.classList.add('d-none');
                return;
            }

            paginationDiv.classList.remove('d-none');
            
            let html = '';
            
            // Previous button
            if (pagination.current_page > 1) {
                html += `<li class="page-item"><a class="page-link" href="#" onclick="searchInvoices(${pagination.current_page - 1})">Previous</a></li>`;
            }
            
            // Page numbers
            for (let i = Math.max(1, pagination.current_page - 2); i <= Math.min(pagination.total_pages, pagination.current_page + 2); i++) {
                const activeClass = i === pagination.current_page ? 'active' : '';
                html += `<li class="page-item ${activeClass}"><a class="page-link" href="#" onclick="searchInvoices(${i})">${i}</a></li>`;
            }
            
            // Next button
            if (pagination.current_page < pagination.total_pages) {
                html += `<li class="page-item"><a class="page-link" href="#" onclick="searchInvoices(${pagination.current_page + 1})">Next</a></li>`;
            }
            
            paginationList.innerHTML = html;
        }

        // Select invoice
        function selectInvoice(element) {
            const invoiceId = element.dataset.invoiceId;
            const balance = parseFloat(element.dataset.balance);
            const invoiceNumber = element.dataset.invoiceNumber;
            
            selectedInvoiceData = {
                id: invoiceId,
                balance: balance,
                invoiceNumber: invoiceNumber
            };

            // Update form fields
            document.getElementById('invoice_id').value = invoiceId;
            document.getElementById('selected_invoice').value = `${invoiceNumber} - ${formatCurrency(balance)}`;
            
            // Auto-fill amount
            const availableAmount = <?php echo $credit['available_amount']; ?>;
            const maxAmount = Math.min(balance, availableAmount);
            
            document.getElementById('applied_amount').max = maxAmount;
            document.getElementById('applied_amount').value = maxAmount.toFixed(2);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('invoiceSearchModal'));
            modal.hide();
        }

        // Format currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount);
        }

        // Event listeners
        document.getElementById('searchInvoicesBtn').addEventListener('click', function() {
            searchInvoices(1);
        });

        document.getElementById('showAllInvoicesBtn').addEventListener('click', function() {
            document.getElementById('invoiceSearchInput').value = '';
            searchInvoices(1);
        });

        document.getElementById('invoiceSearchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchInvoices(1);
            }
        });

        // Auto-load invoices when modal opens
        document.getElementById('invoiceSearchModal').addEventListener('shown.bs.modal', function() {
            // Clear search input and load recent invoices
            document.getElementById('invoiceSearchInput').value = '';
            searchInvoices(1);
        });

        // Validate amount against invoice balance
        document.getElementById('applied_amount').addEventListener('input', function() {
            if (selectedInvoiceData) {
                const appliedAmount = parseFloat(this.value);
                
                if (appliedAmount > selectedInvoiceData.balance) {
                    this.setCustomValidity(`Amount cannot exceed invoice balance of ${formatCurrency(selectedInvoiceData.balance)}`);
                } else {
                    this.setCustomValidity('');
                }
            }
        });

        // Load initial data if invoice is pre-selected
        <?php if (isset($_POST['invoice_id']) && $_POST['invoice_id']): ?>
        // Pre-populate if returning from form submission
        const preSelectedInvoice = <?php echo json_encode(array_filter($invoices, function($inv) { return $inv['id'] == $_POST['invoice_id']; })); ?>;
        if (preSelectedInvoice.length > 0) {
            const invoice = preSelectedInvoice[0];
            selectedInvoiceData = {
                id: invoice.id,
                balance: parseFloat(invoice.balance_due),
                invoiceNumber: invoice.invoice_number
            };
            document.getElementById('selected_invoice').value = `${invoice.invoice_number} - ${formatCurrency(invoice.balance_due)}`;
        }
        <?php endif; ?>
    </script>
</body>
</html>
