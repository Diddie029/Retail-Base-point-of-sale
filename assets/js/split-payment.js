/**
 * Modern Split Payment Module for POS System
 * Handles multiple payment methods for a single transaction
 * 
 * @author POS System
 * @version 1.0.0
 */

class SplitPaymentManager {
    constructor(options = {}) {
        this.totalAmount = 0;
        this.remainingAmount = 0;
        this.payments = [];
        this.currencySymbol = options.currencySymbol || 'KES';
        this.paymentMethods = options.paymentMethods || [];
        this.onUpdate = options.onUpdate || (() => {});
        this.onComplete = options.onComplete || (() => {});
        
        this.init();
    }

    init() {
        this.createSplitPaymentUI();
        this.bindEvents();
        
        // Make this instance globally available for onclick handlers
        window.splitPaymentManager = this;
    }

    /**
     * Set the total amount for the transaction
     */
    setTotalAmount(amount) {
        this.totalAmount = parseFloat(amount) || 0;
        this.remainingAmount = this.totalAmount;
        this.updateDisplay();
    }

    /**
     * Add a payment method to the split
     */
    addPayment(method, amount, additionalData = {}) {
        const paymentAmount = parseFloat(amount) || 0;

        if (paymentAmount <= 0) {
            throw new Error('Payment amount must be greater than 0');
        }

        if (paymentAmount > this.remainingAmount) {
            throw new Error('Payment amount exceeds remaining balance');
        }

        // Special handling for loyalty points
        if (method === 'loyalty_points') {
            if (!additionalData.customer_id) {
                throw new Error('Customer must be selected for loyalty points payment');
            }
            if (!additionalData.points_to_use) {
                throw new Error('Points to use must be specified');
            }
        }

        const payment = {
            id: this.generatePaymentId(),
            method: method,
            amount: paymentAmount,
            ...additionalData
        };

        this.payments.push(payment);
        this.remainingAmount -= paymentAmount;
        this.updateDisplay();
        this.onUpdate(this.getPaymentSummary());

        return payment.id;
    }

    /**
     * Remove a payment from the split
     */
    removePayment(paymentId) {
        const paymentIndex = this.payments.findIndex(p => p.id === paymentId);
        if (paymentIndex === -1) return false;

        const payment = this.payments[paymentIndex];
        this.remainingAmount += payment.amount;
        this.payments.splice(paymentIndex, 1);
        
        this.updateDisplay();
        this.onUpdate(this.getPaymentSummary());
        return true;
    }

    /**
     * Update an existing payment
     */
    updatePayment(paymentId, newAmount, additionalData = {}) {
        const payment = this.payments.find(p => p.id === paymentId);
        if (!payment) return false;

        const newAmountFloat = parseFloat(newAmount) || 0;
        const difference = newAmountFloat - payment.amount;

        if (difference > this.remainingAmount) {
            throw new Error('Updated amount exceeds remaining balance');
        }

        payment.amount = newAmountFloat;
        Object.assign(payment, additionalData);
        this.remainingAmount -= difference;
        
        this.updateDisplay();
        this.onUpdate(this.getPaymentSummary());
        return true;
    }

    /**
     * Get payment summary
     */
    getPaymentSummary() {
        return {
            totalAmount: this.totalAmount,
            remainingAmount: this.remainingAmount,
            paidAmount: this.totalAmount - this.remainingAmount,
            payments: [...this.payments],
            isComplete: this.remainingAmount <= 0.01
        };
    }

    /**
     * Check if split payment is complete
     */
    isComplete() {
        return Math.abs(this.remainingAmount) <= 0.01;
    }

    /**
     * Clear all payments
     */
    clearPayments() {
        this.payments = [];
        this.remainingAmount = this.totalAmount;
        this.updateDisplay();
        this.onUpdate(this.getPaymentSummary());
    }

    /**
     * Generate unique payment ID
     */
    generatePaymentId() {
        return 'payment_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Format amount for display
     */
    formatAmount(amount) {
        return parseFloat(amount).toFixed(2);
    }

    /**
     * Create the split payment UI
     */
    createSplitPaymentUI() {
        const container = document.getElementById('splitPaymentContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="split-payment-manager">
                <div class="split-payment-header mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="payment-summary-card">
                                <h6 class="text-muted mb-1">Total Amount</h6>
                                <div class="h5 mb-0 text-primary" id="splitTotalAmount">
                                    ${this.currencySymbol} 0.00
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="payment-summary-card">
                                <h6 class="text-muted mb-1">Paid Amount</h6>
                                <div class="h5 mb-0 text-success" id="splitPaidAmount">
                                    ${this.currencySymbol} 0.00
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="payment-summary-card">
                                <h6 class="text-muted mb-1">Remaining</h6>
                                <div class="h5 mb-0 text-warning" id="splitRemainingAmount">
                                    ${this.currencySymbol} 0.00
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="split-payment-methods mb-3">
                    <h6 class="fw-bold mb-2">Add Payment Method</h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <select class="form-select" id="splitPaymentMethodSelect">
                                <option value="">Select Payment Method</option>
                                ${this.paymentMethods.map(method =>
                                    `<option value="${method.name}">${method.display_name}</option>`
                                ).join('')}
                            </select>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text">${this.currencySymbol}</span>
                                <input type="number" class="form-control" id="splitPaymentAmount"
                                       placeholder="0.00" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-primary w-100" id="addSplitPaymentBtn">
                                <i class="bi bi-plus-circle me-1"></i>Add Payment
                            </button>
                        </div>
                    </div>

                    <!-- Loyalty Points Section -->
                    <div id="splitLoyaltySection" class="mt-3" style="display: none;">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="bi bi-star-fill me-1"></i>Loyalty Points Payment</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Search Customer</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="bi bi-person"></i>
                                            </span>
                                            <input type="text" class="form-control" id="splitLoyaltyCustomerSearch" 
                                                   placeholder="Search by name, phone, email, or customer number...">
                                            <button class="btn btn-outline-secondary" type="button" id="splitLoyaltyCustomerSearchBtn">
                                                <i class="bi bi-search"></i>
                                            </button>
                                        </div>
                                        <div id="splitLoyaltyCustomerResults" class="mt-2" style="max-height: 200px; overflow-y: auto; display: none;">
                                            <!-- Customer search results will appear here -->
                                        </div>
                                        <div id="splitLoyaltySelectedCustomer" class="mt-2" style="display: none;">
                                            <div class="alert alert-success d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-person-check me-2"></i>
                                                    <strong id="splitLoyaltySelectedCustomerName">Customer Name</strong>
                                                    <br>
                                                    <small id="splitLoyaltySelectedCustomerPoints">0 points available</small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-danger" id="splitLoyaltyClearCustomer">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Available Points</label>
                                        <input type="text" class="form-control" id="splitLoyaltyAvailable" readonly placeholder="0">
                                    </div>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Amount to Use</label>
                                        <div class="input-group">
                                            <span class="input-group-text">${this.currencySymbol}</span>
                                            <input type="number" class="form-control" id="splitLoyaltyAmount"
                                                   placeholder="0.00" min="0" step="0.01">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Points Required</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="splitLoyaltyPointsRequired" readonly placeholder="0">
                                            <span class="input-group-text">points</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-warning" id="addLoyaltyPaymentBtn">
                                        <i class="bi bi-star-fill me-1"></i>Add Loyalty Payment
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" id="cancelLoyaltyBtn">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="split-payment-list">
                    <h6 class="fw-bold mb-2">Payment Breakdown</h6>
                    <div id="splitPaymentsList" class="payment-list-container">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-credit-card fs-1"></i>
                            <p class="mt-2 mb-1">No payments added</p>
                            <small>Add payment methods above to split the total</small>
                        </div>
                    </div>
                </div>

                <!-- Customer Selection for Loyalty Points -->
                <div class="split-payment-customer mt-3">
                    <h6 class="fw-bold mb-3">
                        <i class="bi bi-person-gift me-2"></i>Customer Selection (Optional - for earning loyalty points)
                    </h6>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input type="text" class="form-control" id="splitCustomerSearch" 
                               placeholder="Search customer by name, phone, email, or customer number...">
                        <button class="btn btn-outline-secondary" type="button" id="splitCustomerSearchBtn">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                    <div id="splitCustomerResults" class="mt-2" style="max-height: 200px; overflow-y: auto; display: none;">
                        <!-- Customer search results will appear here -->
                    </div>
                    <div id="splitSelectedCustomer" class="mt-2" style="display: none;">
                        <div class="alert alert-info d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-person-check me-2"></i>
                                <strong id="splitSelectedCustomerName">Customer Name</strong>
                                <br>
                                <small id="splitSelectedCustomerInfo">Customer details</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="splitClearCustomer">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    <div id="splitLoyaltyPointsInfo" class="mt-2" style="display: none;">
                        <div class="alert alert-success">
                            <i class="bi bi-gift me-2"></i>
                            <strong>Loyalty Points:</strong> Customer will earn <span id="splitPointsToEarn">0</span> points from this purchase
                        </div>
                    </div>
                </div>

                <div class="split-payment-actions mt-3">
                    <div class="row">
                        <div class="col-md-12">
                            <button type="button" class="btn btn-outline-secondary w-100" id="clearSplitPaymentsBtn">
                                <i class="bi bi-trash me-1"></i>Clear All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Bind event handlers
     */
    bindEvents() {
        // Add payment button
        document.getElementById('addSplitPaymentBtn')?.addEventListener('click', () => {
            this.handleAddPayment();
        });

        // Clear payments button
        document.getElementById('clearSplitPaymentsBtn')?.addEventListener('click', () => {
            this.clearPayments();
        });


        // Enter key on amount input
        document.getElementById('splitPaymentAmount')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.handleAddPayment();
            }
        });

        // Auto-fill remaining amount on focus
        document.getElementById('splitPaymentAmount')?.addEventListener('focus', (e) => {
            if (!e.target.value && this.remainingAmount > 0) {
                e.target.value = this.formatAmount(this.remainingAmount);
            }
        });

        // Payment method selection change
        document.getElementById('splitPaymentMethodSelect')?.addEventListener('change', (e) => {
            this.handlePaymentMethodChange(e.target.value);
        });

        // Loyalty points events
        document.getElementById('addLoyaltyPaymentBtn')?.addEventListener('click', () => {
            this.handleAddLoyaltyPayment();
        });

        document.getElementById('cancelLoyaltyBtn')?.addEventListener('click', () => {
            this.hideLoyaltySection();
        });

        // Split loyalty customer search functionality
        const splitLoyaltyCustomerSearch = document.getElementById('splitLoyaltyCustomerSearch');
        const splitLoyaltyCustomerSearchBtn = document.getElementById('splitLoyaltyCustomerSearchBtn');
        const splitLoyaltyClearCustomer = document.getElementById('splitLoyaltyClearCustomer');

        if (splitLoyaltyCustomerSearch) {
            let searchTimeout;
            splitLoyaltyCustomerSearch.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                const searchTerm = e.target.value.trim();
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        this.searchSplitLoyaltyCustomers(searchTerm);
                    }, 300);
                } else {
                    document.getElementById('splitLoyaltyCustomerResults').style.display = 'none';
                }
            });
        }

        if (splitLoyaltyCustomerSearchBtn) {
            splitLoyaltyCustomerSearchBtn.addEventListener('click', () => {
                const searchTerm = splitLoyaltyCustomerSearch.value.trim();
                if (searchTerm.length >= 2) {
                    this.searchSplitLoyaltyCustomers(searchTerm);
                }
            });
        }

        if (splitLoyaltyClearCustomer) {
            splitLoyaltyClearCustomer.addEventListener('click', () => {
                this.showSplitLoyaltyCustomerSearch();
                splitLoyaltyCustomerSearch.value = '';
            });
        }

        document.getElementById('splitLoyaltyAmount')?.addEventListener('input', (e) => {
            this.handleLoyaltyAmountInput(e.target.value);
        });

        // Split customer search functionality for earning loyalty points
        const splitCustomerSearch = document.getElementById('splitCustomerSearch');
        const splitCustomerSearchBtn = document.getElementById('splitCustomerSearchBtn');
        const splitClearCustomer = document.getElementById('splitClearCustomer');

        if (splitCustomerSearch) {
            let searchTimeout;
            splitCustomerSearch.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                const searchTerm = e.target.value.trim();
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        this.searchSplitCustomers(searchTerm);
                    }, 300);
                } else {
                    document.getElementById('splitCustomerResults').style.display = 'none';
                }
            });
        }

        if (splitCustomerSearchBtn) {
            splitCustomerSearchBtn.addEventListener('click', () => {
                const searchTerm = splitCustomerSearch.value.trim();
                if (searchTerm.length >= 2) {
                    this.searchSplitCustomers(searchTerm);
                }
            });
        }

        if (splitClearCustomer) {
            splitClearCustomer.addEventListener('click', () => {
                this.clearSplitCustomer();
                splitCustomerSearch.value = '';
            });
        }
    }

    /**
     * Handle payment method selection change
     */
    handlePaymentMethodChange(method) {
        if (method === 'loyalty_points') {
            this.showLoyaltySection();
        } else {
            this.hideLoyaltySection();
        }
    }

    /**
     * Show loyalty points section
     */
    showLoyaltySection() {
        document.getElementById('splitLoyaltySection').style.display = 'block';
        this.showSplitLoyaltyCustomerSearch();
    }

    /**
     * Hide loyalty points section
     */
    hideLoyaltySection() {
        document.getElementById('splitLoyaltySection').style.display = 'none';
        this.resetLoyaltyForm();
    }

    /**
     * Show split loyalty customer search interface
     */
    showSplitLoyaltyCustomerSearch() {
        // Clear any previous selection
        this.splitLoyaltySelectedCustomer = null;
        document.getElementById('splitLoyaltySelectedCustomer').style.display = 'none';
        document.getElementById('splitLoyaltyCustomerResults').style.display = 'none';
        document.getElementById('splitLoyaltyAmount').disabled = true;
        document.getElementById('splitLoyaltyAvailable').value = '0';
        document.getElementById('splitLoyaltyAmount').value = '0.00';
        document.getElementById('splitLoyaltyPointsRequired').value = '0';
    }

    /**
     * Search split loyalty customers
     */
    async searchSplitLoyaltyCustomers(searchTerm) {
        try {
            const response = await fetch(`../api/search_customers_loyalty.php?search=${encodeURIComponent(searchTerm)}`);
            const data = await response.json();

            if (data.success) {
                this.displaySplitLoyaltyCustomerResults(data.customers);
            } else {
                this.showSplitLoyaltyError(data.error || 'Failed to search customers');
            }
        } catch (error) {
            console.error('Error searching customers:', error);
            this.showSplitLoyaltyError('Failed to search customers');
        }
    }

    /**
     * Display split loyalty customer search results
     */
    displaySplitLoyaltyCustomerResults(customers) {
        const resultsDiv = document.getElementById('splitLoyaltyCustomerResults');
        
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="alert alert-info">No customers found</div>';
        } else {
            let html = '<div class="list-group">';
            customers.forEach(customer => {
                html += `
                    <div class="list-group-item list-group-item-action" onclick="window.splitPaymentManager.selectSplitLoyaltyCustomer(${customer.id}, '${customer.display_name}', ${customer.loyalty_points}, '${customer.membership_level}')">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${customer.display_name}</h6>
                            <small class="text-muted">${customer.membership_level}</small>
                        </div>
                        <p class="mb-1 text-muted">${customer.customer_number}</p>
                    </div>
                `;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        }
        
        resultsDiv.style.display = 'block';
    }

    /**
     * Select split loyalty customer
     */
    selectSplitLoyaltyCustomer(customerId, customerName, loyaltyPoints, membershipLevel) {
        this.splitLoyaltySelectedCustomer = {
            id: customerId,
            display_name: customerName,
            loyalty_points: loyaltyPoints,
            membership_level: membershipLevel
        };
        
        // Hide search results
        document.getElementById('splitLoyaltyCustomerResults').style.display = 'none';
        
        // Show selected customer
        document.getElementById('splitLoyaltySelectedCustomerName').textContent = customerName;
        document.getElementById('splitLoyaltySelectedCustomerPoints').textContent = `${loyaltyPoints} points available`;
        document.getElementById('splitLoyaltySelectedCustomer').style.display = 'block';
        
        // Enable amount input and load loyalty data
        document.getElementById('splitLoyaltyAmount').disabled = false;
        this.loadSplitLoyaltyDataForCustomer(customerId);
    }

    /**
     * Load loyalty data for selected customer
     */
    async loadSplitLoyaltyDataForCustomer(customerId) {
        try {
            const response = await fetch(`../api/get_customer_loyalty.php?customer_id=${customerId}`);
            const data = await response.json();

            if (data.success) {
                this.updateSplitLoyaltyDisplay(data.loyalty);
            } else {
                this.showSplitLoyaltyError(data.error || 'Failed to load loyalty information');
            }
        } catch (error) {
            console.error('Error loading loyalty info:', error);
            this.showSplitLoyaltyError('Failed to load loyalty information');
        }
    }

    /**
     * Update split loyalty display
     */
    updateSplitLoyaltyDisplay(loyaltyData) {
        const availablePoints = loyaltyData.balance;
        const pointsValue = loyaltyData.points_value;

        document.getElementById('splitLoyaltyAvailable').value = availablePoints;
        document.getElementById('splitLoyaltyAmount').value = '0.00';
        document.getElementById('splitLoyaltyPointsRequired').value = '0';
        document.getElementById('splitLoyaltyAmount').disabled = false;

        // Add event listener for amount input
        const amountInput = document.getElementById('splitLoyaltyAmount');
        amountInput.removeEventListener('input', this.handleSplitLoyaltyAmountInput);
        amountInput.addEventListener('input', this.handleSplitLoyaltyAmountInput.bind(this));
    }

    /**
     * Handle split loyalty amount input
     */
    handleSplitLoyaltyAmountInput(event) {
        const amountToUse = parseFloat(event.target.value) || 0;
        const availablePoints = parseInt(document.getElementById('splitLoyaltyAvailable').value) || 0;
        
        // Calculate points required (100 points = 1 currency unit)
        const pointsRequired = Math.floor(amountToUse * 100);
        
        // Validate points
        if (pointsRequired > availablePoints) {
            const maxAmount = availablePoints / 100;
            event.target.value = this.formatAmount(maxAmount);
            const adjustedPointsRequired = availablePoints;
            document.getElementById('splitLoyaltyPointsRequired').value = adjustedPointsRequired;
        } else {
            document.getElementById('splitLoyaltyPointsRequired').value = pointsRequired;
        }

        // Update the amount in the main form
        document.getElementById('splitPaymentAmount').value = this.formatAmount(amountToUse);
    }

    /**
     * Show split loyalty error
     */
    showSplitLoyaltyError(message) {
        const resultsDiv = document.getElementById('splitLoyaltyCustomerResults');
        resultsDiv.innerHTML = `<div class="alert alert-danger">${message}</div>`;
        resultsDiv.style.display = 'block';
    }


    /**
     * Handle loyalty amount input
     */
    handleLoyaltyAmountInput(amount) {
        const amountToUse = parseFloat(amount) || 0;
        const pointsRequired = Math.floor(amountToUse * 100); // 100 points = 1 currency unit

        document.getElementById('splitLoyaltyPointsRequired').value = pointsRequired;

        // Update the amount in the main form
        document.getElementById('splitPaymentAmount').value = this.formatAmount(amountToUse);
    }

    /**
     * Handle adding loyalty payment
     */
    async handleAddLoyaltyPayment() {
        if (!this.splitLoyaltySelectedCustomer) {
            this.showError('Please select a customer');
            return;
        }

        const amountToUse = parseFloat(document.getElementById('splitLoyaltyAmount').value) || 0;
        const pointsRequired = parseInt(document.getElementById('splitLoyaltyPointsRequired').value) || 0;

        if (amountToUse <= 0) {
            this.showError('Please enter an amount to use');
            return;
        }

        if (pointsRequired <= 0) {
            this.showError('Invalid amount - no points required');
            return;
        }

        // Get the add loyalty payment button
        const addLoyaltyBtn = document.getElementById('addLoyaltyPaymentBtn');
        if (!addLoyaltyBtn) return;

        // Check if already processing to prevent duplicates
        if (addLoyaltyBtn.disabled) {
            return;
        }

        // Set loading state
        const originalText = addLoyaltyBtn.innerHTML;
        addLoyaltyBtn.disabled = true;
        addLoyaltyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding...';

        try {
            await this.addLoyaltyPointsPayment(this.splitLoyaltySelectedCustomer.id, pointsRequired);
            this.hideLoyaltySection();
            this.resetPaymentForm();
            this.showSuccess('Loyalty points payment added successfully');
        } catch (error) {
            this.showError(error.message);
        } finally {
            // Reset button state
            addLoyaltyBtn.disabled = false;
            addLoyaltyBtn.innerHTML = originalText;
        }
    }

    /**
     * Reset loyalty form
     */
    resetLoyaltyForm() {
        document.getElementById('splitLoyaltyCustomerSearch').value = '';
        document.getElementById('splitLoyaltyAvailable').value = '';
        document.getElementById('splitLoyaltyAmount').value = '0.00';
        document.getElementById('splitLoyaltyPointsRequired').value = '0';
        document.getElementById('splitLoyaltyAmount').disabled = true;
        this.showSplitLoyaltyCustomerSearch();
    }

    /**
     * Reset payment form
     */
    resetPaymentForm() {
        document.getElementById('splitPaymentMethodSelect').value = '';
        document.getElementById('splitPaymentAmount').value = '';
    }

    /**
     * Handle adding a new payment
     */
    handleAddPayment() {
        const methodSelect = document.getElementById('splitPaymentMethodSelect');
        const amountInput = document.getElementById('splitPaymentAmount');

        const method = methodSelect.value;
        const amount = parseFloat(amountInput.value) || 0;

        if (!method) {
            this.showError('Please select a payment method');
            return;
        }

        if (method === 'loyalty_points') {
            this.showError('Please use the loyalty points section below');
            return;
        }

        if (amount <= 0) {
            this.showError('Please enter a valid amount');
            return;
        }

        if (amount > this.remainingAmount) {
            this.showError('Amount exceeds remaining balance');
            return;
        }

        // Get the add payment button
        const addPaymentBtn = document.getElementById('addSplitPaymentBtn');
        if (!addPaymentBtn) return;

        // Check if already processing to prevent duplicates
        if (addPaymentBtn.disabled) {
            return;
        }

        // Set loading state
        const originalText = addPaymentBtn.innerHTML;
        addPaymentBtn.disabled = true;
        addPaymentBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding...';

        try {
            this.addPayment(method, amount);

            // Reset form
            this.resetPaymentForm();

            this.showSuccess('Payment method added successfully');
        } catch (error) {
            this.showError(error.message);
        } finally {
            // Reset button state
            addPaymentBtn.disabled = false;
            addPaymentBtn.innerHTML = originalText;
        }
    }

    /**
     * Update the display with current payment state
     */
    updateDisplay() {
        // Update summary amounts
        document.getElementById('splitTotalAmount').textContent =
            `${this.currencySymbol} ${this.formatAmount(this.totalAmount)}`;

        document.getElementById('splitPaidAmount').textContent =
            `${this.currencySymbol} ${this.formatAmount(this.totalAmount - this.remainingAmount)}`;

        document.getElementById('splitRemainingAmount').textContent =
            `${this.currencySymbol} ${this.formatAmount(this.remainingAmount)}`;

        // Update remaining amount color
        const remainingEl = document.getElementById('splitRemainingAmount');
        if (this.remainingAmount <= 0.01) {
            remainingEl.className = 'h5 mb-0 text-success';
        } else {
            remainingEl.className = 'h5 mb-0 text-warning';
        }

        // Update payments list
        this.updatePaymentsList();

        // Update loyalty points earning calculation
        this.updateSplitLoyaltyPointsEarning();

    }

    /**
     * Update the payments list display
     */
    updatePaymentsList() {
        const container = document.getElementById('splitPaymentsList');
        if (!container) return;

        if (this.payments.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-credit-card fs-1"></i>
                    <p class="mt-2 mb-1">No payments added</p>
                    <small>Add payment methods above to split the total</small>
                </div>
            `;
            return;
        }

        const paymentsHtml = this.payments.map(payment => {
            const methodInfo = this.paymentMethods.find(m => m.name === payment.method);
            const methodName = methodInfo ? methodInfo.display_name : payment.method;
            const methodIcon = methodInfo ? methodInfo.icon : 'bi-credit-card';
            const methodColor = methodInfo ? methodInfo.color : '#6c757d';

            return `
                <div class="payment-item card mb-2" data-payment-id="${payment.id}">
                    <div class="card-body p-3">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="payment-method-icon rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 40px; height: 40px; background: ${methodColor};">
                                    <i class="${methodIcon} text-white"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h6 class="mb-1">${methodName}</h6>
                                <small class="text-muted">Payment ${this.payments.indexOf(payment) + 1}</small>
                            </div>
                            <div class="col-auto">
                                <div class="text-end">
                                    <div class="h6 mb-1">${this.currencySymbol} ${this.formatAmount(payment.amount)}</div>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-payment-btn"
                                            data-payment-id="${payment.id}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        container.innerHTML = paymentsHtml;

        // Bind remove buttons
        container.querySelectorAll('.remove-payment-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const paymentId = e.target.closest('.remove-payment-btn').dataset.paymentId;
                this.removePayment(paymentId);
            });
        });
    }

    /**
     * Show error message
     */
    showError(message) {
        this.showNotification(message, 'danger');
    }

    /**
     * Show success message
     */
    showSuccess(message) {
        this.showNotification(message, 'success');
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);

        // Auto remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }

    /**
     * Add loyalty points payment with customer validation
     */
    async addLoyaltyPointsPayment(customerId, pointsToUse) {
        try {
            // Validate customer and points
            const loyaltyData = await this.validateLoyaltyPoints(customerId, pointsToUse);

            if (!loyaltyData.valid) {
                throw new Error(loyaltyData.error);
            }

            // Add the loyalty payment
            return this.addPayment('loyalty_points', loyaltyData.pointsValue, {
                customer_id: customerId,
                points_to_use: pointsToUse,
                points_value: loyaltyData.pointsValue,
                customer_name: loyaltyData.customer.display_name
            });
        } catch (error) {
            throw new Error('Failed to add loyalty points payment: ' + error.message);
        }
    }

    /**
     * Validate loyalty points for a customer
     */
    async validateLoyaltyPoints(customerId, pointsToUse) {
        try {
            const response = await fetch('../include/get_customer_loyalty.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    customer_id: customerId,
                    points_to_use: pointsToUse
                })
            });

            const data = await response.json();

            if (!data.success) {
                return { valid: false, error: data.error || 'Failed to validate loyalty points' };
            }

            const availablePoints = data.balance;
            if (pointsToUse > availablePoints) {
                return { valid: false, error: 'Insufficient loyalty points' };
            }

            // Calculate points value (100 points = 1 currency unit)
            const pointsValue = pointsToUse / 100;

            if (pointsValue > this.remainingAmount) {
                return { valid: false, error: 'Points value exceeds remaining balance' };
            }

            return {
                valid: true,
                pointsValue: pointsValue,
                customer: data.customer,
                availablePoints: availablePoints
            };
        } catch (error) {
            return { valid: false, error: 'Network error validating loyalty points' };
        }
    }

    /**
     * Get payment data for submission
     */
    getPaymentData() {
        if (!this.isComplete()) {
            throw new Error('Payment is not complete');
        }

        return {
            is_split_payment: true,
            split_payments: this.payments.map(payment => ({
                method: payment.method,
                amount: payment.amount,
                cash_received: payment.cash_received || null,
                change_due: payment.change_due || null,
                // Loyalty points specific data
                points_to_use: payment.points_to_use || null,
                customer_id: payment.customer_id || null
            })),
            total_amount: this.totalAmount,
            // Customer for earning loyalty points
            customer_id: this.splitSelectedCustomer ? this.splitSelectedCustomer.id : null,
            customer_name: this.splitSelectedCustomer ? this.splitSelectedCustomer.display_name : 'Walk-in Customer',
            customer_type: this.splitSelectedCustomer ? 'registered' : 'walk_in',
            membership_level: this.splitSelectedCustomer ? this.splitSelectedCustomer.membership_level : 'Basic',
            // Calculate non-redeemed amount for loyalty points earning
            loyalty_eligible_amount: this.calculateNonRedeemedAmount()
        };
    }

    /**
     * Search customers for earning loyalty points
     */
    async searchSplitCustomers(searchTerm) {
        try {
            const response = await fetch(`../api/search_customers_loyalty.php?search=${encodeURIComponent(searchTerm)}`);
            const data = await response.json();

            if (data.success) {
                this.displaySplitCustomerResults(data.customers);
            } else {
                this.showSplitCustomerError(data.error || 'Failed to search customers');
            }
        } catch (error) {
            console.error('Error searching customers:', error);
            this.showSplitCustomerError('Failed to search customers');
        }
    }

    /**
     * Display split customer search results
     */
    displaySplitCustomerResults(customers) {
        const resultsDiv = document.getElementById('splitCustomerResults');
        
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="alert alert-info">No customers found</div>';
        } else {
            let html = '<div class="list-group">';
            customers.forEach(customer => {
                html += `
                    <div class="list-group-item list-group-item-action" onclick="window.splitPaymentManager.selectSplitCustomer(${customer.id}, '${customer.display_name}', '${customer.membership_level}', '${customer.customer_number}')">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${customer.display_name}</h6>
                            <small class="text-muted">${customer.membership_level}</small>
                        </div>
                        <p class="mb-1 text-muted">${customer.customer_number}</p>
                    </div>
                `;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        }
        
        resultsDiv.style.display = 'block';
    }

    /**
     * Select split customer for earning loyalty points
     */
    selectSplitCustomer(customerId, customerName, membershipLevel, customerNumber) {
        this.splitSelectedCustomer = {
            id: customerId,
            display_name: customerName,
            membership_level: membershipLevel,
            customer_number: customerNumber
        };
        
        // Hide search results
        document.getElementById('splitCustomerResults').style.display = 'none';
        
        // Show selected customer
        document.getElementById('splitSelectedCustomerName').textContent = customerName;
        document.getElementById('splitSelectedCustomerInfo').textContent = `${customerNumber} • ${membershipLevel}`;
        document.getElementById('splitSelectedCustomer').style.display = 'block';
        
        // Update loyalty points calculation
        this.updateSplitLoyaltyPointsEarning();
    }

    /**
     * Clear split customer selection
     */
    clearSplitCustomer() {
        this.splitSelectedCustomer = null;
        document.getElementById('splitSelectedCustomer').style.display = 'none';
        document.getElementById('splitCustomerResults').style.display = 'none';
        document.getElementById('splitLoyaltyPointsInfo').style.display = 'none';
    }

    /**
     * Update loyalty points earning calculation
     */
    updateSplitLoyaltyPointsEarning() {
        if (this.splitSelectedCustomer) {
            // Calculate points that would be earned from non-redeemed amount
            const nonRedeemedAmount = this.calculateNonRedeemedAmount();
            const pointsToEarn = this.calculateLoyaltyPointsForAmount(nonRedeemedAmount, this.splitSelectedCustomer.membership_level);
            
            document.getElementById('splitPointsToEarn').textContent = pointsToEarn;
            document.getElementById('splitLoyaltyPointsInfo').style.display = 'block';
        } else {
            document.getElementById('splitLoyaltyPointsInfo').style.display = 'none';
        }
    }

    /**
     * Calculate non-redeemed amount (total minus loyalty point redemptions)
     */
    calculateNonRedeemedAmount() {
        let redeemedAmount = 0;
        this.payments.forEach(payment => {
            if (payment.method === 'loyalty_points') {
                redeemedAmount += payment.amount;
            }
        });
        return Math.max(0, this.totalAmount - redeemedAmount);
    }

    /**
     * Calculate loyalty points for amount based on membership level
     */
    calculateLoyaltyPointsForAmount(amount, membershipLevel) {
        const basePointsPerDollar = 1; // Default rate
        const multiplier = this.getMembershipMultiplier(membershipLevel);
        return Math.floor(amount * basePointsPerDollar * multiplier);
    }

    /**
     * Get membership multiplier
     */
    getMembershipMultiplier(level) {
        const multipliers = {
            'Basic': 1.0,
            'Bronze': 1.2,
            'Silver': 1.5,
            'Gold': 2.0,
            'Platinum': 2.5,
            'Diamond': 3.0
        };
        return multipliers[level] || 1.0;
    }

    /**
     * Show split customer error
     */
    showSplitCustomerError(message) {
        const resultsDiv = document.getElementById('splitCustomerResults');
        resultsDiv.innerHTML = `<div class="alert alert-danger">${message}</div>`;
        resultsDiv.style.display = 'block';
    }

    /**
     * Reset the split payment manager
     */
    reset() {
        this.totalAmount = 0;
        this.remainingAmount = 0;
        this.payments = [];
        this.splitSelectedCustomer = null;
        this.updateDisplay();
    }
}

// Export for use in other modules
window.SplitPaymentManager = SplitPaymentManager;
