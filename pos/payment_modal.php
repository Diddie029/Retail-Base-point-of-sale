<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get active payment methods
$stmt = $conn->query("
    SELECT * FROM payment_types 
    WHERE is_active = 1 
    ORDER BY sort_order, display_name
");
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add loyalty points as a payment method if not already present
$hasLoyaltyPoints = false;
foreach ($payment_methods as $method) {
    if ($method['name'] === 'loyalty_points') {
        $hasLoyaltyPoints = true;
        break;
    }
}

if (!$hasLoyaltyPoints) {
    $payment_methods[] = [
        'id' => 999,
        'name' => 'loyalty_points',
        'display_name' => 'Loyalty Points',
        'description' => 'Pay with loyalty points',
        'icon' => 'bi bi-gift',
        'color' => '#ffc107',
        'is_active' => 1,
        'sort_order' => 99
    ];
}

// Get cart data from session or POST
$cart_items = $_POST['cart_items'] ?? $_SESSION['cart'] ?? [];
$tax_rate = $settings['tax_rate'] ?? 16.0;

// Calculate cart totals using the new function
$cart_totals = calculateCartTotals($cart_items, $tax_rate);
$subtotal = $cart_totals['subtotal'];
$tax_amount = $cart_totals['tax'];
$total_amount = $cart_totals['total'];
?>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="paymentModalLabel">
                    <i class="bi bi-credit-card me-2"></i>Payment Processing
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Payment Summary -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="fw-bold">Transaction Summary</h6>
                        <div class="d-flex justify-content-between">
                            <span>Subtotal:</span>
                            <span class="fw-bold"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <span id="paymentSubtotal">0.00</span></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Tax (<?php echo $tax_rate; ?>%):</span>
                            <span class="fw-bold"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <span id="paymentTax">0.00</span></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="h6">Total Amount:</span>
                            <span class="h6 text-success fw-bold"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <span id="paymentTotal">0.00</span></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold">Items (<span id="paymentItemCount">0</span>)</h6>
                        <div class="receipt-items" id="paymentItems" style="max-height: 200px; overflow-y: auto;">
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-cart-x fs-1"></i>
                                <p class="mt-2 mb-1">No items in cart</p>
                                <small>Add products to get started</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods -->
                <div class="mb-4">
                    <h6 class="fw-bold mb-3">Select Payment Method</h6>
                    
                    <!-- Cash Payment Method (Always Visible) -->
                    <div class="row g-2 mb-3">
                        <?php foreach ($payment_methods as $index => $method): ?>
                            <?php if ($method['name'] === 'cash'): ?>
                            <div class="col-md-4 col-sm-6">
                                <div class="payment-method card h-100 selected" 
                                     data-method="<?php echo $method['name']; ?>" 
                                     style="cursor: pointer; transition: all 0.3s; border-color: #007bff; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                                    <div class="card-body text-center p-3">
                                        <div class="mb-2">
                                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center" 
                                                 style="width: 40px; height: 40px; background: <?php echo $method['color']; ?>;">
                                                <i class="<?php echo $method['icon']; ?> text-white fs-5"></i>
                                            </div>
                                        </div>
                                        <h6 class="card-title mb-1"><?php echo htmlspecialchars($method['display_name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($method['description']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- Other Payment Methods (Collapsible) -->
                    <div class="mb-3">
                        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#otherPaymentMethods" aria-expanded="false" aria-controls="otherPaymentMethods">
                            <i class="bi bi-chevron-down me-1"></i>Other Payment Methods
                        </button>
                    </div>
                    
                    <div class="collapse" id="otherPaymentMethods">
                        <div class="row g-2">
                            <?php foreach ($payment_methods as $index => $method): ?>
                                <?php if ($method['name'] !== 'cash'): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="payment-method card h-100" 
                                         data-method="<?php echo $method['name']; ?>" 
                                         style="cursor: pointer; transition: all 0.3s;">
                                        <div class="card-body text-center p-3">
                                            <div class="mb-2">
                                                <div class="rounded-circle d-inline-flex align-items-center justify-content-center" 
                                                     style="width: 40px; height: 40px; background: <?php echo $method['color']; ?>;">
                                                    <i class="<?php echo $method['icon']; ?> text-white fs-5"></i>
                                                </div>
                                            </div>
                                            <h6 class="card-title mb-1"><?php echo htmlspecialchars($method['display_name']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($method['description']); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Cash Payment Section -->
                <div id="cashPaymentSection" class="mb-4" style="display: block;">
                    <h6 class="fw-bold mb-3">
                        <i class="bi bi-cash-coin me-2"></i>Cash Payment Details
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="cashReceived" class="form-label fw-semibold">
                                <i class="bi bi-arrow-down-circle me-1"></i>Amount Received
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-primary text-white fw-bold">
                                    <?php echo $settings['currency_symbol'] ?? 'KES'; ?>
                                </span>
                                <input type="number" 
                                       class="form-control form-control-lg text-end fw-bold" 
                                       id="cashReceived" 
                                       step="0.01" 
                                       min="0" 
                                       placeholder="0.00"
                                       style="font-size: 1.2rem; border-left: none;">
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>Enter the amount received from customer
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-arrow-up-circle me-1"></i>Change Due
                            </label>
                            <div id="changeDisplay" class="p-4 border-2 rounded-3 position-relative" 
                                 style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-color: #dee2e6 !important; min-height: 80px;">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="bi bi-calculator text-muted" style="font-size: 1.5rem;"></i>
                                    </div>
                                    <small class="text-muted d-block mb-1">Change</small>
                                    <div class="h4 mb-0 fw-bold" id="changeAmount" style="color: #dc3545;">
                                        <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00
                                    </div>
                                </div>
                                <!-- Status indicator -->
                                <div id="changeStatus" class="position-absolute top-0 end-0 p-2">
                                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 1.2rem;"></i>
                                </div>
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>Amount to return to customer
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Amount Buttons -->
                    <div class="mt-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-lightning me-1"></i>Quick Amounts
                        </label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm quick-amount" data-amount="0">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm quick-amount" data-amount="50">
                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 50
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm quick-amount" data-amount="100">
                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 100
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm quick-amount" data-amount="500">
                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 500
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm quick-amount" data-amount="1000">
                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 1,000
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm quick-amount" data-amount="2000">
                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 2,000
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm" id="exactAmountBtn">
                                <i class="bi bi-check-circle me-1"></i>Exact Amount
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Mobile Money Payment Section -->
                <div id="mobileMoneySection" class="mb-4" style="display: none;">
                    <h6 class="fw-bold mb-3">Mobile Money Details</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="mobileNumber" class="form-label">Mobile Number</label>
                            <input type="tel" class="form-control" id="mobileNumber" placeholder="07XXXXXXXX">
                        </div>
                        <div class="col-md-6">
                            <label for="mobileProvider" class="form-label">Provider</label>
                            <select class="form-select" id="mobileProvider">
                                <option value="">Select Provider</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="airtel">Airtel Money</option>
                                <option value="equitel">Equitel</option>
                                <option value="telkom">Telkom</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Card Payment Section -->
                <div id="cardPaymentSection" class="mb-4" style="display: none;">
                    <h6 class="fw-bold mb-3">Card Payment Details</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="cardNumber" class="form-label">Card Number</label>
                            <input type="text" class="form-control" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19">
                        </div>
                        <div class="col-md-3">
                            <label for="cardExpiry" class="form-label">Expiry</label>
                            <input type="text" class="form-control" id="cardExpiry" placeholder="MM/YY" maxlength="5">
                        </div>
                        <div class="col-md-3">
                            <label for="cardCVV" class="form-label">CVV</label>
                            <input type="text" class="form-control" id="cardCVV" placeholder="123" maxlength="4">
                        </div>
                    </div>
                </div>

                <!-- Loyalty Points Payment Section -->
                <div id="loyaltyPointsPaymentSection" class="mb-4" style="display: none;">
                    <h6 class="fw-bold mb-3">Loyalty Points Payment</h6>
                    
                    <!-- Customer Selection for Loyalty Points -->
                    <div class="mb-3">
                        <label class="form-label">Select Customer</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-person"></i>
                            </span>
                            <input type="text" class="form-control" id="loyaltyCustomerSearch" placeholder="Search customer by name, phone, email, or customer number...">
                            <button class="btn btn-outline-secondary" type="button" id="loyaltyCustomerSearchBtn">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div id="loyaltyCustomerResults" class="mt-2" style="max-height: 200px; overflow-y: auto; display: none;">
                            <!-- Customer search results will appear here -->
                        </div>
                        <div id="loyaltySelectedCustomer" class="mt-2" style="display: none;">
                            <div class="alert alert-success d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="bi bi-person-check me-2"></i>
                                    <strong id="loyaltySelectedCustomerName">Customer Name</strong>
                                    <br>
                                    <small id="loyaltySelectedCustomerPoints">0 points available</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger" id="loyaltyClearCustomer">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <label for="loyaltyPointsAvailable" class="form-label">Available Points</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-gift text-warning"></i>
                                </span>
                                <input type="text" class="form-control" id="loyaltyPointsAvailable" readonly placeholder="0">
                                <span class="input-group-text">points</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="loyaltyPointsToUse" class="form-label">Points to Use</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-gift text-warning"></i>
                                </span>
                                <input type="number" class="form-control" id="loyaltyPointsToUse" min="0" step="1" placeholder="0" disabled>
                                <span class="input-group-text">points</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label for="loyaltyPointsValue" class="form-label">Points Value</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                <input type="text" class="form-control" id="loyaltyPointsValue" readonly placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="remainingAmount" class="form-label">Remaining Amount</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                <input type="text" class="form-control" id="remainingAmount" readonly placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Loyalty Points:</strong> 100 points = <?php echo $settings['currency_symbol'] ?? 'KES'; ?>1.00
                        </div>
                    </div>
                </div>

                <!-- Payment Notes -->
                <div class="mb-3">
                    <label for="paymentNotes" class="form-label">Payment Notes (Optional)</label>
                    <textarea class="form-control" id="paymentNotes" rows="2" placeholder="Add any notes about this payment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary payment-btn cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success payment-btn confirm" disabled>
                    <i class="bi bi-check-circle me-1"></i>Confirm Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="receiptModalLabel">
                    <i class="bi bi-check-circle me-2"></i>Payment Successful
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="success-icon mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="text-success">Transaction Completed Successfully!</h4>
                    <p class="text-muted">Your payment has been processed and recorded.</p>
                </div>

                <!-- Receipt Content -->
                <div class="receipt-container bg-white border rounded p-4">
                    <div class="text-center mb-4">
                        <h5 class="fw-bold receipt-shop-name"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h5>
                        <small class="text-muted receipt-shop-address"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></small>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <small class="text-muted">Transaction ID:</small>
                            <div class="fw-bold receipt-transaction-id">--</div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Date:</small>
                            <div class="fw-bold receipt-date">--</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <small class="text-muted">Time:</small>
                            <div class="fw-bold receipt-time">--</div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Payment:</small>
                            <div class="fw-bold receipt-payment-method">--</div>
                        </div>
                    </div>

                    <hr>

                    <div class="receipt-items mb-3">
                        <!-- Items will be populated by JavaScript -->
                    </div>

                    <hr>

                    <div class="row mb-2">
                        <div class="col-6">Subtotal:</div>
                        <div class="col-6 text-end receipt-subtotal"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">Tax (<?php echo $tax_rate; ?>%):</div>
                        <div class="col-6 text-end receipt-tax"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 fw-bold">TOTAL:</div>
                        <div class="col-6 text-end fw-bold receipt-total"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</div>
                    </div>

                    <!-- Cash Payment Details (hidden by default) -->
                    <div id="receiptCashDetails" style="display: none;">
                        <hr>
                        <div class="row mb-2">
                            <div class="col-6">Cash Received:</div>
                            <div class="col-6 text-end receipt-cash-received"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-6">Change Due:</div>
                            <div class="col-6 text-end receipt-change-due"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</div>
                        </div>
                    </div>

                    <div class="text-center mt-4">
                        <small class="text-muted">Thank you for your business!<br>Please keep this receipt for your records</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger receipt-btn cancel" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-outline-primary receipt-btn print">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
                <button type="button" class="btn btn-outline-secondary receipt-btn download">
                    <i class="bi bi-download me-1"></i>Download
                </button>
                <button type="button" class="btn btn-success receipt-btn new-transaction">
                    <i class="bi bi-plus-circle me-1"></i>New Transaction
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Enhanced Payment Modal Styles */
#paymentModal .modal-content {
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

#paymentModal .modal-header {
    border-radius: 15px 15px 0 0;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
}

#paymentModal .form-control-lg {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

#paymentModal .form-control-lg:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
    transform: translateY(-1px);
}

#paymentModal .input-group-text {
    border-radius: 10px 0 0 10px;
    border: 2px solid #e9ecef;
    border-right: none;
    font-weight: 700;
    font-size: 1.1rem;
}

#paymentModal .quick-amount {
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

#paymentModal .quick-amount:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

#paymentModal .quick-amount:active {
    transform: translateY(0);
}

#paymentModal #changeDisplay {
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

#paymentModal #changeDisplay::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    transition: left 0.5s;
}

#paymentModal #changeDisplay.positive::before {
    left: 100%;
}

#paymentModal #changeStatus {
    transition: all 0.3s ease;
}

#paymentModal .btn-outline-primary {
    border-width: 2px;
    font-weight: 600;
}

#paymentModal .btn-outline-success {
    border-width: 2px;
    font-weight: 600;
}

/* Animation for change display */
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

#paymentModal #changeDisplay.positive {
    animation: pulse 0.6s ease-in-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #paymentModal .quick-amount {
        font-size: 0.875rem;
        padding: 0.375rem 0.75rem;
    }
    
    #paymentModal .form-control-lg {
        font-size: 1.1rem;
    }
}
</style>

<script>
// Payment processing configuration
window.POSConfig = {
    currencySymbol: '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>',
    companyName: '<?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>',
    companyAddress: '<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>',
    taxRate: <?php echo $tax_rate; ?>
};

// Cart data for payment processing
window.cartData = <?php echo json_encode($cart_items); ?>;
window.paymentTotals = {
    subtotal: <?php echo $subtotal; ?>,
    tax: <?php echo $tax_amount; ?>,
    total: <?php echo $total_amount; ?>
};
</script>
