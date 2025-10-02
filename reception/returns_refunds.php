<?php
// Session is already started by reception.php - no need to start again
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// For AJAX requests, suppress all output except JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Ensure we always return JSON, even on errors
    header('Content-Type: application/json');
    
    // Start output buffering to catch any unexpected output
    ob_start();
    
    // Catch any PHP errors and return them as JSON
    try {

    if ($_POST['action'] === 'search_sales_for_return') {
        try {
            $filters = [
                'search_term' => trim($_POST['search_term'] ?? ''),
                'date_from' => trim($_POST['date_from'] ?? ''),
                'date_to' => trim($_POST['date_to'] ?? ''),
                'receipt_number' => trim($_POST['receipt_number'] ?? '')
            ];

            $result = searchSalesForReturn($conn, $filters);

            // Clean output buffer and return JSON
            ob_clean();
            echo json_encode($result);
        } catch (Exception $e) {
            error_log("Error in search_sales_for_return: " . $e->getMessage());
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Search error: ' . $e->getMessage(),
                'sales' => [],
                'count' => 0
            ]);
        }
        exit();
    }

    if ($_POST['action'] === 'get_sale_details_for_return') {
        $sale_id = intval($_POST['sale_id'] ?? 0);
        $result = getSaleDetailsForReturn($conn, $sale_id);
        ob_clean();
        echo json_encode($result);
        exit();
    }

    if ($_POST['action'] === 'process_return') {
        $return_data = [
            'sale_id' => intval($_POST['sale_id'] ?? 0),
            'return_type' => trim($_POST['return_type'] ?? 'refund'),
            'refund_method' => trim($_POST['refund_method'] ?? 'cash'),
            'return_reason' => trim($_POST['return_reason'] ?? ''),
            'return_notes' => trim($_POST['return_notes'] ?? ''),
            'return_items' => json_decode($_POST['return_items'] ?? '[]', true),
            'user_id' => $_SESSION['user_id'] ?? null
        ];

        $result = processReturn($conn, $return_data);
        ob_clean();
        echo json_encode($result);
        exit();
    }

    if ($_POST['action'] === 'get_return_details') {
        $return_id = intval($_POST['return_id'] ?? 0);
        $result = getReturnDetails($conn, $return_id);
        ob_clean();
        echo json_encode($result);
        exit();
    }
    
    // If we get here, unknown action
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . ($_POST['action'] ?? 'none')]);
    exit();
    
    } catch (Exception $e) {
        // Catch any PHP errors and return them as JSON
        error_log("Error in returns_refunds.php: " . $e->getMessage());
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Server error: ' . $e->getMessage(),
            'error_type' => 'php_exception'
        ]);
        exit();
    } catch (Error $e) {
        // Catch fatal errors
        error_log("Fatal error in returns_refunds.php: " . $e->getMessage());
        ob_clean();
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal error: ' . $e->getMessage(),
            'error_type' => 'php_fatal'
        ]);
        exit();
    }
}

// If this was an AJAX request, we should have already exited by now
// If we get here during an AJAX request, something went wrong
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // This should not happen if the above code is working correctly
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unknown error occurred']);
    exit();
}

// Get system settings
$settings = [];
try {
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($stmt) {
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (PDOException $e) {
    // If settings table doesn't exist or has issues, continue with empty settings
    error_log("Settings query error: " . $e->getMessage());
}
?>

<!-- Returns and Refunds Modal -->
<div class="modal fade" id="returnsRefundsModal" tabindex="-1" aria-labelledby="returnsRefundsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc2626, #b91c1c); color: white;">
                <h5 class="modal-title" id="returnsRefundsModalLabel">
                    <i class="bi bi-arrow-return-left me-2"></i>Product Returns & Refunds
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Search Sales -->
                <div id="searchStep" class="step-content">
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body">
                            <h6 class="card-title text-danger mb-3">
                                <i class="bi bi-search me-2"></i>Find Original Sale
                            </h6>

                            <div class="row g-3">
                            <div class="col-md-4">
                                <label for="returnSearchTerm" class="form-label">Receipt Number / Transaction ID</label>
                                <input type="text" class="form-control" id="returnSearchTerm"
                                       placeholder="e.g., RCP-000123 or 123"
                                       autocomplete="off">
                                <small class="text-muted mt-1">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Enter receipt number (RCP-XXXXXX) or transaction ID
                                </small>
                            </div>

                                <div class="col-md-2">
                                    <label for="returnDateFrom" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="returnDateFrom">
                                </div>

                                <div class="col-md-2">
                                    <label for="returnDateTo" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="returnDateTo">
                                </div>

                                <div class="col-md-2">
                                    <label for="returnReceiptNumber" class="form-label">Receipt #</label>
                                    <input type="text" class="form-control" id="returnReceiptNumber"
                                           placeholder="RCP-000123">
                                </div>

                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger w-100" onclick="searchSalesForReturn()">
                                        <i class="bi bi-search me-1"></i>Search
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row mt-2">
                                <div class="col-12">
                                    <button type="button" class="btn btn-outline-info btn-sm" onclick="showAllRecentSales()">
                                        <i class="bi bi-list me-1"></i>Show All Recent Sales
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="salesSearchResults" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-muted mb-0">
                                <i class="bi bi-receipt me-2"></i>
                                <span id="salesCount">0</span> sales found
                            </h6>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSalesSearch()">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </button>
                        </div>

                        <div id="salesList" class="list-group">
                            <!-- Sales results will be populated here -->
                        </div>
                    </div>

                    <div id="noSalesResults" style="display: none;" class="text-center py-5">
                        <i class="bi bi-receipt" style="font-size: 4rem; color: #ccc;"></i>
                        <h5 class="text-muted mt-3">No sales found</h5>
                        <p class="text-muted">Try adjusting your search criteria</p>
                    </div>
                </div>

                <!-- Step 2: Select Items for Return -->
                <div id="returnStep" class="step-content" style="display: none;">
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="card-title text-danger mb-0">
                                    <i class="bi bi-box-seam me-2"></i>Select Items to Return
                                </h6>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="backToSearch()">
                                    <i class="bi bi-arrow-left me-1"></i>Back to Search
                                </button>
                            </div>

                            <div id="returnSaleDetails" class="mb-4">
                                <!-- Sale details will be populated here -->
                            </div>

                            <!-- Items Header -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-danger mb-0">
                                    <i class="bi bi-check-circle me-2"></i>Select Products to Return
                                </h6>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAllItems">
                                    <label class="form-check-label" for="selectAllItems">
                                        Select All Available
                                    </label>
                                </div>
                            </div>

                            <div id="returnItemsList">
                                <!-- Return items will be populated here -->
                            </div>

                            <!-- Return Options -->
                            <div class="row mt-4" id="returnOptionsSection">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Return Type</label>
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="returnType" id="returnTypeRefund" value="refund" checked>
                                            <label class="form-check-label" for="returnTypeRefund">
                                                <i class="bi bi-cash me-1"></i>Cash Refund
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="returnType" id="returnTypeExchange" value="exchange">
                                            <label class="form-check-label" for="returnTypeExchange">
                                                <i class="bi bi-arrow-repeat me-1"></i>Product Exchange
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Refund Method</label>
                                    <select class="form-select" id="refundMethod">
                                        <option value="cash">Cash</option>
                                        <option value="store_credit">Store Credit</option>
                                        <option value="card">Original Payment Method</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row" id="returnReasonSection">
                                <div class="col-md-6">
                                    <label for="returnReason" class="form-label">Return Reason</label>
                                    <select class="form-select" id="returnReason">
                                        <option value="">Select Reason</option>
                                        <option value="defective">Defective Product</option>
                                        <option value="wrong_item">Wrong Item</option>
                                        <option value="not_as_described">Not as Described</option>
                                        <option value="changed_mind">Changed Mind</option>
                                        <option value="size_issue">Size/Fit Issue</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="returnNotes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="returnNotes" rows="2" placeholder="Any additional notes..."></textarea>
                                </div>
                            </div>

                            <div class="mt-4 p-3 bg-white rounded border" id="returnSummarySection">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Total Items Selected:</strong> <span id="totalReturnItems">0</span>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <strong>Total Refund Amount:</strong> <span id="totalReturnAmount" class="text-danger fw-bold"><?php echo $settings['currency_symbol'] ?? '$'; ?>0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Processing Complete -->
                <div id="completeStep" class="step-content" style="display: none;">
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        </div>
                        <h4 class="text-success mb-3">Return Processed Successfully!</h4>
                        <div id="returnCompleteDetails" class="mb-4">
                            <!-- Return details will be shown here -->
                        </div>
                        <div class="d-flex gap-2 justify-content-center">
                            <button type="button" class="btn btn-primary" onclick="startNewReturn()">
                                <i class="bi bi-plus-circle me-1"></i>Process Another Return
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <div id="returnStepFooter" style="display: none;">
                    <button type="button" class="btn btn-secondary" onclick="backToSearch()">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="processReturn()">
                        <i class="bi bi-check-circle me-1"></i>Process Return
                    </button>
                </div>
                <div id="defaultFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Returns Modal Styles */
.step-content {
    min-height: 400px;
}

.sale-item {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
    cursor: pointer;
}

.sale-item:hover {
    background-color: #f8f9fa;
    border-left-color: #dc2626;
    transform: translateX(5px);
}

.return-item-row {
    display: flex;
    align-items: center;
    padding: 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    background: white;
    transition: all 0.3s ease;
}

.return-item-row.selected {
    border-color: #dc2626;
    background: #fef2f2;
    box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.1);
}

.return-item-details {
    flex: 1;
}

.return-item-name {
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.return-item-meta {
    font-size: 0.85rem;
    color: #6c757d;
}

.return-quantity-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.return-quantity-controls input {
    width: 80px;
    text-align: center;
}

.condition-select {
    min-width: 120px;
}

.return-amount {
    font-weight: 600;
    color: #dc2626;
    min-width: 100px;
    text-align: right;
}

.return-receipt-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Courier New', monospace;
}

.return-receipt-header {
    background: #dc2626;
    color: white;
    padding: 15px;
    text-align: center;
}

.return-receipt-body {
    padding: 15px;
    font-size: 12px;
    line-height: 1.4;
}

@media print {
    .modal, .modal-backdrop, .btn, .form-control {
        display: none !important;
    }

    .return-receipt-container {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        max-width: none;
        box-shadow: none;
        border: none;
        margin: 0;
    }
}
</style>

<script>
// Returns and Refunds Modal Functions
let currentSaleData = null;
let selectedReturnItems = [];

function showReturnsRefunds() {
    const modal = new bootstrap.Modal(document.getElementById('returnsRefundsModal'));
    modal.show();

    // Set default dates (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);

    document.getElementById('returnDateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('returnDateTo').value = today.toISOString().split('T')[0];

    // Reset to search step
    showStep('searchStep');

    // Focus on search input
    setTimeout(() => {
        document.getElementById('returnSearchTerm').focus();
    }, 300);
}

function showStep(stepId) {
    // Batch DOM updates to prevent forced reflow
    requestAnimationFrame(() => {
        // Hide all steps
        document.querySelectorAll('.step-content').forEach(step => {
            step.style.display = 'none';
        });

        // Hide all footers
        document.getElementById('returnStepFooter').style.display = 'none';
        document.getElementById('defaultFooter').style.display = 'none';

        // Show selected step
        document.getElementById(stepId).style.display = 'block';

        if (stepId === 'returnStep') {
            document.getElementById('returnStepFooter').style.display = 'block';
        } else {
            document.getElementById('defaultFooter').style.display = 'block';
        }
    });
}

function searchSalesForReturn() {
    const searchTerm = document.getElementById('returnSearchTerm').value.trim();
    const dateFrom = document.getElementById('returnDateFrom').value;
    const dateTo = document.getElementById('returnDateTo').value;
    const receiptNumber = document.getElementById('returnReceiptNumber').value.trim();

    // Validate search term - allow search with just date range or any search criteria
    if (!searchTerm && !receiptNumber && !dateFrom && !dateTo) {
        showAlert('Please enter a receipt number, transaction ID, or select a date range to search', 'warning');
        return;
    }


    // Show loading state
    const searchBtn = document.querySelector('[onclick="searchSalesForReturn()"]');
    const originalText = searchBtn.innerHTML;
    searchBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Searching...';
    searchBtn.disabled = true;

    // Hide previous results
    document.getElementById('salesSearchResults').style.display = 'none';
    document.getElementById('noSalesResults').style.display = 'none';

    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'search_sales_for_return');
    if (searchTerm) formData.append('search_term', searchTerm);
    if (dateFrom) formData.append('date_from', dateFrom);
    if (dateTo) formData.append('date_to', dateTo);
    if (receiptNumber) formData.append('receipt_number', receiptNumber);

    // Search sales
    fetch('returns_refunds.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.sales && data.sales.length > 0) {
            displaySalesResults(data.sales);
        } else {
            document.getElementById('noSalesResults').style.display = 'block';
            if (data.message) {
                showAlert(data.message, 'warning');
            } else {
                showAlert('No transactions found matching your search criteria. Try adjusting the date range or search term.', 'info');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Search failed. Please try again.', 'danger');
    })
    .finally(() => {
        // Restore button state
        searchBtn.innerHTML = originalText;
        searchBtn.disabled = false;
    });
}

function displaySalesResults(sales) {
    const salesList = document.getElementById('salesList');
    
    // Use DocumentFragment for better performance
    const fragment = document.createDocumentFragment();

    sales.forEach(sale => {
        const saleDate = new Date(sale.created_at);
        const formattedDate = saleDate.toLocaleDateString() + ' ' + saleDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        const saleItem = document.createElement('div');
        saleItem.className = 'list-group-item sale-item';
        saleItem.onclick = () => selectSaleForReturn(sale.id);

        const remainingAmount = parseFloat(sale.final_amount) - parseFloat(sale.total_returned);

        saleItem.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold">${sale.receipt_number}</div>
                    <div class="text-muted small">${formattedDate}</div>
                    <div>${sale.customer_name || 'Walk-in Customer'}</div>
                    <div class="small text-muted">${sale.item_count} items • ${sale.payment_method}</div>
                </div>
                <div class="text-end">
                    <div class="fw-bold text-danger"><?php echo $settings['currency_symbol'] ?? '$'; ?>${remainingAmount.toFixed(2)}</div>
                    <div class="small text-muted">Remaining</div>
                    ${parseFloat(sale.total_returned) > 0 ?
                        `<div class="small text-warning">Returned: <?php echo $settings['currency_symbol'] ?? '$'; ?>${parseFloat(sale.total_returned).toFixed(2)}</div>` :
                        ''}
                </div>
            </div>
        `;

        fragment.appendChild(saleItem);
    });

    // Batch DOM updates to prevent forced reflow
    requestAnimationFrame(() => {
        salesList.innerHTML = '';
        salesList.appendChild(fragment);
        document.getElementById('salesCount').textContent = sales.length;
        document.getElementById('salesSearchResults').style.display = 'block';
    });
}

function clearSalesSearch() {
    // Batch DOM updates for better performance
    requestAnimationFrame(() => {
        document.getElementById('returnSearchTerm').value = '';
        document.getElementById('returnReceiptNumber').value = '';
        document.getElementById('salesSearchResults').style.display = 'none';
        document.getElementById('noSalesResults').style.display = 'none';
    });
}

function selectSaleForReturn(saleId) {
    // Show loading
    const formData = new FormData();
    formData.append('action', 'get_sale_details_for_return');
    formData.append('sale_id', saleId);

    fetch('returns_refunds.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentSaleData = data;
            displayReturnItems(data);
            showStep('returnStep');
        } else {
            showAlert(data.message || 'Failed to load sale details', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load sale details', 'danger');
    });
}

function displayReturnItems(data) {
    const { sale, items } = data;
    const currencySymbol = '<?php echo $settings['currency_symbol'] ?? '$'; ?>';

    // Display sale details
    const saleDetails = document.getElementById('returnSaleDetails');
    const saleDate = new Date(sale.created_at);
    const formattedDate = saleDate.toLocaleDateString() + ' ' + saleDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

    saleDetails.innerHTML = `
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-receipt me-2"></i>${sale.receipt_number}</span>
                    <span>${formattedDate}</span>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Customer:</strong> ${sale.customer_name || 'Walk-in Customer'}<br>
                        <strong>Cashier:</strong> ${sale.cashier_name || 'Unknown'}<br>
                        <strong>Payment:</strong> ${sale.payment_method || 'Cash'}
                    </div>
                    <div class="col-md-6 text-end">
                        <strong>Original Total:</strong> <span class="text-primary">${currencySymbol}${parseFloat(sale.final_amount).toFixed(2)}</span>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Display return items
    const itemsList = document.getElementById('returnItemsList');
    itemsList.innerHTML = '';

    selectedReturnItems = [];

    // Count items available for return
    let availableItemsCount = 0;
    items.forEach(item => {
        const availableQuantity = parseInt(item.available_for_return || 0);
        if (availableQuantity > 0) {
            availableItemsCount++;
        }
    });
    
    // If no items available for return, show enhanced notice and hide all unnecessary features
    if (availableItemsCount === 0) {
        itemsList.innerHTML = `
            <div class="text-center py-5">
                <div class="mb-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                </div>
                <h4 class="text-success mb-3">Return Status: Complete</h4>
                <div class="alert alert-success d-flex align-items-center justify-content-center mx-auto" role="alert" style="max-width: 650px;">
                    <div class="text-center">
                        <p class="mb-2"><strong>🎉 Transaction Fully Processed</strong></p>
                        <p class="mb-2">All products from <strong>${sale.receipt_number}</strong> have been completely returned.</p>
                        <p class="mb-0 small text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            No additional returns can be processed for this transaction as all items have been returned to inventory.
                        </p>
                    </div>
                </div>
                
                <!-- Transaction Summary -->
                <div class="card mx-auto mt-4" style="max-width: 500px;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 text-center">
                            <i class="bi bi-clipboard-check me-2"></i>Transaction Summary
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="text-muted small">Original Sale</div>
                                <div class="fw-bold">${sale.receipt_number}</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">Total Items</div>
                                <div class="fw-bold">${items.reduce((sum, item) => sum + parseInt(item.quantity), 0)}</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted small">All Returned</div>
                                <div class="fw-bold text-success">
                                    <i class="bi bi-check-circle-fill me-1"></i>Yes
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="mt-4">
                    <button type="button" class="btn btn-primary btn-lg me-2" onclick="backToSearch()">
                        <i class="bi bi-search me-2"></i>Search Another Sale
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="bi bi-lightbulb me-1"></i>
                        <strong>Tip:</strong> Use the search above to find a different transaction that may have items available for return.
                    </small>
                </div>
            </div>
        `;
        
        // Hide ALL return-related sections and controls
        const sectionsToHide = [
            'returnOptionsSection',
            'returnReasonSection', 
            'returnSummarySection'
        ];
        
        sectionsToHide.forEach(sectionId => {
            const section = document.getElementById(sectionId);
            if (section) {
                section.style.display = 'none';
            }
        });
        
        // Hide the "Select All Available" checkbox section
        const selectAllSection = document.querySelector('.d-flex.justify-content-between.align-items-center.mb-3');
        if (selectAllSection && selectAllSection.querySelector('#selectAllItems')) {
            selectAllSection.style.display = 'none';
        }
        
        // Hide footer process button
        document.getElementById('returnStepFooter').style.display = 'none';
        
        // Show only the default footer
        document.getElementById('defaultFooter').style.display = 'block';
        
        return;
    }
    
    // Show return options if items are available
    const sectionsToShow = [
        'returnOptionsSection',
        'returnReasonSection', 
        'returnSummarySection'
    ];
    
    sectionsToShow.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            section.style.display = 'flex';
        }
    });
    
    // Ensure the summary section uses block display
    const summarySection = document.getElementById('returnSummarySection');
    if (summarySection) {
        summarySection.style.display = 'block';
    }
    
    // Show footer process button
    document.getElementById('returnStepFooter').style.display = 'block';

    items.forEach(item => {
        const availableQuantity = parseInt(item.available_for_return || 0);

        if (availableQuantity > 0) {
            const itemRow = document.createElement('div');
            itemRow.className = 'return-item-row';

            itemRow.innerHTML = `
                <div class="return-item-details">
                    <div class="d-flex align-items-center mb-2">
                        <input type="checkbox" class="form-check-input me-2 item-checkbox" data-item-id="${item.id}">
                        <div>
                            <div class="return-item-name fw-bold">${item.product_name || 'Product'}</div>
                            <div class="return-item-meta small text-muted">
                                SKU: ${item.sku || 'N/A'} •
                                Original Qty: ${item.quantity} •
                                Already Returned: ${item.already_returned || 0} •
                                Available: ${availableQuantity} •
                                Unit Price: ${currencySymbol}${parseFloat(item.unit_price).toFixed(2)}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="return-quantity-controls">
                    <label class="form-label mb-1 small">Return Quantity:</label>
                    <input type="number" class="form-control form-control-sm return-qty-input"
                           min="0" max="${availableQuantity}" value="0"
                           data-item-id="${item.id}"
                           data-product-id="${item.product_id || ''}"
                           data-product-name="${item.product_name}"
                           data-unit-price="${item.unit_price}"
                           data-max-qty="${availableQuantity}">
                    <small class="text-muted">Max: ${availableQuantity}</small>
                </div>
                <div class="condition-select">
                    <label class="form-label mb-1 small">Condition:</label>
                    <select class="form-select form-select-sm condition-select-input" data-item-id="${item.id}">
                        <option value="new">New</option>
                        <option value="used" selected>Used</option>
                        <option value="damaged">Damaged</option>
                    </select>
                </div>
                <div class="return-amount text-end">
                    <div class="small text-muted mb-1">Return Amount:</div>
                    <div class="fw-bold text-danger">${currencySymbol}<span class="return-amount-display" data-item-id="${item.id}">0.00</span></div>
                </div>
            `;

            itemsList.appendChild(itemRow);

            // Add event listeners
            const qtyInput = itemRow.querySelector('.return-qty-input');
            const conditionSelect = itemRow.querySelector('.condition-select-input');
            const amountDisplay = itemRow.querySelector('.return-amount-display');
            const itemCheckbox = itemRow.querySelector('.item-checkbox');

            qtyInput.addEventListener('input', function() {
                updateReturnAmount(this.dataset.itemId, this.value, item.unit_price, amountDisplay);
                // Update checkbox based on quantity
                itemCheckbox.checked = parseInt(this.value) > 0;
                // Update visual selection
                itemRow.classList.toggle('selected', parseInt(this.value) > 0);
            });

            itemCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    qtyInput.value = availableQuantity;
                } else {
                    qtyInput.value = 0;
                }
                updateReturnAmount(qtyInput.dataset.itemId, qtyInput.value, item.unit_price, amountDisplay);
                // Update visual selection
                itemRow.classList.toggle('selected', this.checked);
            });

            conditionSelect.addEventListener('change', function() {
                // Could add logic for condition-based pricing adjustments
            });

            // Store reference for select all functionality
            qtyInput._maxValue = availableQuantity;
            qtyInput._amountDisplay = amountDisplay;
            qtyInput._unitPrice = item.unit_price;
            qtyInput._checkbox = itemCheckbox;
        }
    });

    // Add select all functionality
    const selectAllCheckbox = document.getElementById('selectAllItems');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const qtyInputs = document.querySelectorAll('.return-qty-input');
            const checkboxes = document.querySelectorAll('.item-checkbox');
            const itemRows = document.querySelectorAll('.return-item-row');
            qtyInputs.forEach((input, index) => {
                if (this.checked) {
                    input.value = input._maxValue;
                    checkboxes[index].checked = true;
                    itemRows[index].classList.add('selected');
                } else {
                    input.value = 0;
                    checkboxes[index].checked = false;
                    itemRows[index].classList.remove('selected');
                }
                updateReturnAmount(input.dataset.itemId, input.value, input._unitPrice, input._amountDisplay);
            });
        });
    }

    // Add return type change functionality
    setupReturnTypeHandlers();
}

function updateReturnAmount(itemId, quantity, unitPrice, amountDisplay) {
    const returnQty = parseInt(quantity) || 0;
    const amount = returnQty * parseFloat(unitPrice);

    amountDisplay.textContent = amount.toFixed(2);

    // Update selected items
    const existingIndex = selectedReturnItems.findIndex(item => item.sale_item_id == itemId);

    if (returnQty > 0) {
        const qtyInput = document.querySelector(`[data-item-id="${itemId}"].return-qty-input`);
        const conditionSelect = document.querySelector(`[data-item-id="${itemId}"].condition-select-input`);
        
        const itemData = {
            sale_item_id: itemId,
            product_id: qtyInput.dataset.productId || null,
            product_name: qtyInput.dataset.productName || '',
            quantity: parseInt(qtyInput.dataset.maxQty) || 0,
            unit_price: parseFloat(unitPrice) || 0,
            total_price: (parseFloat(unitPrice) || 0) * (parseInt(qtyInput.dataset.maxQty) || 0),
            return_quantity: returnQty,
            return_amount: amount,
            condition_status: conditionSelect ? conditionSelect.value : 'used',
            condition_notes: ''
        };

        if (existingIndex >= 0) {
            selectedReturnItems[existingIndex] = itemData;
        } else {
            selectedReturnItems.push(itemData);
        }
    } else if (existingIndex >= 0) {
        selectedReturnItems.splice(existingIndex, 1);
    }

    updateReturnTotals();
}

function updateReturnTotals() {
    const totalItems = selectedReturnItems.reduce((sum, item) => sum + parseInt(item.return_quantity), 0);
    const totalAmount = selectedReturnItems.reduce((sum, item) => sum + parseFloat(item.return_amount), 0);

    document.getElementById('totalReturnItems').textContent = totalItems;
    document.getElementById('totalReturnAmount').textContent = '<?php echo $settings['currency_symbol'] ?? '$'; ?>' + totalAmount.toFixed(2);
}

function setupReturnTypeHandlers() {
    const returnTypeRefund = document.getElementById('returnTypeRefund');
    const returnTypeExchange = document.getElementById('returnTypeExchange');
    const refundMethodSelect = document.getElementById('refundMethod');
    
    if (returnTypeRefund && returnTypeExchange && refundMethodSelect) {
        // Function to update refund method options based on return type
        function updateRefundMethodOptions() {
            const selectedReturnType = document.querySelector('input[name="returnType"]:checked').value;
            
            // Clear existing options
            refundMethodSelect.innerHTML = '';
            
            if (selectedReturnType === 'refund') {
                // Cash Refund - only allow Cash
                refundMethodSelect.innerHTML = '<option value="cash">Cash</option>';
            } else if (selectedReturnType === 'exchange') {
                // Product Exchange - only allow Store Credit
                refundMethodSelect.innerHTML = '<option value="store_credit">Store Credit</option>';
            }
        }
        
        // Add event listeners to both radio buttons
        returnTypeRefund.addEventListener('change', updateRefundMethodOptions);
        returnTypeExchange.addEventListener('change', updateRefundMethodOptions);
        
        // Initialize with default selection (refund)
        updateRefundMethodOptions();
    }
}

function backToSearch() {
    showStep('searchStep');
    selectedReturnItems = [];
}

function processReturn() {
    if (selectedReturnItems.length === 0) {
        showAlert('Please select at least one item to return', 'warning');
        return;
    }

    const returnType = document.querySelector('input[name="returnType"]:checked').value;
    const refundMethod = document.getElementById('refundMethod').value;
    const returnReason = document.getElementById('returnReason').value;
    const returnNotes = document.getElementById('returnNotes').value;

    if (!returnReason) {
        showAlert('Please select a return reason', 'warning');
        return;
    }

    // Show loading
    const processBtn = document.querySelector('[onclick="processReturn()"]');
    const originalText = processBtn.innerHTML;
    processBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Processing...';
    processBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'process_return');
    formData.append('sale_id', currentSaleData.sale.id);
    formData.append('return_type', returnType);
    formData.append('refund_method', refundMethod);
    formData.append('return_reason', returnReason);
    formData.append('return_notes', returnNotes);
    formData.append('return_items', JSON.stringify(selectedReturnItems));

    fetch('returns_refunds.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayReturnComplete(data);
            showStep('completeStep');
        } else {
            showAlert(data.message || 'Failed to process return', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to process return', 'danger');
    })
    .finally(() => {
        // Restore button state
        processBtn.innerHTML = originalText;
        processBtn.disabled = false;
    });
}

function displayReturnComplete(data) {
    const completeDetails = document.getElementById('returnCompleteDetails');

    completeDetails.innerHTML = `
        <div class="card border-success">
            <div class="card-body text-center">
                <h5 class="text-success">${data.return_number}</h5>
                <p class="mb-2">Return processed successfully</p>
                <div class="row">
                    <div class="col-6">
                        <strong>Items Returned:</strong><br>
                        ${selectedReturnItems.reduce((sum, item) => sum + parseInt(item.return_quantity), 0)}
                    </div>
                    <div class="col-6">
                        <strong>Refund Amount:</strong><br>
                        <span class="text-success fw-bold"><?php echo $settings['currency_symbol'] ?? '$'; ?>${selectedReturnItems.reduce((sum, item) => sum + parseFloat(item.return_amount), 0).toFixed(2)}</span>
                    </div>
                </div>
            </div>
        </div>
    `;
}


function startNewReturn() {
    selectedReturnItems = [];
    currentSaleData = null;
    showStep('searchStep');
    clearSalesSearch();
}

function showAlert(message, type) {
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    // Add to page
    document.body.appendChild(alertDiv);

    // Auto remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Enable Enter key for search
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = ['returnSearchTerm', 'returnReceiptNumber'];
    searchInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchSalesForReturn();
                }
            });
        }
    });
});

// Make functions globally available
window.showReturnsRefunds = showReturnsRefunds;
window.searchSalesForReturn = searchSalesForReturn;

// Function to show all recent sales with loading state
function showAllRecentSales() {
    const btn = document.querySelector('button[onclick="showAllRecentSales()"]');
    const originalContent = btn.innerHTML;
    
    // Show loading state
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Loading...';
    btn.disabled = true;
    
    const formData = new FormData();
    formData.append('action', 'search_sales_for_return');
    // Don't add any search criteria to get all recent sales
    
    fetch('returns_refunds.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.sales && data.sales.length > 0) {
            displaySalesResults(data.sales);
            showAlert(`Found ${data.sales.length} recent sales`, 'success');
        } else {
            document.getElementById('noSalesResults').style.display = 'block';
            showAlert('No recent sales found. Try adjusting the date range or check if there are any sales in the system.', 'warning');
        }
    })
    .catch(error => {
        console.error('Recent sales search error:', error);
        showAlert('Failed to load recent sales', 'danger');
    })
    .finally(() => {
        // Reset button state
        btn.innerHTML = originalContent;
        btn.disabled = false;
    });
}

window.showAllRecentSales = showAllRecentSales;
window.clearSalesSearch = clearSalesSearch;
window.selectSaleForReturn = selectSaleForReturn;
window.backToSearch = backToSearch;
window.processReturn = processReturn;
window.startNewReturn = startNewReturn;
</script>
