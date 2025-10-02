/**
 * Enhanced Payment Processing System for POS
 * Handles multiple payment methods, validation, and receipt generation
 */

class PaymentProcessor {
    constructor() {
        this.selectedPaymentMethod = null;
        this.paymentAmount = 0;
        this.transactionData = null;
        this.currency = '$';
        this.taxRate = 0;
        this.cashSelectedCustomer = null;
        this.loyaltySelectedCustomer = null;
        // Reference to a pre-opened print window (opened synchronously on user gesture)
        this._preopenedPrintWindow = null;
        
        this.init();
    }

    init() {
        this.currency = window.POSConfig?.currencySymbol || '$';
        this.taxRate = window.POSConfig?.taxRate || 16;
        this.paymentAmount = window.paymentTotals?.total || 0;
        this.transactionData = window.cartData || [];
        
        this.bindEvents();
        this.setupPaymentMethods();
        this.updatePaymentSummary();
        this.setDefaultPaymentMethod();
    }

    // Method to refresh cart data from current session
    refreshCartData() {
        // Get current cart data from the page
        this.transactionData = window.cartData || [];
        
        // Recalculate totals
        let subtotal = 0;
        if (this.transactionData && this.transactionData.length > 0) {
            this.transactionData.forEach(item => {
                subtotal += parseFloat(item.price) * parseInt(item.quantity);
            });
        }
        const tax = subtotal * (this.taxRate / 100);
        const total = subtotal + tax;
        
        this.paymentAmount = total;
        
        // Update payment summary
        this.updatePaymentSummary();
        
        // Update confirm button state
        this.updateConfirmButton();
    }

    bindEvents() {
        // Payment method selection
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', (e) => {
                this.selectPaymentMethod(e.currentTarget);
            });
        });

        // Listen for payment modal show event
        const paymentModal = document.getElementById('paymentModal');
        if (paymentModal) {
            paymentModal.addEventListener('show.bs.modal', () => {
                this.refreshCartData();
                this.bindQuickAmountButtons();
            });
        }

        // Loyalty customer search functionality
        const loyaltyCustomerSearch = document.getElementById('loyaltyCustomerSearch');
        const loyaltyCustomerSearchBtn = document.getElementById('loyaltyCustomerSearchBtn');
        const loyaltyClearCustomer = document.getElementById('loyaltyClearCustomer');

        if (loyaltyCustomerSearch) {
            let searchTimeout;
            loyaltyCustomerSearch.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                const searchTerm = e.target.value.trim();
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        this.searchLoyaltyCustomers(searchTerm);
                    }, 300);
                } else {
                    document.getElementById('loyaltyCustomerResults').style.display = 'none';
                }
            });
        }

        if (loyaltyCustomerSearchBtn) {
            loyaltyCustomerSearchBtn.addEventListener('click', () => {
                const searchTerm = loyaltyCustomerSearch.value.trim();
                if (searchTerm.length >= 2) {
                    this.searchLoyaltyCustomers(searchTerm);
                }
            });
        }

        if (loyaltyClearCustomer) {
            loyaltyClearCustomer.addEventListener('click', () => {
                this.showLoyaltyCustomerSearch();
                loyaltyCustomerSearch.value = '';
            });
        }

        // Cash customer search functionality
        const cashCustomerSearch = document.getElementById('cashCustomerSearch');
        const cashCustomerSearchBtn = document.getElementById('cashCustomerSearchBtn');
        const cashClearCustomer = document.getElementById('cashClearCustomer');

        if (cashCustomerSearch) {
            let searchTimeout;
            cashCustomerSearch.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                const searchTerm = e.target.value.trim();
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        this.searchCashCustomers(searchTerm);
                    }, 300);
                } else {
                    document.getElementById('cashCustomerResults').style.display = 'none';
                }
            });
        }

        if (cashCustomerSearchBtn) {
            cashCustomerSearchBtn.addEventListener('click', () => {
                const searchTerm = cashCustomerSearch.value.trim();
                if (searchTerm.length >= 2) {
                    this.searchCashCustomers(searchTerm);
                }
            });
        }

        if (cashClearCustomer) {
            cashClearCustomer.addEventListener('click', () => {
                this.showCashCustomerSearch();
                cashCustomerSearch.value = '';
                this.cashSelectedCustomer = null;
                this.updateCashLoyaltyPoints();
            });
        }

        // Collapsible button for other payment methods
        const collapseBtn = document.querySelector('[data-bs-target="#otherPaymentMethods"]');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', (e) => {
                const icon = e.currentTarget.querySelector('i');
                if (e.currentTarget.getAttribute('aria-expanded') === 'true') {
                    icon.className = 'bi bi-chevron-down me-1';
                } else {
                    icon.className = 'bi bi-chevron-up me-1';
                }
            });
        }

        // Cash input events
        const cashInput = document.getElementById('cashReceived');
        if (cashInput) {
            cashInput.addEventListener('input', () => {
                this.calculateChange();
                this.updateCashLoyaltyPoints();
            });
            cashInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.confirmPayment();
                }
            });
        }

        // Bind quick amount buttons
        this.bindQuickAmountButtons();

        // Card number formatting
        const cardNumber = document.getElementById('cardNumber');
        if (cardNumber) {
            cardNumber.addEventListener('input', (e) => this.formatCardNumber(e));
        }

        // Card expiry formatting
        const cardExpiry = document.getElementById('cardExpiry');
        if (cardExpiry) {
            cardExpiry.addEventListener('input', (e) => this.formatCardExpiry(e));
        }

        // Payment buttons
        const cancelBtn = document.querySelector('.payment-btn.cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.cancelPayment());
        }

        const confirmBtn = document.querySelector('.payment-btn.confirm');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => this.confirmPayment());
        }

        // Receipt buttons
        this.bindReceiptEvents();
    }

    bindReceiptEvents() {
        const printBtn = document.querySelector('.receipt-btn.print');
        if (printBtn) {
            printBtn.addEventListener('click', () => this.printReceipt());
        }


        const newTransactionBtn = document.querySelector('.receipt-btn.new-transaction');
        if (newTransactionBtn) {
            newTransactionBtn.addEventListener('click', () => this.startNewTransaction());
        }

        const cancelReceiptBtn = document.querySelector('.receipt-btn.cancel');
        if (cancelReceiptBtn) {
            cancelReceiptBtn.addEventListener('click', () => this.cancelReceipt());
        }
    }

    setupPaymentMethods() {
        // Add hover effects and selection styling
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('mouseenter', () => {
                if (!method.classList.contains('selected')) {
                    method.style.transform = 'translateY(-2px)';
                    method.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                }
            });

            method.addEventListener('mouseleave', () => {
                if (!method.classList.contains('selected')) {
                    method.style.transform = 'translateY(0)';
                    method.style.boxShadow = '';
                }
            });
        });
    }

    setDefaultPaymentMethod() {
        // Auto-select cash payment method
        const cashMethod = document.querySelector('.payment-method[data-method="cash"]');
        if (cashMethod) {
            this.selectedPaymentMethod = 'cash';
            this.showPaymentSection('cash');
            this.updateConfirmButton();
            
            // Focus on cash input after a short delay
            setTimeout(() => {
                const cashInput = document.getElementById('cashReceived');
                if (cashInput) {
                    cashInput.focus();
                }
            }, 300);
        }
    }

    updatePaymentSummary() {
        // Use requestIdleCallback for better performance
        const updateSummary = () => {
            // Calculate cart totals from current cart data
            let subtotal = 0;
            let itemCount = 0;
            
            if (this.transactionData && this.transactionData.length > 0) {
                this.transactionData.forEach(item => {
                    subtotal += parseFloat(item.price) * parseInt(item.quantity);
                    itemCount += parseInt(item.quantity);
                });
            }
            
            const tax = subtotal * (this.taxRate / 100);
            const total = subtotal + tax;
            
            // Update payment totals
            this.paymentAmount = total;
            window.paymentTotals = { subtotal, tax, total };
            
            // Cache DOM elements for better performance
            if (!this._summaryElements) {
                this._summaryElements = {
                    subtotal: document.getElementById('paymentSubtotal'),
                    tax: document.getElementById('paymentTax'),
                    total: document.getElementById('paymentTotal'),
                    itemCount: document.getElementById('paymentItemCount'),
                    items: document.getElementById('paymentItems')
                };
            }
            
            const elements = this._summaryElements;
            
            // Batch DOM updates
            if (elements.subtotal) elements.subtotal.textContent = this.formatAmount(subtotal);
            if (elements.tax) elements.tax.textContent = this.formatAmount(tax);
            if (elements.total) elements.total.textContent = this.formatAmount(total);
            if (elements.itemCount) elements.itemCount.textContent = this.transactionData.length;

            // Update items display
            if (elements.items && this.transactionData.length > 0) {
            // Calculate total quantity of all products
            const totalQuantity = this.transactionData.reduce((sum, item) => sum + item.quantity, 0);
            const productCount = this.transactionData.length;
            
            let itemsHtml = '';
            
            // Show product summary
            itemsHtml += `
                <div class="text-center py-3">
                    <div class="d-flex align-items-center justify-content-center mb-2">
                        <i class="bi bi-cart-check fs-2 text-success me-2"></i>
                        <div>
                            <h6 class="mb-0 fw-bold">${productCount} Product${productCount !== 1 ? 's' : ''}</h6>
                            <small class="text-muted">${totalQuantity} item${totalQuantity !== 1 ? 's' : ''} total</small>
                        </div>
                    </div>
                    <div class="border-top pt-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="togglePaymentItemsDetails()">
                            <i class="bi bi-list-ul me-1"></i>View Details
                        </button>
                    </div>
                </div>
                <div id="paymentItemsDetails" style="display: none;">
                    ${this.generateDetailedItemsHtml()}
                </div>
            `;
            
                elements.items.innerHTML = itemsHtml;
            } else if (elements.items) {
                elements.items.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x fs-1"></i>
                        <p class="mt-2 mb-1">No items in cart</p>
                        <small>Add products to get started</small>
                    </div>
                `;
            }
        };

        // Use requestIdleCallback with timeout for better performance
        if (window.requestIdleCallback) {
            requestIdleCallback(updateSummary, { timeout: 50 });
        } else {
            requestAnimationFrame(updateSummary);
        }
    }

    /**
     * Generate detailed items HTML for payment modal
     */
    generateDetailedItemsHtml() {
        if (!this.transactionData || this.transactionData.length === 0) {
            return '<div class="text-center text-muted py-2">No items</div>';
        }

        let itemsHtml = '';
        this.transactionData.forEach(item => {
            const itemTotal = item.price * item.quantity;
            itemsHtml += `
                <div class="d-flex justify-content-between py-1 border-bottom">
                    <div>
                        <small class="fw-bold">${item.name}</small>
                        <br>
                        <small class="text-muted">${item.quantity} × ${this.currency}${this.formatAmount(item.price)}</small>
                    </div>
                    <div class="text-end">
                        <small class="fw-bold">${this.currency}${this.formatAmount(itemTotal)}</small>
                    </div>
                </div>
            `;
        });
        return itemsHtml;
    }

    selectPaymentMethod(methodElement) {
        // Remove previous selection
        document.querySelectorAll('.payment-method').forEach(m => {
            m.classList.remove('selected');
            m.style.transform = 'translateY(0)';
            m.style.boxShadow = '';
            m.style.borderColor = '';
        });

        // Select new method
        methodElement.classList.add('selected');
        methodElement.style.transform = 'translateY(-2px)';
        methodElement.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
        methodElement.style.borderColor = '#007bff';

        this.selectedPaymentMethod = methodElement.dataset.method;
        this.showPaymentSection(this.selectedPaymentMethod);
        this.updateConfirmButton();

        // If selecting a method that's in the collapsible section, expand it
        if (this.selectedPaymentMethod !== 'cash' && this.selectedPaymentMethod !== 'loyalty_points') {
            const collapseElement = document.getElementById('otherPaymentMethods');
            const collapseBtn = document.querySelector('[data-bs-target="#otherPaymentMethods"]');
            if (collapseElement && collapseBtn) {
                const bsCollapse = new bootstrap.Collapse(collapseElement, { show: true });
                const icon = collapseBtn.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-chevron-up me-1';
                }
                collapseBtn.setAttribute('aria-expanded', 'true');
            }
        }
    }

    showPaymentSection(method) {
        // Check if split payment is selected
        const splitPaymentRadio = document.getElementById('splitPayment');
        const isSplitPayment = splitPaymentRadio && splitPaymentRadio.checked;
        
        // If split payment is selected, don't show single payment sections
        if (isSplitPayment) {
            return;
        }

        // Hide all payment sections
        document.getElementById('cashPaymentSection').style.display = 'none';
        document.getElementById('mobileMoneySection').style.display = 'none';
        document.getElementById('cardPaymentSection').style.display = 'none';
        document.getElementById('loyaltyPointsPaymentSection').style.display = 'none';
        const refundSec = document.getElementById('refundReceiptPaymentSection');
        if (refundSec) refundSec.style.display = 'none';

        // Show relevant section
        switch (method) {
            case 'cash':
                document.getElementById('cashPaymentSection').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('cashReceived').focus();
                }, 100);
                break;
            case 'mobile_money':
                document.getElementById('mobileMoneySection').style.display = 'block';
                break;
            case 'credit_card':
            case 'debit_card':
            case 'pos_card':
                document.getElementById('cardPaymentSection').style.display = 'block';
                break;
            case 'loyalty_points':
                const loyaltySection = document.getElementById('loyaltyPointsPaymentSection');
                if (loyaltySection) {
                    loyaltySection.style.display = 'block';
                    this.loadCustomerLoyaltyInfo();
                    setTimeout(() => {
                        const pointsInput = document.getElementById('loyaltyPointsToUse');
                        if (pointsInput) pointsInput.focus();
                    }, 100);
                }
                break;
            case 'refund_receipt':
                // Show store credit/refund receipt section in single-payment mode
                const refundSection = document.getElementById('refundReceiptPaymentSection');
                if (refundSection) {
                    refundSection.style.display = 'block';
                    setTimeout(() => {
                        const receiptInput = document.getElementById('refundReceiptNumber');
                        if (receiptInput) {
                            receiptInput.focus();
                            // Do not select automatically to avoid accidental overwrite after validation
                        }
                    }, 100);
                }
                break;
        }
    }

    calculateChange() {
        const cashInput = document.getElementById('cashReceived');
        const changeDisplay = document.getElementById('changeDisplay');
        const changeAmount = document.getElementById('changeAmount');
        
        if (!cashInput || !changeDisplay || !changeAmount) return;

        const cashReceived = parseFloat(cashInput.value) || 0;
        const change = cashReceived - this.paymentAmount;

        // Update change display
        changeAmount.textContent = `${this.currency}${this.formatAmount(Math.abs(change))}`;

        // Update styling
        changeDisplay.classList.remove('positive', 'negative');
        if (change >= 0) {
            changeDisplay.classList.add('positive');
            changeAmount.style.color = '#10b981';
        } else {
            changeDisplay.classList.add('negative');
            changeAmount.style.color = '#ef4444';
            changeAmount.textContent = `${this.currency}${this.formatAmount(Math.abs(change))} insufficient`;
        }

        this.updateConfirmButton();
    }

    formatCardNumber(e) {
        let value = e.target.value.replace(/\s/g, '').replace(/[^0-9]/gi, '');
        let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
        e.target.value = formattedValue;
    }

    formatCardExpiry(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length >= 2) {
            value = value.substring(0, 2) + '/' + value.substring(2, 4);
        }
        e.target.value = value;
    }

    updateConfirmButton() {
        const confirmBtn = document.querySelector('.payment-btn.confirm');
        if (!confirmBtn) return;

        let canConfirm = this.selectedPaymentMethod !== null;

        // Validate specific payment methods
        if (this.selectedPaymentMethod === 'cash') {
            const cashInput = document.getElementById('cashReceived');
            const cashReceived = parseFloat(cashInput?.value) || 0;
            canConfirm = cashReceived >= this.paymentAmount && this.paymentAmount > 0;
        } else if (this.selectedPaymentMethod === 'mobile_money') {
            const mobileNumber = document.getElementById('mobileNumber').value;
            const provider = document.getElementById('mobileProvider').value;
            canConfirm = mobileNumber.length >= 10 && provider !== '' && this.paymentAmount > 0;
        } else if (['credit_card', 'debit_card', 'pos_card'].includes(this.selectedPaymentMethod)) {
            const cardNumber = document.getElementById('cardNumber').value;
            const cardExpiry = document.getElementById('cardExpiry').value;
            const cardCVV = document.getElementById('cardCVV').value;
            canConfirm = cardNumber.length >= 16 && cardExpiry.length === 5 && cardCVV.length >= 3 && this.paymentAmount > 0;
        } else if (this.selectedPaymentMethod === 'loyalty_points') {
            const loyaltyValidation = this.validateLoyaltyPayment();
            canConfirm = loyaltyValidation.valid && this.paymentAmount > 0;
        } else if (this.selectedPaymentMethod === 'refund_receipt') {
            const refundValidation = this.validateRefundReceiptPayment();
            canConfirm = refundValidation.valid && this.paymentAmount > 0;
        }

        confirmBtn.disabled = !canConfirm;
    }

    async confirmPayment() {
        if (!this.selectedPaymentMethod) return;

        const confirmBtn = document.querySelector('.payment-btn.confirm');
        if (confirmBtn.disabled) return;

        // Show loading state
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';

        try {
            // If auto-print is enabled, pre-open a (blank) print window now while still in
            // the user click handler so popup blockers allow it. We'll navigate it later
            // when the receipt is ready.
            if (window.autoPrintReceipt) {
                try {
                    // Use a consistent name so subsequent attempts reuse the same window/tab
                    this._preopenedPrintWindow = window.open('', 'pos_print_window');
                    if (this._preopenedPrintWindow) {
                        // Provide minimal feedback while processing
                        try {
                            this._preopenedPrintWindow.document.write('<html><head><title>Printing...</title></head><body><p>Preparing receipt for printing...</p></body></html>');
                        } catch (e) {
                            // ignore write errors (some browsers may restrict document write)
                        }
                    }
                } catch (e) {
                    console.warn('Could not pre-open print window', e);
                    this._preopenedPrintWindow = null;
                }
            }

            const paymentData = await this.processPayment();
            
            if (paymentData.success) {
                this.showReceipt(paymentData);
            } else {
                this.showError(paymentData.error || 'Payment processing failed');
            }
        } catch (error) {
            console.error('Payment error:', error);
            // Show the actual error message from the server
            this.showError(error.message || 'Payment processing failed. Please try again.');
        } finally {
            // Reset button
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm Payment';
        }
    }

    async processPayment() {
        // Check if this is a split payment
        const splitPaymentRadio = document.getElementById('splitPayment');
        const isSplitPayment = splitPaymentRadio && splitPaymentRadio.checked;

        if (isSplitPayment) {
            // Split payment validation is handled by the split payment manager
            // This should not be called for split payments
            throw new Error('Split payments should be processed through the split payment manager');
        }

        // Validate single payment method selection
        if (!this.selectedPaymentMethod) {
            throw new Error('Please select a payment method');
        }

        // Validate payment method specific requirements
        if (this.selectedPaymentMethod === 'cash') {
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            if (cashReceived < this.paymentAmount) {
                throw new Error('Cash received is less than the total amount');
            }
        } else if (this.selectedPaymentMethod === 'mobile_money') {
            const mobileNumber = document.getElementById('mobileNumber').value;
            const mobileProvider = document.getElementById('mobileProvider').value;
            if (!mobileNumber || !mobileProvider) {
                throw new Error('Please provide mobile number and provider');
            }
        } else if (['credit_card', 'debit_card', 'pos_card'].includes(this.selectedPaymentMethod)) {
            const cardNumber = document.getElementById('cardNumber').value;
            if (!cardNumber || cardNumber.length < 4) {
                throw new Error('Please provide valid card information');
            }
        } else if (this.selectedPaymentMethod === 'loyalty_points') {
            const loyaltyValidation = this.validateLoyaltyPayment();
            if (!loyaltyValidation.valid) {
                throw new Error(loyaltyValidation.error);
            }
        } else if (this.selectedPaymentMethod === 'refund_receipt') {
            const refundReceiptValidation = this.validateRefundReceiptPayment();
            if (!refundReceiptValidation.valid) {
                throw new Error(refundReceiptValidation.error);
            }
        }

        // Calculate totals from current cart data
        let subtotal = 0;
        if (this.transactionData && this.transactionData.length > 0) {
            this.transactionData.forEach(item => {
                subtotal += parseFloat(item.price) * parseInt(item.quantity);
            });
        }
        const tax = subtotal * (this.taxRate / 100);
        const total = subtotal + tax;

        // Get selected customer data
        let selectedCustomer = window.selectedCustomer || null;
        
        // For cash payments, use the cash selected customer if available
        if (this.selectedPaymentMethod === 'cash' && this.cashSelectedCustomer) {
            selectedCustomer = {
                id: this.cashSelectedCustomer.id,
                display_name: this.cashSelectedCustomer.display_name,
                customer_type: 'registered',
                membership_level: this.cashSelectedCustomer.membership_level
            };
        }
        
        const paymentData = {
            method: this.selectedPaymentMethod,
            amount: total,
            subtotal: subtotal,
            tax: tax,
            items: this.transactionData,
            quotation_id: window.currentQuotationId || null,
            timestamp: new Date().toISOString(),
            notes: document.getElementById('paymentNotes')?.value || '',
            // Customer information
            customer_id: selectedCustomer ? selectedCustomer.id : null,
            customer_name: selectedCustomer ? selectedCustomer.display_name : 'Walk-in Customer',
            customer_phone: selectedCustomer ? selectedCustomer.phone : '',
            customer_email: selectedCustomer ? selectedCustomer.email : '',
            customer_type: selectedCustomer ? selectedCustomer.customer_type : 'walk_in',
            membership_level: selectedCustomer ? selectedCustomer.membership_level : 'Basic',
            tax_exempt: selectedCustomer ? selectedCustomer.tax_exempt : false
        };

        // Add method-specific data
        if (this.selectedPaymentMethod === 'cash') {
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            paymentData.cash_received = cashReceived;
            paymentData.change_due = cashReceived - this.paymentAmount;
        } else if (this.selectedPaymentMethod === 'mobile_money') {
            paymentData.mobile_number = document.getElementById('mobileNumber').value;
            paymentData.mobile_provider = document.getElementById('mobileProvider').value;
        } else if (['credit_card', 'debit_card', 'pos_card'].includes(this.selectedPaymentMethod)) {
            paymentData.card_number = document.getElementById('cardNumber').value;
            paymentData.card_expiry = document.getElementById('cardExpiry').value;
            paymentData.card_cvv = document.getElementById('cardCVV').value;
        } else if (this.selectedPaymentMethod === 'loyalty_points') {
            const loyaltyValidation = this.validateLoyaltyPayment();
            if (!loyaltyValidation.valid) {
                throw new Error(loyaltyValidation.error);
            }
            paymentData.use_loyalty_points = true;
            paymentData.loyalty_points_to_use = loyaltyValidation.pointsToUse;
            paymentData.loyalty_discount = loyaltyValidation.pointsValue;
            paymentData.remaining_amount = loyaltyValidation.remainingAmount;
            
            // Override customer data with loyalty selected customer
            if (loyaltyValidation.customer) {
                paymentData.customer_id = loyaltyValidation.customer.id;
                paymentData.customer_name = loyaltyValidation.customer.display_name;
                paymentData.customer_phone = '';
                paymentData.customer_email = '';
                paymentData.customer_type = 'registered';
                paymentData.tax_exempt = false;
            }
        } else if (this.selectedPaymentMethod === 'refund_receipt') {
            const refundValidation = this.validateRefundReceiptPayment();
            if (!refundValidation.valid) {
                throw new Error(refundValidation.error);
            }
            
            // Add refund receipt data
            paymentData.refund_number = refundValidation.refundNumber;
            paymentData.amount_to_use = refundValidation.amountToUse;
        }

        // Ensure refund receipt fields are present when required (defensive)
        if (this.selectedPaymentMethod === 'refund_receipt') {
            if (!paymentData.refund_number) {
                const rn = document.getElementById('refundReceiptNumber')?.value?.trim();
                if (rn) paymentData.refund_number = rn;
            }
            if (!paymentData.amount_to_use || paymentData.amount_to_use <= 0) {
                const amt = parseFloat(document.getElementById('refundReceiptAmountToUse')?.value) || 0;
                if (amt > 0) paymentData.amount_to_use = amt;
            }
            
            // Final validation before sending
            if (!paymentData.refund_number) {
                throw new Error('Refund receipt number is missing. Please validate the receipt first.');
            }
            if (!paymentData.amount_to_use || paymentData.amount_to_use <= 0) {
                throw new Error('Refund receipt amount is missing. Please validate the receipt first.');
            }
        }

        // Log payload for debugging
        try { console.debug('processPayment payload', paymentData); } catch (e) {}

        // Send payment data to server
        const response = await fetch('process_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(paymentData)
        });

        // Read JSON safely and surface server errors
        let json;
        try {
            json = await response.json();
        } catch (e) {
            const txt = await response.text().catch(() => '');
            throw new Error(txt || 'Invalid response from server');
        }

        if (!response.ok) {
            throw new Error(json?.error || 'Payment processing failed');
        }

        return json;
    }

    showReceipt(paymentData) {
        // Hide payment modal
        const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (paymentModal) paymentModal.hide();

        // Populate receipt data
        this.populateReceipt(paymentData);

        // Show/hide auto-print indicator based on setting
        const autoPrintIndicator = document.getElementById('autoPrintIndicator');
        if (autoPrintIndicator) {
            if (window.autoPrintReceipt) {
                autoPrintIndicator.classList.remove('d-none');
            } else {
                autoPrintIndicator.classList.add('d-none');
            }
        }

        // Show receipt modal
        const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
        receiptModal.show();

        // Auto-print receipt if enabled
        if (window.autoPrintReceipt) {
            // Wait a moment for the modal to be fully displayed
            setTimeout(() => {
                this.printReceipt();
            }, 500);
        }
    }

    populateReceipt(paymentData) {
        const now = new Date();
        
        // Set transaction details
        document.querySelector('.receipt-transaction-id').textContent = paymentData.transaction_id || this.generateTransactionId();
        document.querySelector('.receipt-date').textContent = now.toLocaleDateString();
        document.querySelector('.receipt-time').textContent = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
        document.querySelector('.receipt-payment-method').textContent = this.formatPaymentMethod(paymentData.method);

        // Ensure we have valid numeric values
        const subtotal = parseFloat(paymentData.subtotal) || 0;
        const tax = parseFloat(paymentData.tax) || 0;
        const total = parseFloat(paymentData.amount) || 0;

        // Set totals with proper formatting
        document.querySelector('.receipt-subtotal').textContent = `${this.currency}${this.formatAmount(subtotal)}`;
        document.querySelector('.receipt-tax').textContent = `${this.currency}${this.formatAmount(tax)}`;
        document.querySelector('.receipt-total').textContent = `${this.currency}${this.formatAmount(total)}`;

        // Show cash details if cash payment
        const cashDetails = document.getElementById('receiptCashDetails');
        if (paymentData.method === 'cash' && paymentData.cash_received !== undefined) {
            const cashReceived = parseFloat(paymentData.cash_received) || 0;
            const changeDue = parseFloat(paymentData.change_due) || 0;
            document.querySelector('.receipt-cash-received').textContent = `${this.currency}${this.formatAmount(cashReceived)}`;
            document.querySelector('.receipt-change-due').textContent = `${this.currency}${this.formatAmount(changeDue)}`;
            cashDetails.style.display = 'block';
        } else {
            cashDetails.style.display = 'none';
        }

        // Populate items
        this.populateReceiptItems(paymentData.items || []);
    }

    populateReceiptItems(items) {
        const itemsContainer = document.querySelector('.receipt-items');
        if (!itemsContainer || !items) return;

        let itemsHtml = '';
        items.forEach(item => {
            const itemTotal = item.price * item.quantity;
            itemsHtml += `
                <div class="d-flex justify-content-between py-1">
                    <div>
                        <small class="fw-bold">${item.name}</small>
                        <br>
                        <small class="text-muted">${item.quantity} × ${this.currency}${this.formatAmount(item.price)}</small>
                    </div>
                    <div class="text-end">
                        <small class="fw-bold">${this.currency}${this.formatAmount(itemTotal)}</small>
                    </div>
                </div>
            `;
        });
        itemsContainer.innerHTML = itemsHtml;
    }

    printReceipt() {
        const receiptData = this.getReceiptData();
        // Build print URL with auto-print and auto-close flags
        const params = new URLSearchParams();
        params.set('data', encodeURIComponent(JSON.stringify(receiptData)));
        params.set('auto_print', 'true');
        params.set('auto_close', 'true');
        const printUrl = `print_receipt.php?${params.toString()}`;

        // Open in new tab instead of popup window
        const printTab = window.open(printUrl, '_blank');
        
        // Check if tab was opened successfully
        if (!printTab) {
            console.error('Failed to open print tab. Popup may be blocked.');
            alert('Print tab could not be opened. Please check if popups are blocked and try again.');
            return;
        }
        
        // Focus the new tab
        printTab.focus();
        
        // The print page will handle auto-printing and auto-closing
        // Listen for tab close to start new transaction
        const checkClosed = setInterval(() => {
            if (printTab.closed) {
                clearInterval(checkClosed);
                // Start new transaction after print tab closes
                this.startNewTransaction();
            }
        }, 1000);
    }


    getReceiptData() {
        const modal = document.getElementById('receiptModal');
        return {
            transaction_id: modal.querySelector('.receipt-transaction-id')?.textContent || '',
            date: modal.querySelector('.receipt-date')?.textContent || '',
            time: modal.querySelector('.receipt-time')?.textContent || '',
            payment_method: modal.querySelector('.receipt-payment-method')?.textContent || '',
            subtotal: modal.querySelector('.receipt-subtotal')?.textContent || '',
            tax: modal.querySelector('.receipt-tax')?.textContent || '',
            total: modal.querySelector('.receipt-total')?.textContent || '',
            items: this.extractItemsFromReceipt(),
            company_name: window.POSConfig?.companyName || 'POS System',
            company_address: window.POSConfig?.companyAddress || ''
        };
    }

    extractItemsFromReceipt() {
        const items = [];
        const itemElements = document.querySelectorAll('.receipt-items .d-flex');
        
        itemElements.forEach(element => {
            const name = element.querySelector('small.fw-bold')?.textContent || '';
            const qty = element.querySelector('small.text-muted')?.textContent || '';
            const price = element.querySelector('.text-end small.fw-bold')?.textContent || '';
            
            if (name) {
                items.push({ name, qty, price });
            }
        });
        
        return items;
    }

    startNewTransaction() {
        // Clear cart without confirmation and without page reload
        // Clear session cart on server
        fetch('clear_cart.php', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Clear cart data in memory
                window.cartData = [];
                window.paymentTotals = { subtotal: 0, tax: 0, total: 0 };
                
                // Update cart display using global function
                if (typeof updateCartDisplay === 'function') {
                    updateCartDisplay([]);
                }
                
                // Reset payment processor data
                this.transactionData = [];
                this.paymentAmount = 0;
                
                // Hide receipt modal
                const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
                if (receiptModal) receiptModal.hide();
                
                // Reset payment modal if it's open
                const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
                if (paymentModal) paymentModal.hide();
                
                // Reset payment processor state
                this.resetPaymentModal();
                
                console.log('New transaction started - cart cleared');
            } else {
                console.error('Error clearing cart:', data.error);
                // Fallback to page reload if clearing fails
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error clearing cart:', error);
            // Fallback to page reload if clearing fails
            window.location.reload();
        });
    }

    cancelPayment() {
        this.resetPaymentModal();
        const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (paymentModal) paymentModal.hide();
    }

    cancelReceipt() {
        const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
        if (receiptModal) receiptModal.hide();
    }

    // LOYALTY POINTS METHODS

    async loadCustomerLoyaltyInfo() {
        // Check if we have a selected customer from the main customer selection
        const selectedCustomer = window.selectedCustomer;
        if (selectedCustomer && selectedCustomer.customer_type !== 'walk_in') {
            // Use the already selected customer
            this.loyaltySelectedCustomer = selectedCustomer;
            this.loadLoyaltyDataForCustomer(selectedCustomer.id);
        } else {
            // Show customer search interface
            this.showLoyaltyCustomerSearch();
        }
    }

    showLoyaltyCustomerSearch() {
        // Clear any previous selection
        this.loyaltySelectedCustomer = null;
        const selectedCustomerEl = document.getElementById('loyaltySelectedCustomer');
        const resultsEl = document.getElementById('loyaltyCustomerResults');
        const pointsToUseEl = document.getElementById('loyaltyPointsToUse');
        const pointsAvailableEl = document.getElementById('loyaltyPointsAvailable');
        const pointsValueEl = document.getElementById('loyaltyPointsValue');
        const remainingAmountEl = document.getElementById('remainingAmount');
        
        if (selectedCustomerEl) selectedCustomerEl.style.display = 'none';
        if (resultsEl) resultsEl.style.display = 'none';
        if (pointsToUseEl) pointsToUseEl.disabled = true;
        if (pointsAvailableEl) pointsAvailableEl.value = '0';
        if (pointsValueEl) pointsValueEl.value = '0.00';
        if (remainingAmountEl) remainingAmountEl.value = this.formatAmount(this.paymentAmount);
    }

    // Cash Customer Search Methods
    showCashCustomerSearch() {
        // Clear any previous selection
        this.cashSelectedCustomer = null;
        document.getElementById('cashSelectedCustomer').style.display = 'none';
        document.getElementById('cashCustomerResults').style.display = 'none';
        document.getElementById('cashLoyaltyPointsInfo').style.display = 'none';
    }

    async searchCashCustomers(searchTerm) {
        try {
            const response = await fetch(`../api/search_customers_loyalty.php?search=${encodeURIComponent(searchTerm)}`);
            const data = await response.json();

            if (data.success) {
                this.displayCashCustomerResults(data.customers);
            } else {
                this.showCashError(data.error || 'Failed to search customers');
            }
        } catch (error) {
            console.error('Error searching customers:', error);
            this.showCashError('Failed to search customers');
        }
    }

    displayCashCustomerResults(customers) {
        const resultsDiv = document.getElementById('cashCustomerResults');
        
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="alert alert-info">No customers found</div>';
        } else {
            let html = '<div class="list-group">';
            customers.forEach(customer => {
                html += `
                    <div class="list-group-item list-group-item-action" onclick="window.paymentProcessor.selectCashCustomer(${customer.id}, '${customer.display_name}', '${customer.membership_level}')">
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

    selectCashCustomer(customerId, customerName, membershipLevel) {
        this.cashSelectedCustomer = {
            id: customerId,
            display_name: customerName,
            membership_level: membershipLevel
        };
        
        // Hide search results
        document.getElementById('cashCustomerResults').style.display = 'none';
        
        // Show selected customer
        document.getElementById('cashSelectedCustomerName').textContent = customerName;
        document.getElementById('cashSelectedCustomerPoints').textContent = `${membershipLevel} member`;
        document.getElementById('cashSelectedCustomer').style.display = 'block';
        
        // Update loyalty points calculation
        this.updateCashLoyaltyPoints();
    }

    updateCashLoyaltyPoints() {
        if (this.cashSelectedCustomer && this.paymentAmount > 0) {
            // Calculate loyalty points that would be earned
            const pointsToEarn = this.calculateLoyaltyPointsForAmount(this.paymentAmount, this.cashSelectedCustomer.membership_level);
            document.getElementById('cashPointsToEarn').textContent = pointsToEarn;
            document.getElementById('cashLoyaltyPointsInfo').style.display = 'block';
        } else {
            document.getElementById('cashLoyaltyPointsInfo').style.display = 'none';
        }
    }

    calculateLoyaltyPointsForAmount(amount, membershipLevel) {
        // This is a simplified calculation - in a real implementation, 
        // you'd want to call the server to get the actual loyalty settings
        const basePointsPerDollar = 1; // Default rate
        const multiplier = this.getMembershipMultiplier(membershipLevel);
        return Math.floor(amount * basePointsPerDollar * multiplier);
    }

    getMembershipMultiplier(level) {
        const multipliers = {
            'Basic': 1.0,
            'Silver': 1.5,
            'Gold': 2.0,
            'Platinum': 2.5,
            'Diamond': 3.0
        };
        return multipliers[level] || 1.0;
    }

    showCashError(message) {
        const resultsDiv = document.getElementById('cashCustomerResults');
        resultsDiv.innerHTML = `<div class="alert alert-danger">${message}</div>`;
        resultsDiv.style.display = 'block';
    }

    bindQuickAmountButtons() {
        // Re-bind quick amount buttons (in case they were dynamically added)
        const quickAmountBtns = document.querySelectorAll('.quick-amount');
        quickAmountBtns.forEach(btn => {
            // Remove existing event listeners to avoid duplicates
            btn.removeEventListener('click', this.handleQuickAmountClick);
            // Add new event listener
            btn.addEventListener('click', this.handleQuickAmountClick.bind(this));
        });

        // Re-bind exact amount button
        const exactAmountBtn = document.getElementById('exactAmountBtn');
        if (exactAmountBtn) {
            exactAmountBtn.removeEventListener('click', this.handleExactAmountClick);
            exactAmountBtn.addEventListener('click', this.handleExactAmountClick.bind(this));
        }
    }

    handleQuickAmountClick(event) {
        const amount = parseFloat(event.currentTarget.dataset.amount) || 0;
        const cashInput = document.getElementById('cashReceived');
        if (cashInput) {
            cashInput.value = amount;
            cashInput.focus();
            this.calculateChange();
            this.updateCashLoyaltyPoints();
        }
    }

    handleExactAmountClick() {
        const cashInput = document.getElementById('cashReceived');
        if (cashInput) {
            cashInput.value = this.paymentAmount;
            cashInput.focus();
            this.calculateChange();
            this.updateCashLoyaltyPoints();
        }
    }

    async searchLoyaltyCustomers(searchTerm) {
        try {
            const response = await fetch(`../api/search_customers_loyalty.php?search=${encodeURIComponent(searchTerm)}`);
            const data = await response.json();

            if (data.success) {
                this.displayLoyaltyCustomerResults(data.customers);
            } else {
                this.showLoyaltyError(data.error || 'Failed to search customers');
            }
        } catch (error) {
            console.error('Error searching customers:', error);
            this.showLoyaltyError('Failed to search customers');
        }
    }

    displayLoyaltyCustomerResults(customers) {
        const resultsDiv = document.getElementById('loyaltyCustomerResults');
        
        if (customers.length === 0) {
            resultsDiv.innerHTML = '<div class="alert alert-info">No customers found</div>';
        } else {
            let html = '<div class="list-group">';
            customers.forEach(customer => {
                html += `
                    <div class="list-group-item list-group-item-action" onclick="window.paymentProcessor.selectLoyaltyCustomer(${customer.id}, '${customer.display_name}', ${customer.loyalty_points})">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${customer.display_name}</h6>
                            <small class="text-muted">${customer.loyalty_points} points available</small>
                        </div>
                        <p class="mb-1 text-muted">${customer.customer_number} • ${customer.membership_level}</p>
                    </div>
                `;
            });
            html += '</div>';
            resultsDiv.innerHTML = html;
        }
        
        resultsDiv.style.display = 'block';
    }

    selectLoyaltyCustomer(customerId, customerName, loyaltyPoints) {
        this.loyaltySelectedCustomer = {
            id: customerId,
            display_name: customerName,
            loyalty_points: loyaltyPoints
        };
        
        // Hide search results
        document.getElementById('loyaltyCustomerResults').style.display = 'none';
        
        // Show selected customer
        document.getElementById('loyaltySelectedCustomerName').textContent = customerName;
        document.getElementById('loyaltySelectedCustomerPoints').textContent = `${loyaltyPoints} points available`;
        document.getElementById('loyaltySelectedCustomer').style.display = 'block';
        
        // Enable points input and load loyalty data
        document.getElementById('loyaltyPointsToUse').disabled = false;
        this.loadLoyaltyDataForCustomer(customerId);
    }

    async loadLoyaltyDataForCustomer(customerId) {
        try {
            const response = await fetch(`../api/get_customer_loyalty.php?customer_id=${customerId}`);
            const data = await response.json();

            if (data.success) {
                this.updateLoyaltyDisplay(data.loyalty);
            } else {
                this.showLoyaltyError(data.error || 'Failed to load loyalty information');
            }
        } catch (error) {
            console.error('Error loading loyalty info:', error);
            this.showLoyaltyError('Failed to load loyalty information');
        }
    }

    updateLoyaltyDisplay(loyaltyData) {
        const availablePoints = loyaltyData.balance;
        const pointsValue = loyaltyData.points_value;

        document.getElementById('loyaltyPointsAvailable').value = availablePoints;
        document.getElementById('loyaltyPointsToUse').max = availablePoints;
        document.getElementById('loyaltyPointsToUse').value = 0;
        document.getElementById('loyaltyPointsValue').value = '0.00';
        document.getElementById('remainingAmount').value = this.formatAmount(this.paymentAmount);

        // Add event listener for points input
        const pointsInput = document.getElementById('loyaltyPointsToUse');
        pointsInput.removeEventListener('input', this.handleLoyaltyPointsInput);
        pointsInput.addEventListener('input', this.handleLoyaltyPointsInput.bind(this));
    }

    handleLoyaltyPointsInput(event) {
        const pointsToUse = parseInt(event.target.value) || 0;
        const availablePoints = parseInt(document.getElementById('loyaltyPointsAvailable').value) || 0;
        
        // Validate points
        if (pointsToUse > availablePoints) {
            event.target.value = availablePoints;
            pointsToUse = availablePoints;
        }

        // Calculate points value (100 points = 1 currency unit)
        const pointsValue = pointsToUse / 100;
        const remainingAmount = Math.max(0, this.paymentAmount - pointsValue);

        document.getElementById('loyaltyPointsValue').value = this.formatAmount(pointsValue);
        document.getElementById('remainingAmount').value = this.formatAmount(remainingAmount);

        // Update confirm button state
        this.updateConfirmButton();
    }

    showLoyaltyError(message) {
        const loyaltySection = document.getElementById('loyaltyPointsPaymentSection');
        loyaltySection.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Error:</strong> ${message}
            </div>
        `;
    }

    validateLoyaltyPayment() {
        const selectedCustomer = this.loyaltySelectedCustomer;
        if (!selectedCustomer) {
            return { valid: false, error: 'Please select a customer for loyalty points payment' };
        }

        const pointsToUse = parseInt(document.getElementById('loyaltyPointsToUse').value) || 0;
        const availablePoints = parseInt(document.getElementById('loyaltyPointsAvailable').value) || 0;

        if (pointsToUse <= 0) {
            return { valid: false, error: 'Please enter points to use' };
        }

        if (pointsToUse > availablePoints) {
            return { valid: false, error: 'Not enough loyalty points available' };
        }

        const pointsValue = pointsToUse / 100;
        const remainingAmount = this.paymentAmount - pointsValue;

        if (remainingAmount < 0) {
            return { valid: false, error: 'Points value exceeds total amount' };
        }

        return { 
            valid: true, 
            pointsToUse: pointsToUse, 
            pointsValue: pointsValue, 
            remainingAmount: remainingAmount,
            customer: selectedCustomer
        };
    }

    validateRefundReceiptPayment() {
        // Check if receipt was already validated via the validate button
        // (payment_modal.php stores this in window.currentRefundReceiptData)
        if (window.currentRefundReceiptData && window.currentRefundReceiptData.success) {
            const refundReceiptNumber = document.getElementById('refundReceiptNumber')?.value?.trim();
            const refundReceiptAmount = parseFloat(document.getElementById('refundReceiptAmountToUse')?.value) || 0;
            
            if (refundReceiptAmount > 0) {
                return { 
                    valid: true,
                    refundNumber: refundReceiptNumber,
                    amountToUse: refundReceiptAmount
                };
            }
        }

        // Fallback: check fields manually
        const refundReceiptNumber = document.getElementById('refundReceiptNumber')?.value?.trim();
        const refundReceiptAmount = parseFloat(document.getElementById('refundReceiptAmountToUse')?.value) || 0;

        if (!refundReceiptNumber) {
            return { valid: false, error: 'Please enter a refund receipt number' };
        }

        if (!refundReceiptNumber.match(/^RFD-\d{7}$/)) {
            return { valid: false, error: 'Invalid receipt format. Please use format: RFD-0000001' };
        }

        if (refundReceiptAmount <= 0) {
            return { valid: false, error: 'Please validate the refund receipt first' };
        }

        if (refundReceiptAmount > this.paymentAmount) {
            return { valid: false, error: 'Refund amount exceeds payment amount' };
        }

        return { 
            valid: true,
            refundNumber: refundReceiptNumber,
            amountToUse: refundReceiptAmount
        };
    }

    resetPaymentModal() {
        // Reset payment method selection
        this.selectedPaymentMethod = null;
        document.querySelectorAll('.payment-method').forEach(method => {
            method.classList.remove('selected');
            method.style.transform = 'translateY(0)';
            method.style.boxShadow = '';
            method.style.borderColor = '';
        });

        // Reset collapsible state
        const collapseElement = document.getElementById('otherPaymentMethods');
        const collapseBtn = document.querySelector('[data-bs-target="#otherPaymentMethods"]');
        if (collapseElement && collapseBtn) {
            const bsCollapse = bootstrap.Collapse.getInstance(collapseElement);
            if (bsCollapse) {
                bsCollapse.hide();
            }
            const icon = collapseBtn.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-chevron-down me-1';
            }
            collapseBtn.setAttribute('aria-expanded', 'false');
        }

        // Hide all payment sections
        document.getElementById('cashPaymentSection').style.display = 'none';
        document.getElementById('mobileMoneySection').style.display = 'none';
        document.getElementById('cardPaymentSection').style.display = 'none';
        document.getElementById('loyaltyPointsPaymentSection').style.display = 'none';

        // Clear form inputs
        document.getElementById('cashReceived').value = '';
        document.getElementById('mobileNumber').value = '';
        document.getElementById('mobileProvider').value = '';
        document.getElementById('cardNumber').value = '';
        document.getElementById('cardExpiry').value = '';
        document.getElementById('cardCVV').value = '';
        document.getElementById('paymentNotes').value = '';

        // Reset change display
        const changeAmount = document.getElementById('changeAmount');
        if (changeAmount) {
            changeAmount.textContent = `${this.currency}0.00`;
        }
        const changeDisplay = document.getElementById('changeDisplay');
        if (changeDisplay) {
            changeDisplay.classList.remove('positive', 'negative');
        }

        // Disable confirm button
        const confirmBtn = document.querySelector('.payment-btn.confirm');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm Payment';
        }
    }

    showError(message) {
        alert(`Error: ${message}`);
    }

    generateTransactionId() {
        // For now, use simple format. In production, this should be generated by the server
        const prefix = 'TXN';
        // Use mixed characters (numbers + uppercase + lowercase) for more variety
        const characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        let randomString = '';
        for (let i = 0; i < 6; i++) {
            randomString += characters.charAt(Math.floor(Math.random() * characters.length));
        }
        return `${prefix}${randomString}`;
    }

    formatPaymentMethod(method) {
        const methods = {
            cash: 'Cash',
            mobile_money: 'Mobile Money',
            credit_card: 'Credit Card',
            debit_card: 'Debit Card',
            pos_card: 'POS Card',
            bank_transfer: 'Bank Transfer',
            check: 'Check',
            online_payment: 'Online Payment',
            voucher: 'Voucher',
            store_credit: 'Store Credit'
        };
        return methods[method] || method;
    }

    formatAmount(amount) {
        const num = parseFloat(amount);
        if (isNaN(num) || !isFinite(num)) {
            return '0.00';
        }
        return num.toFixed(2);
    }
}

// Global function to toggle payment items details
function togglePaymentItemsDetails() {
    const detailsEl = document.getElementById('paymentItemsDetails');
    const buttonEl = event.target;
    
    if (detailsEl) {
        const isVisible = detailsEl.style.display !== 'none';
        
        if (isVisible) {
            detailsEl.style.display = 'none';
            buttonEl.innerHTML = '<i class="bi bi-list-ul me-1"></i>View Details';
        } else {
            detailsEl.style.display = 'block';
            buttonEl.innerHTML = '<i class="bi bi-eye-slash me-1"></i>Hide Details';
        }
    }
}

// Initialize payment processor when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.paymentProcessor = new PaymentProcessor();
});

// Expose to global scope for external access
window.PaymentProcessor = PaymentProcessor;
