<?php
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Catch any PHP errors and return them as JSON
    try {

    if ($_POST['action'] === 'search_transactions') {
        try {
            $search_term = trim($_POST['search_term'] ?? '');
            $date_from = trim($_POST['date_from'] ?? '');
            $date_to = trim($_POST['date_to'] ?? '');
            $receipt_number = trim($_POST['receipt_number'] ?? '');

            $where_conditions = [];
            $params = [];

            // Search by receipt number (extract numeric part)
            if (!empty($receipt_number)) {
                $numeric_part = preg_replace('/[^0-9]/', '', $receipt_number);
                if (!empty($numeric_part)) {
                    $where_conditions[] = "s.id = ?";
                    $params[] = intval($numeric_part);
                }
            }

            // Date range filter
            if (!empty($date_from)) {
                $where_conditions[] = "DATE(s.created_at) >= ?";
                $params[] = $date_from;
            }
            if (!empty($date_to)) {
                $where_conditions[] = "DATE(s.created_at) <= ?";
                $params[] = $date_to;
            }

            // Search term filter (customer name, phone, email)
            if (!empty($search_term)) {
                $where_conditions[] = "(
                    s.customer_name LIKE ? OR
                    s.customer_phone LIKE ? OR
                    s.customer_email LIKE ? OR
                    s.id = ? OR
                    CONCAT('RCP-', LPAD(s.id, 6, '0')) LIKE ?
                )";
                $search_pattern = '%' . $search_term . '%';
                $params[] = $search_pattern;
                $params[] = $search_pattern;
                $params[] = $search_pattern;
                $params[] = intval($search_term) ?: 0;
                $params[] = $search_pattern;
            }

            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

            $stmt = $conn->prepare("
                SELECT s.id, s.created_at, s.customer_name, s.customer_phone, s.customer_email,
                       s.final_amount, s.payment_method, s.total_paid, s.change_due,
                       COUNT(si.id) as item_count, s.user_id, u.username as cashier_name,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                LEFT JOIN users u ON s.user_id = u.id
                {$where_clause}
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT 50
            ");

            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'transactions' => $transactions,
                'count' => count($transactions)
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_POST['action'] === 'reprint_receipt') {
        try {
            $sale_id = intval($_POST['sale_id'] ?? 0);

            if ($sale_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
                exit();
            }

            // Get sale details
            $stmt = $conn->prepare("
                SELECT s.*, u.username as cashier_name,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.id = ?
            ");
            $stmt->execute([$sale_id]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                exit();
            }

            // Get sale items
            $stmt = $conn->prepare("
                SELECT si.*, p.name as product_name, p.sku
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.id
                WHERE si.sale_id = ?
                ORDER BY si.id
            ");
            $stmt->execute([$sale_id]);
            $sale_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get system settings for receipt formatting
            $settings = [];
            $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            echo json_encode([
                'success' => true,
                'sale' => $sale,
                'items' => $sale_items,
                'settings' => $settings
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error retrieving receipt: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // If we get here, unknown action
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . ($_POST['action'] ?? 'none')]);
    exit();
    
    } catch (Exception $e) {
        // Catch any PHP errors and return them as JSON
        error_log("Error in transaction_cart.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Server error: ' . $e->getMessage(),
            'error_type' => 'php_exception'
        ]);
        exit();
    } catch (Error $e) {
        // Catch fatal errors
        error_log("Fatal error in transaction_cart.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal error: ' . $e->getMessage(),
            'error_type' => 'php_fatal'
        ]);
        exit();
    }
}

// Get system settings for the modal
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!-- Transaction Search and Receipt Reprinting Modal -->
<div class="modal fade" id="transactionCartModal" tabindex="-1" aria-labelledby="transactionCartModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white;">
                <h5 class="modal-title" id="transactionCartModalLabel">
                    <i class="bi bi-search me-2"></i>Transaction Search & Receipt Reprinting
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Search Section -->
                <div class="card border-0 bg-light mb-4">
                    <div class="card-body">
                        <h6 class="card-title text-primary mb-3">
                            <i class="bi bi-funnel me-2"></i>Search Transactions
                        </h6>

                        <div class="row g-3">
                            <!-- Search Term -->
                            <div class="col-md-4">
                                <label for="cartSearchTerm" class="form-label">Search Term</label>
                                <input type="text" class="form-control" id="cartSearchTerm"
                                       placeholder="Customer name, phone, email, or receipt #"
                                       autocomplete="off">
                                <small class="text-muted">Examples: John Doe, 555-123-4567, RCP-000123</small>
                            </div>

                            <!-- Date From -->
                            <div class="col-md-2">
                                <label for="cartDateFrom" class="form-label">From Date</label>
                                <input type="date" class="form-control" id="cartDateFrom">
                            </div>

                            <!-- Date To -->
                            <div class="col-md-2">
                                <label for="cartDateTo" class="form-label">To Date</label>
                                <input type="date" class="form-control" id="cartDateTo">
                            </div>

                            <!-- Receipt Number -->
                            <div class="col-md-2">
                                <label for="cartReceiptNumber" class="form-label">Receipt #</label>
                                <input type="text" class="form-control" id="cartReceiptNumber"
                                       placeholder="RCP-000123">
                            </div>

                            <!-- Search Button -->
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-primary w-100" onclick="searchTransactions()">
                                    <i class="bi bi-search me-1"></i>Search
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Results -->
                <div id="transactionResults" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-muted mb-0">
                            <i class="bi bi-list-ul me-2"></i>
                            <span id="resultsCount">0</span> transactions found
                        </h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearTransactionSearch()">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </button>
                    </div>

                    <div id="transactionList" class="list-group">
                        <!-- Transaction results will be populated here -->
                    </div>
                </div>

                <!-- No Results Message -->
                <div id="noTransactionResults" style="display: none;" class="text-center py-5">
                    <i class="bi bi-receipt" style="font-size: 4rem; color: #ccc;"></i>
                    <h5 class="text-muted mt-3">No transactions found</h5>
                    <p class="text-muted">Try adjusting your search criteria</p>
                </div>

                <!-- Receipt Preview Modal (within the main modal) -->
                <div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h6 class="modal-title">Receipt Preview</h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div id="receiptPreviewContent" style="font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.4; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; max-width: 350px; margin: 0 auto;">
                                    <!-- Receipt content will be loaded here -->
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-primary" onclick="printReceipt()">
                                    <i class="bi bi-printer me-1"></i>Print Receipt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Transaction Cart Modal Styles */
.transaction-item {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.transaction-item:hover {
    background-color: #f8f9fa;
    border-left-color: #3b82f6;
    transform: translateX(5px);
}

.transaction-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.transaction-receipt-number {
    font-weight: 600;
    color: #3b82f6;
}

.transaction-date {
    font-size: 0.85rem;
    color: #6c757d;
}

.transaction-customer {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.transaction-details {
    font-size: 0.85rem;
    color: #6c757d;
}

.transaction-amount {
    font-weight: 600;
    color: #198754;
    font-size: 1.1rem;
}

.receipt-preview-container {
    max-width: 400px;
    margin: 0 auto;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Courier New', monospace;
}

.receipt-header-preview {
    background: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
    color: white;
    padding: 15px;
    text-align: center;
}

.receipt-body-preview {
    padding: 15px;
    font-size: 12px;
    line-height: 1.4;
}

.receipt-item-preview {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 4px;
    border-bottom: 1px dotted #e5e7eb;
}

.receipt-total-preview {
    border-top: 2px solid #e5e7eb;
    padding-top: 8px;
    margin-top: 8px;
    font-weight: bold;
}

@media print {
    .modal, .modal-backdrop, .btn, .form-control {
        display: none !important;
    }

    .receipt-preview-container {
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
// Transaction Cart Modal Functions
let currentReceiptData = null;

function showTransactionCart() {
    const modal = new bootstrap.Modal(document.getElementById('transactionCartModal'));
    modal.show();

    // Set default dates (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);

    document.getElementById('cartDateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('cartDateTo').value = today.toISOString().split('T')[0];

    // Focus on search input
    setTimeout(() => {
        document.getElementById('cartSearchTerm').focus();
    }, 300);
}

function searchTransactions() {
    const searchTerm = document.getElementById('cartSearchTerm').value.trim();
    const dateFrom = document.getElementById('cartDateFrom').value;
    const dateTo = document.getElementById('cartDateTo').value;
    const receiptNumber = document.getElementById('cartReceiptNumber').value.trim();

    // Show loading state
    const searchBtn = document.querySelector('[onclick="searchTransactions()"]');
    const originalText = searchBtn.innerHTML;
    searchBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Searching...';
    searchBtn.disabled = true;

    // Hide previous results
    document.getElementById('transactionResults').style.display = 'none';
    document.getElementById('noTransactionResults').style.display = 'none';

    // Prepare form data
    const formData = new FormData();
    formData.append('action', 'search_transactions');
    if (searchTerm) formData.append('search_term', searchTerm);
    if (dateFrom) formData.append('date_from', dateFrom);
    if (dateTo) formData.append('date_to', dateTo);
    if (receiptNumber) formData.append('receipt_number', receiptNumber);

    // Search transactions
    fetch('transaction_cart.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.transactions.length > 0) {
            displayTransactionResults(data.transactions);
        } else {
            document.getElementById('noTransactionResults').style.display = 'block';
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

function displayTransactionResults(transactions) {
    const transactionList = document.getElementById('transactionList');
    transactionList.innerHTML = '';

    transactions.forEach(transaction => {
        const transactionDate = new Date(transaction.created_at);
        const formattedDate = transactionDate.toLocaleDateString() + ' ' + transactionDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        const listItem = document.createElement('div');
        listItem.className = 'list-group-item transaction-item';
        listItem.innerHTML = `
            <div class="transaction-header">
                <span class="transaction-receipt-number">${transaction.receipt_number}</span>
                <span class="transaction-amount"><?php echo $settings['currency_symbol'] ?? '$'; ?>${parseFloat(transaction.final_amount).toFixed(2)}</span>
            </div>
            <div class="transaction-date">${formattedDate}</div>
            <div class="transaction-customer">${transaction.customer_name || 'Walk-in Customer'}</div>
            <div class="transaction-details">
                ${transaction.item_count} item(s) • ${transaction.payment_method} • Cashier: ${transaction.cashier_name}
            </div>
            <div class="mt-2">
                <button type="button" class="btn btn-primary btn-sm me-2" onclick="viewReceipt(${transaction.id})">
                    <i class="bi bi-eye me-1"></i>View
                </button>
                <button type="button" class="btn btn-success btn-sm" onclick="reprintReceipt(${transaction.id})">
                    <i class="bi bi-printer me-1"></i>Reprint
                </button>
            </div>
        `;

        transactionList.appendChild(listItem);
    });

    document.getElementById('resultsCount').textContent = transactions.length;
    document.getElementById('transactionResults').style.display = 'block';
}

function clearTransactionSearch() {
    document.getElementById('cartSearchTerm').value = '';
    document.getElementById('cartReceiptNumber').value = '';
    document.getElementById('transactionResults').style.display = 'none';
    document.getElementById('noTransactionResults').style.display = 'none';
}

function viewReceipt(saleId) {
    const formData = new FormData();
    formData.append('action', 'reprint_receipt');
    formData.append('sale_id', saleId);

    fetch('transaction_cart.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentReceiptData = data;
            displayReceiptPreview(data);
            const modal = new bootstrap.Modal(document.getElementById('receiptPreviewModal'));
            modal.show();
        } else {
            showAlert(data.message || 'Failed to load receipt', 'danger');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Failed to load receipt', 'danger');
    });
}

function reprintReceipt(saleId) {
    viewReceipt(saleId); // Load and show receipt, then user can print
}

function displayReceiptPreview(data) {
    const { sale, items, settings } = data;
    const currencySymbol = settings.currency_symbol || '$';

    const saleDate = new Date(sale.created_at);
    const formattedDate = saleDate.toLocaleDateString() + ' ' + saleDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

    let receiptHtml = `
        <div class="receipt-preview-container">
            <div class="receipt-header-preview">
                <div style="font-size: 18px; font-weight: bold; margin-bottom: 5px;">${settings.company_name || 'Your Store'}</div>
                <div style="font-size: 12px;">
                    ${settings.company_address || ''}<br>
                    ${settings.company_phone ? 'Phone: ' + settings.company_phone : ''}<br>
                    ${settings.company_email || ''}
                </div>
            </div>
            <div class="receipt-body-preview">
                <div style="text-align: center; margin-bottom: 15px; border-bottom: 1px dashed #ccc; padding-bottom: 10px;">
                    <div style="font-weight: bold;">${sale.receipt_number}</div>
                    <div>${formattedDate}</div>
                    <div>Cashier: ${sale.cashier_name || 'Unknown'}</div>
                </div>

                <div style="margin-bottom: 15px;">
    `;

    items.forEach(item => {
        receiptHtml += `
            <div class="receipt-item-preview">
                <div>
                    <div style="font-weight: bold;">${item.product_name || 'Product'}</div>
                    <div style="font-size: 10px; color: #666;">
                        ${item.quantity} × ${currencySymbol}${parseFloat(item.unit_price).toFixed(2)}
                        ${item.sku ? ' (SKU: ' + item.sku + ')' : ''}
                    </div>
                </div>
                <div style="font-weight: bold;">${currencySymbol}${parseFloat(item.total_price).toFixed(2)}</div>
            </div>
        `;
    });

    receiptHtml += `
                </div>

                <div class="receipt-total-preview">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span>Subtotal:</span>
                        <span>${currencySymbol}${parseFloat(sale.subtotal).toFixed(2)}</span>
                    </div>
    `;

    if (sale.tax_amount > 0) {
        receiptHtml += `
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span>Tax (${sale.tax_rate}%):</span>
                <span>${currencySymbol}${parseFloat(sale.tax_amount).toFixed(2)}</span>
            </div>
        `;
    }

    receiptHtml += `
                    <div style="display: flex; justify-content: space-between; font-size: 14px; font-weight: bold; border-top: 1px solid #000; padding-top: 5px;">
                        <span>TOTAL:</span>
                        <span>${currencySymbol}${parseFloat(sale.final_amount).toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                        <span>Payment:</span>
                        <span>${sale.payment_method || 'Cash'}</span>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 15px; font-size: 11px; color: #666;">
                    <div>Thank you for your business!</div>
                    <div style="margin-top: 5px;">
                        ${settings.receipt_footer_text || 'This is a computer-generated receipt.'}
                    </div>
                </div>
            </div>
        </div>
    `;

    document.getElementById('receiptPreviewContent').innerHTML = receiptHtml;
}

function printReceipt() {
    // Get the receipt content
    const receiptContent = document.getElementById('receiptPreviewContent').innerHTML;
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    
    // Write the receipt HTML with proper styling
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt Print</title>
            <style>
                body {
                    font-family: 'Courier New', monospace;
                    margin: 0;
                    padding: 20px;
                    font-size: 12px;
                    line-height: 1.4;
                }
                .receipt-preview-container {
                    max-width: 300px;
                    margin: 0 auto;
                }
                .receipt-header-preview {
                    text-align: center;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px dashed #000;
                }
                .receipt-item-preview {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 8px;
                    align-items: flex-start;
                }
                .receipt-total-preview {
                    border-top: 1px dashed #000;
                    padding-top: 10px;
                    margin-top: 15px;
                }
                @media print {
                    body { margin: 0; padding: 10px; }
                    .receipt-preview-container { max-width: none; }
                }
            </style>
        </head>
        <body>
            ${receiptContent}
        </body>
        </html>
    `);
    
    // Close the document and trigger print
    printWindow.document.close();
    
    // Wait for content to load, then print and close
    printWindow.onload = function() {
        printWindow.print();
        printWindow.onafterprint = function() {
            printWindow.close();
        };
    };
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
    const searchInputs = ['cartSearchTerm', 'cartReceiptNumber'];
    searchInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchTransactions();
                }
            });
        }
    });
});

// Make functions globally available
window.showTransactionCart = showTransactionCart;
window.searchTransactions = searchTransactions;
window.clearTransactionSearch = clearTransactionSearch;
window.viewReceipt = viewReceipt;
window.reprintReceipt = reprintReceipt;
window.printReceipt = printReceipt;
</script>
