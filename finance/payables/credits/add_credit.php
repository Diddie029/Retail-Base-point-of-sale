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

// Get suppliers for dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = sanitizeProductInput($_POST['supplier_id'] ?? '');
    $credit_type = sanitizeProductInput($_POST['credit_type'] ?? '');
    $credit_date = sanitizeProductInput($_POST['credit_date'] ?? '');
    $credit_amount = floatval($_POST['credit_amount'] ?? 0);
    $reason = sanitizeProductInput($_POST['reason'] ?? '', 'text');
    $reference_invoice = sanitizeProductInput($_POST['reference_invoice'] ?? '');
    $expiry_date = sanitizeProductInput($_POST['expiry_date'] ?? '');

    // Validation
    $errors = [];
    
    if (empty($supplier_id)) {
        $errors[] = 'Please select a supplier';
    }
    
    if (empty($credit_type)) {
        $errors[] = 'Please select a credit type';
    }
    
    if (empty($credit_date)) {
        $errors[] = 'Please select a credit date';
    }
    
    if ($credit_amount <= 0) {
        $errors[] = 'Credit amount must be greater than 0';
    }
    
    if (empty($reason)) {
        $errors[] = 'Please provide a reason for the credit';
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Generate credit number
            $credit_number = generateCreditNumber($conn, $settings);
            
            // Insert credit record
            $stmt = $conn->prepare("
                INSERT INTO supplier_credits 
                (credit_number, supplier_id, credit_type, credit_date, credit_amount, 
                 reason, reference_invoice, expiry_date, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $credit_number,
                $supplier_id,
                $credit_type,
                $credit_date,
                $credit_amount,
                $reason,
                $reference_invoice,
                $expiry_date ?: null,
                $user_id
            ]);
            
            $credit_id = $conn->lastInsertId();
            
            // Log transaction
            logPayableTransaction($conn, 'credit_applied', $credit_id, 'supplier_credits', 
                0, $credit_amount, "Credit of " . formatCurrency($credit_amount, $settings) . " created", $user_id);
            
            $conn->commit();
            
            $message = 'Credit recorded successfully. Credit Number: ' . $credit_number;
            $message_type = 'success';
            
            // Clear form if not staying on same page
            if (!isset($_POST['add_another'])) {
                $_POST = []; // Clear form data
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error recording credit: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}

$page_title = "Add Supplier Credit";
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
                        <a href="credits.php" class="btn btn-outline-light btn-sm me-3">
                            <i class="mdi mdi-arrow-left me-1"></i>Back to Credits
                        </a>
                        <h1 class="mb-0"><i class="mdi mdi-account-plus me-2"></i>Add Supplier Credit</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item"><a href="credits.php" style="color: rgba(255,255,255,0.8);">Credits</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Add Credit</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Record credits received from suppliers</p>
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
                    <form method="POST" id="creditForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" 
                                                    <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="credit_type" class="form-label">Credit Type <span class="text-danger">*</span></label>
                                    <select class="form-select" id="credit_type" name="credit_type" required>
                                        <option value="">Select Type</option>
                                        <option value="return" <?php echo (isset($_POST['credit_type']) && $_POST['credit_type'] == 'return') ? 'selected' : ''; ?>>Return</option>
                                        <option value="discount" <?php echo (isset($_POST['credit_type']) && $_POST['credit_type'] == 'discount') ? 'selected' : ''; ?>>Discount</option>
                                        <option value="overpayment" <?php echo (isset($_POST['credit_type']) && $_POST['credit_type'] == 'overpayment') ? 'selected' : ''; ?>>Overpayment</option>
                                        <option value="adjustment" <?php echo (isset($_POST['credit_type']) && $_POST['credit_type'] == 'adjustment') ? 'selected' : ''; ?>>Adjustment</option>
                                        <option value="other" <?php echo (isset($_POST['credit_type']) && $_POST['credit_type'] == 'other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Return Selection (only for return type) -->
                        <div class="row" id="return_selection_row" style="display: none;">
                            <div class="col-12">
                                <div class="mb-3">
                                    <label for="return_search" class="form-label">Select Return <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="return_search" placeholder="Search approved returns..." readonly>
                                        <button class="btn btn-outline-secondary" type="button" id="searchReturnBtn">
                                            <i class="mdi mdi-magnify"></i> Search
                                        </button>
                                    </div>
                                    <input type="hidden" id="return_id" name="return_id">
                                    <small class="form-text text-muted">Select a return to automatically populate credit details</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="credit_date" class="form-label">Credit Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="credit_date" name="credit_date" 
                                           value="<?php echo isset($_POST['credit_date']) ? $_POST['credit_date'] : date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="credit_amount" class="form-label">Credit Amount <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="credit_amount" name="credit_amount" 
                                           step="0.01" min="0.01" 
                                           value="<?php echo isset($_POST['credit_amount']) ? $_POST['credit_amount'] : ''; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="reference_invoice" class="form-label">Reference Invoice</label>
                                    <input type="text" class="form-control" id="reference_invoice" name="reference_invoice" 
                                           value="<?php echo isset($_POST['reference_invoice']) ? htmlspecialchars($_POST['reference_invoice']) : ''; ?>" 
                                           placeholder="Original invoice number if applicable">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date (Optional)</label>
                                    <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                                           value="<?php echo isset($_POST['expiry_date']) ? $_POST['expiry_date'] : ''; ?>">
                                    <small class="form-text text-muted">Leave blank for no expiry</small>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" 
                                      placeholder="Detailed reason for this credit" required><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="mdi mdi-content-save"></i> Record Credit
                            </button>
                            <button type="submit" name="add_another" value="1" class="btn btn-success">
                                <i class="mdi mdi-plus"></i> Save & Add Another
                            </button>
                            <a href="credits.php" class="btn btn-secondary">
                                <i class="mdi mdi-arrow-left"></i> Back to Credits
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Credit Information -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Credit Information</h5>
                    <div class="mb-3">
                        <h6>Credit Types:</h6>
                        <ul class="list-unstyled">
                            <li><strong>Return:</strong> Credit for returned goods</li>
                            <li><strong>Discount:</strong> Price adjustment or discount</li>
                            <li><strong>Overpayment:</strong> Credit for overpaid amount</li>
                            <li><strong>Adjustment:</strong> General account adjustment</li>
                            <li><strong>Other:</strong> Any other type of credit</li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <h6>Usage:</h6>
                        <p class="text-muted">Credits can be applied to future invoices to reduce the amount owed to suppliers.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>

    <!-- Return Search Modal -->
    <div class="modal fade" id="returnSearchModal" tabindex="-1" aria-labelledby="returnSearchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="returnSearchModalLabel">Select Approved Return</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="returnSearchInput" placeholder="Search by return number, supplier, or created by... (Leave empty to see all returns)">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Items</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="returnSearchResults">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Loading all approved returns...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const creditTypeSelect = document.getElementById('credit_type');
    const reasonTextarea = document.getElementById('reason');
    const returnSelectionRow = document.getElementById('return_selection_row');
    const searchReturnBtn = document.getElementById('searchReturnBtn');
    const returnSearchModal = new bootstrap.Modal(document.getElementById('returnSearchModal'));
    const returnSearchInput = document.getElementById('returnSearchInput');
    const returnSearchResults = document.getElementById('returnSearchResults');
    const returnSearchField = document.getElementById('return_search');
    const returnIdField = document.getElementById('return_id');
    const supplierSelect = document.getElementById('supplier_id');
    
    // Show/hide return selection based on credit type
    creditTypeSelect.addEventListener('change', function() {
        const selectedType = this.value;
        if (selectedType === 'return') {
            returnSelectionRow.style.display = 'block';
            returnIdField.required = true;
        } else {
            returnSelectionRow.style.display = 'none';
            returnIdField.required = false;
            returnIdField.value = '';
            returnSearchField.value = '';
        }
        
        // Auto-populate reason based on credit type
        const currentReason = reasonTextarea.value;
        if (!currentReason.trim()) {
            const reasonTemplates = {
                'return': 'Credit for returned goods - ',
                'discount': 'Price discount/adjustment - ',
                'overpayment': 'Credit for overpayment - ',
                'adjustment': 'Account adjustment - ',
                'other': 'Credit note - '
            };
            
            if (reasonTemplates[selectedType]) {
                reasonTextarea.value = reasonTemplates[selectedType];
                reasonTextarea.focus();
            }
        }
    });
    
    // Open return search modal
    searchReturnBtn.addEventListener('click', function() {
        const selectedSupplierId = supplierSelect.value;
        
        if (!selectedSupplierId) {
            alert('Please select a supplier first');
            return;
        }
        
        returnSearchModal.show();
        // Auto-load all approved returns when modal opens
        loadApprovedReturns(selectedSupplierId);
    });
    
    // Function to load approved returns automatically
    function loadApprovedReturns(supplierId = null) {
        returnSearchResults.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading all approved returns...</td></tr>';
        
        const url = supplierId ? `get_approved_returns.php?supplier_id=${supplierId}` : `get_approved_returns.php`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                displayReturnResults(data);
            })
            .catch(error => {
                console.error('Error loading returns:', error);
                returnSearchResults.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading returns</td></tr>';
            });
    }
    
    // Function to search returns
    function searchReturns() {
        const searchTerm = returnSearchInput.value.trim();
        const selectedSupplierId = supplierSelect.value;
        
        if (!searchTerm) {
            // If no search term, load all approved returns for selected supplier
            loadApprovedReturns(selectedSupplierId);
            return;
        }
        
        returnSearchResults.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Searching...</td></tr>';
        
        const searchUrl = selectedSupplierId ? 
            `search_returns.php?q=${encodeURIComponent(searchTerm)}&supplier_id=${selectedSupplierId}` :
            `search_returns.php?q=${encodeURIComponent(searchTerm)}`;
        
        fetch(searchUrl)
            .then(response => response.json())
            .then(data => {
                displayReturnResults(data);
            })
            .catch(error => {
                console.error('Error searching returns:', error);
                returnSearchResults.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error searching returns</td></tr>';
            });
    }
    
    // Function to display return results
    function displayReturnResults(returns) {
        if (returns.length === 0) {
            returnSearchResults.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No approved returns found</td></tr>';
            return;
        }
        
        let html = '';
        returns.forEach(return_item => {
            html += `
                <tr>
                    <td><strong>${return_item.return_number}</strong></td>
                    <td>${return_item.supplier_name}</td>
                    <td>${new Date(return_item.return_date).toLocaleDateString()}</td>
                    <td>${formatCurrency(return_item.total_amount)}</td>
                    <td><span class="badge bg-info">${return_item.total_items} items</span></td>
                    <td>
                        <button class="btn btn-primary btn-sm" onclick="selectReturn(${return_item.id}, '${return_item.return_number}', ${return_item.total_amount})">
                            Select
                        </button>
                    </td>
                </tr>
            `;
        });
        returnSearchResults.innerHTML = html;
    }
    
    // Search input event listener
    returnSearchInput.addEventListener('input', function() {
        clearTimeout(this.searchTimeout);
        
        if (this.value.trim() === '') {
            // If input is empty, show all returns immediately
            const selectedSupplierId = supplierSelect.value;
            loadApprovedReturns(selectedSupplierId);
        } else {
            // If there's a search term, search after delay
            this.searchTimeout = setTimeout(() => {
                searchReturns();
            }, 300);
        }
    });
    
    // Global function to select a return
    window.selectReturn = function(returnId, returnNumber, returnAmount) {
        returnIdField.value = returnId;
        returnSearchField.value = returnNumber;
        
        // Auto-populate credit amount if not already set
        const creditAmountField = document.getElementById('credit_amount');
        if (!creditAmountField.value) {
            creditAmountField.value = returnAmount;
        }
        
        // Auto-populate reason if not already set
        if (!reasonTextarea.value.trim()) {
            reasonTextarea.value = `Credit for returned goods - Return #${returnNumber}`;
        }
        
        returnSearchModal.hide();
    };
    
    // Format currency helper function
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-KE', {
            style: 'currency',
            currency: 'KES'
        }).format(amount);
    }
});
</script>
</body>
</html>
