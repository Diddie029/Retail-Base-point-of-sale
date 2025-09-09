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
            cashInput.addEventListener('input', () => this.calculateChange());
            cashInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.confirmPayment();
                }
            });
        }

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

        const downloadBtn = document.querySelector('.receipt-btn.download');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => this.downloadReceipt());
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
        
        // Update the display
        const subtotalEl = document.getElementById('paymentSubtotal');
        const taxEl = document.getElementById('paymentTax');
        const totalEl = document.getElementById('paymentTotal');
        const itemCountEl = document.getElementById('paymentItemCount');
        const itemsEl = document.getElementById('paymentItems');

        if (subtotalEl) subtotalEl.textContent = this.formatAmount(subtotal);
        if (taxEl) taxEl.textContent = this.formatAmount(tax);
        if (totalEl) totalEl.textContent = this.formatAmount(total);
        if (itemCountEl) itemCountEl.textContent = this.transactionData.length;

        // Update items display
        if (itemsEl && this.transactionData.length > 0) {
            let itemsHtml = '';
            this.transactionData.forEach(item => {
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
            itemsEl.innerHTML = itemsHtml;
        } else if (itemsEl) {
            itemsEl.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cart-x fs-1"></i>
                    <p class="mt-2 mb-1">No items in cart</p>
                    <small>Add products to get started</small>
                </div>
            `;
        }
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

        // If selecting a non-cash method, expand the collapsible section
        if (this.selectedPaymentMethod !== 'cash') {
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
        // Hide all payment sections
        document.getElementById('cashPaymentSection').style.display = 'none';
        document.getElementById('mobileMoneySection').style.display = 'none';
        document.getElementById('cardPaymentSection').style.display = 'none';

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
                this.loadCustomerLoyaltyInfo();
                document.getElementById('loyaltyPointsPaymentSection').style.display = 'block';
                setTimeout(() => {
                    document.getElementById('loyaltyPointsToUse').focus();
                }, 100);
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
            const paymentData = await this.processPayment();
            
            if (paymentData.success) {
                this.showReceipt(paymentData);
            } else {
                this.showError(paymentData.error || 'Payment processing failed');
            }
        } catch (error) {
            console.error('Payment error:', error);
            this.showError('Payment processing failed. Please try again.');
        } finally {
            // Reset button
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirm Payment';
        }
    }

    async processPayment() {
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
        const selectedCustomer = window.selectedCustomer || null;
        
        const paymentData = {
            method: this.selectedPaymentMethod,
            amount: total,
            subtotal: subtotal,
            tax: tax,
            items: this.transactionData,
            timestamp: new Date().toISOString(),
            notes: document.getElementById('paymentNotes')?.value || '',
            // Customer information
            customer_id: selectedCustomer ? selectedCustomer.id : null,
            customer_name: selectedCustomer ? selectedCustomer.display_name : 'Walk-in Customer',
            customer_phone: selectedCustomer ? selectedCustomer.phone : '',
            customer_email: selectedCustomer ? selectedCustomer.email : '',
            customer_type: selectedCustomer ? selectedCustomer.customer_type : 'walk_in',
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
        }

        // Send payment data to server
        const response = await fetch('process_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(paymentData)
        });

        return await response.json();
    }

    showReceipt(paymentData) {
        // Hide payment modal
        const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (paymentModal) paymentModal.hide();

        // Populate receipt data
        this.populateReceipt(paymentData);

        // Show receipt modal
        const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
        receiptModal.show();
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
        const printUrl = `print_receipt.php?data=${encodeURIComponent(JSON.stringify(receiptData))}`;
        
        // Open print window
        const printWindow = window.open(printUrl, '_blank', 'width=800,height=600');
        
        // Listen for the print window to close or complete printing
        const checkClosed = setInterval(() => {
            if (printWindow.closed) {
                clearInterval(checkClosed);
                // Start new transaction after print window closes
                this.startNewTransaction();
            }
        }, 1000);
    }

    async downloadReceipt() {
        try {
            const receiptData = this.getReceiptData();
            const response = await fetch('generate_pdf_receipt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(receiptData)
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `receipt_${receiptData.transaction_id}.pdf`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            } else {
                throw new Error('PDF generation failed');
            }
        } catch (error) {
            console.error('Download error:', error);
            alert('Receipt download failed. Please try again.');
        }
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
        document.getElementById('loyaltySelectedCustomer').style.display = 'none';
        document.getElementById('loyaltyCustomerResults').style.display = 'none';
        document.getElementById('loyaltyPointsToUse').disabled = true;
        document.getElementById('loyaltyPointsAvailable').value = '0';
        document.getElementById('loyaltyPointsValue').value = '0.00';
        document.getElementById('remainingAmount').value = this.formatAmount(this.paymentAmount);
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
                            <small class="text-muted">${customer.loyalty_points} points</small>
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
        const prefix = 'TXN';
        const timestamp = Date.now();
        const random = Math.floor(Math.random() * 1000);
        return `${prefix}${timestamp}${random}`;
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

// Initialize payment processor when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.paymentProcessor = new PaymentProcessor();
});

// Expose to global scope for external access
window.PaymentProcessor = PaymentProcessor;
