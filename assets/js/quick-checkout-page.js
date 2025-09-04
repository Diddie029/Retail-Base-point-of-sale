/**
 * Quick Checkout Page JavaScript
 * Modern glassmorphism design with advanced payment processing
 * Features: Payment selection, cash calculations, receipt generation, auto-print
 */

class QuickCheckout {
    constructor() {
        this.selectedPaymentMethod = null;
        this.cashReceived = 0;
        this.changeAmount = 0;
        this.isProcessing = false;
        this.successModal = null;
        this.receiptData = null;
        
        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        console.log('Initializing Quick Checkout...');
        this.setupEventListeners();
        this.initializeModals();
        this.animateEntrance();
    }

    setupEventListeners() {
        // Payment method selection
        const paymentBtns = document.querySelectorAll('.payment-btn');
        paymentBtns.forEach(btn => {
            btn.addEventListener('click', (e) => this.selectPaymentMethod(e));
        });

        // Cash input handling
        const cashInput = document.getElementById('cashReceived');
        if (cashInput) {
            cashInput.addEventListener('input', (e) => this.calculateChange(e));
            cashInput.addEventListener('keydown', (e) => this.handleCashInputKeydown(e));
        }

        // Process payment button
        const processBtn = document.getElementById('processPaymentBtn');
        if (processBtn) {
            processBtn.addEventListener('click', (e) => this.processPayment(e));
        }

        // Print receipt button
        const printBtn = document.getElementById('printReceiptBtn');
        if (printBtn) {
            printBtn.addEventListener('click', () => this.printReceipt());
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));

        // Auto-focus cash input when cash is selected
        const observer = new MutationObserver(() => {
            const cashSection = document.getElementById('cashPaymentSection');
            if (cashSection && cashSection.style.display !== 'none') {
                setTimeout(() => {
                    const cashInput = document.getElementById('cashReceived');
                    if (cashInput) {
                        cashInput.focus();
                        cashInput.select();
                    }
                }, 300);
            }
        });

        const cashSection = document.getElementById('cashPaymentSection');
        if (cashSection) {
            observer.observe(cashSection, { attributes: true, attributeFilter: ['style'] });
        }
    }

    initializeModals() {
        this.successModal = new bootstrap.Modal(document.getElementById('successModal'), {
            backdrop: 'static',
            keyboard: false
        });
    }

    animateEntrance() {
        // Trigger entrance animations
        const card = document.querySelector('.checkout-card');
        if (card) {
            card.style.animation = 'cardEntrance 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards';
        }

        // Animate floating particles
        this.startParticleAnimation();
    }

    startParticleAnimation() {
        const particles = document.querySelector('.floating-particles');
        if (particles) {
            particles.style.animation = 'particleFloat 20s linear infinite';
        }
    }

    selectPaymentMethod(event) {
        const btn = event.currentTarget;
        const method = btn.dataset.method;

        // Remove selection from all buttons
        document.querySelectorAll('.payment-btn').forEach(b => {
            b.classList.remove('selected');
        });

        // Add selection to clicked button
        btn.classList.add('selected');
        this.selectedPaymentMethod = method;

        // Show/hide cash payment section
        const cashSection = document.getElementById('cashPaymentSection');
        if (method === 'cash') {
            cashSection.style.display = 'block';
            // Auto-fill with exact amount
            setTimeout(() => {
                const cashInput = document.getElementById('cashReceived');
                if (cashInput) {
                    cashInput.value = window.POSConfig.totalAmount.toFixed(2);
                    this.calculateChange({ target: cashInput });
                    cashInput.focus();
                    cashInput.select();
                }
            }, 300);
        } else {
            cashSection.style.display = 'none';
            this.cashReceived = window.POSConfig.totalAmount;
            this.changeAmount = 0;
        }

        // Enable process payment button
        this.updateProcessButton();

        // Add haptic feedback (if supported)
        if (navigator.vibrate) {
            navigator.vibrate(50);
        }

        console.log(`Payment method selected: ${method}`);
    }

    calculateChange(event) {
        const cashReceived = parseFloat(event.target.value) || 0;
        this.cashReceived = cashReceived;
        this.changeAmount = Math.max(0, cashReceived - window.POSConfig.totalAmount);

        const changeDisplay = document.getElementById('changeAmount');
        if (changeDisplay) {
            const formattedChange = `${window.POSConfig.currencySymbol}${this.changeAmount.toFixed(2)}`;
            changeDisplay.textContent = formattedChange;
            
            // Update change display styling based on amount
            const changeContainer = changeDisplay.closest('.change-display');
            if (changeContainer) {
                changeContainer.classList.remove('positive', 'negative', 'exact');
                if (this.changeAmount > 0) {
                    changeContainer.classList.add('positive');
                } else if (cashReceived < window.POSConfig.totalAmount) {
                    changeContainer.classList.add('negative');
                } else {
                    changeContainer.classList.add('exact');
                }
            }
        }

        this.updateProcessButton();
    }

    handleCashInputKeydown(event) {
        // Allow Enter key to trigger payment processing
        if (event.key === 'Enter' && this.canProcessPayment()) {
            event.preventDefault();
            this.processPayment();
        }
        
        // Quick amount buttons (F1-F4 for common amounts)
        const totalAmount = window.POSConfig.totalAmount;
        const quickAmounts = {
            'F1': Math.ceil(totalAmount), // Round up to nearest whole number
            'F2': Math.ceil(totalAmount / 10) * 10, // Round up to nearest 10
            'F3': Math.ceil(totalAmount / 20) * 20, // Round up to nearest 20
            'F4': Math.ceil(totalAmount / 50) * 50  // Round up to nearest 50
        };

        if (quickAmounts[event.key]) {
            event.preventDefault();
            const cashInput = event.target;
            cashInput.value = quickAmounts[event.key].toFixed(2);
            this.calculateChange({ target: cashInput });
        }
    }

    updateProcessButton() {
        const processBtn = document.getElementById('processPaymentBtn');
        const canProcess = this.canProcessPayment();
        
        if (processBtn) {
            processBtn.disabled = !canProcess;
            
            if (canProcess) {
                processBtn.classList.remove('btn-secondary');
                processBtn.classList.add('btn-primary');
                
                // Update button text based on payment method
                const buttonText = processBtn.querySelector('span');
                if (buttonText) {
                    if (this.selectedPaymentMethod === 'cash') {
                        buttonText.textContent = `Pay ${window.POSConfig.currencySymbol}${window.POSConfig.totalAmount.toFixed(2)}`;
                    } else {
                        buttonText.textContent = 'Process Payment';
                    }
                }
            } else {
                processBtn.classList.remove('btn-primary');
                processBtn.classList.add('btn-secondary');
            }
        }
    }

    canProcessPayment() {
        if (!this.selectedPaymentMethod || this.isProcessing) {
            return false;
        }

        if (this.selectedPaymentMethod === 'cash') {
            return this.cashReceived >= window.POSConfig.totalAmount;
        }

        return true;
    }

    handleKeyboardShortcuts(event) {
        // Global keyboard shortcuts
        if (event.ctrlKey || event.metaKey) {
            switch (event.key) {
                case '1':
                    event.preventDefault();
                    this.selectPaymentByMethod('cash');
                    break;
                case '2':
                    event.preventDefault();
                    this.selectPaymentByMethod('card');
                    break;
                case '3':
                    event.preventDefault();
                    this.selectPaymentByMethod('mobile');
                    break;
                case '4':
                    event.preventDefault();
                    this.selectPaymentByMethod('bank');
                    break;
                case 'Enter':
                    if (this.canProcessPayment()) {
                        event.preventDefault();
                        this.processPayment();
                    }
                    break;
            }
        }

        // Escape to go back
        if (event.key === 'Escape') {
            goBack();
        }
    }

    selectPaymentByMethod(method) {
        const btn = document.querySelector(`[data-method="${method}"]`);
        if (btn) {
            btn.click();
        }
    }

    async processPayment(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.canProcessPayment() || this.isProcessing) {
            return;
        }

        this.isProcessing = true;
        this.setProcessingState(true);

        try {
            console.log('Processing payment...', {
                method: this.selectedPaymentMethod,
                cashReceived: this.cashReceived,
                changeAmount: this.changeAmount,
                total: window.POSConfig.totalAmount
            });

            // Prepare payment data
            const paymentData = {
                cart_data: window.POSConfig.cartData,
                payment_method: this.selectedPaymentMethod,
                payment_type: 'single',
                customer_notes: '',
                cash_received: this.selectedPaymentMethod === 'cash' ? this.cashReceived : null,
                change_amount: this.selectedPaymentMethod === 'cash' ? this.changeAmount : 0
            };

            // Send payment request
            const response = await this.sendPaymentRequest(paymentData);

            if (response.success) {
                // Store receipt data for printing
                this.receiptData = {
                    saleId: response.sale_id,
                    receiptNumber: this.generateReceiptNumber(),
                    totalAmount: response.total_amount,
                    subtotal: response.subtotal,
                    taxAmount: response.tax_amount,
                    paymentMethod: response.payment_method,
                    cashReceived: response.cash_received,
                    changeAmount: response.change_amount,
                    items: window.POSConfig.validItems,
                    timestamp: new Date()
                };

                // Show success modal with animation
                setTimeout(() => {
                    this.showSuccessModal();
                }, 500);

                // Auto-print receipt after 2 seconds
                setTimeout(() => {
                    this.autoPrintReceipt();
                }, 2000);

            } else {
                throw new Error(response.error || 'Payment processing failed');
            }

        } catch (error) {
            console.error('Payment processing error:', error);
            this.showErrorMessage(error.message || 'An error occurred while processing the payment');
        } finally {
            this.isProcessing = false;
            this.setProcessingState(false);
        }
    }

    async sendPaymentRequest(paymentData) {
        const response = await fetch('ajax-quick-checkout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(paymentData)
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        return data;
    }

    setProcessingState(processing) {
        const processBtn = document.getElementById('processPaymentBtn');
        
        if (processBtn) {
            if (processing) {
                processBtn.classList.add('processing');
                processBtn.disabled = true;
            } else {
                processBtn.classList.remove('processing');
                processBtn.disabled = !this.canProcessPayment();
            }
        }
    }

    generateReceiptNumber() {
        const now = new Date();
        const year = now.getFullYear().toString().substr(-2);
        const month = (now.getMonth() + 1).toString().padStart(2, '0');
        const day = now.getDate().toString().padStart(2, '0');
        const random = Math.floor(Math.random() * 999999).toString().padStart(6, '0');
        
        return `R${year}${month}${day}${random}`;
    }

    showSuccessModal() {
        if (!this.receiptData || !this.successModal) {
            return;
        }

        // Update modal content
        document.getElementById('receiptNumber').textContent = this.receiptData.receiptNumber;
        document.getElementById('finalAmount').textContent = 
            `${window.POSConfig.currencySymbol}${this.receiptData.totalAmount.toFixed(2)}`;
        document.getElementById('paymentMethodUsed').textContent = 
            this.getPaymentMethodDisplayName(this.receiptData.paymentMethod);

        // Show/hide cash details
        const cashDetailsRow = document.getElementById('cashDetailsRow');
        const changeDetailsRow = document.getElementById('changeDetailsRow');
        
        if (this.receiptData.paymentMethod === 'cash' && this.receiptData.cashReceived) {
            cashDetailsRow.style.display = 'flex';
            changeDetailsRow.style.display = 'flex';
            
            document.getElementById('cashReceivedAmount').textContent = 
                `${window.POSConfig.currencySymbol}${this.receiptData.cashReceived.toFixed(2)}`;
            document.getElementById('changeGiven').textContent = 
                `${window.POSConfig.currencySymbol}${this.receiptData.changeAmount.toFixed(2)}`;
        } else {
            cashDetailsRow.style.display = 'none';
            changeDetailsRow.style.display = 'none';
        }

        // Show modal with animation
        this.successModal.show();

        // Trigger checkmark animation
        setTimeout(() => {
            this.animateSuccessCheckmark();
        }, 300);
    }

    animateSuccessCheckmark() {
        const checkmarkContainer = document.querySelector('.checkmark-container');
        if (checkmarkContainer) {
            // Reset animation
            checkmarkContainer.style.animation = 'none';
            
            // Trigger animation
            setTimeout(() => {
                checkmarkContainer.style.animation = 'checkmarkCircle 0.8s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards';
            }, 10);
        }
    }

    getPaymentMethodDisplayName(method) {
        const displayNames = {
            'cash': 'Cash Payment',
            'card': 'Card Payment',
            'mobile': 'Mobile Payment',
            'bank': 'Bank Transfer'
        };
        return displayNames[method] || method;
    }

    autoPrintReceipt() {
        // Only auto-print if browser supports it and user hasn't disabled it
        if (window.print && !localStorage.getItem('disableAutoPrint')) {
            console.log('Auto-printing receipt...');
            setTimeout(() => {
                this.printReceipt();
            }, 500);
        }
    }

    printReceipt() {
        if (!this.receiptData) {
            console.error('No receipt data available');
            return;
        }

        try {
            // Generate receipt content
            const receiptContent = this.generateReceiptContent();
            
            // Create print window
            const printWindow = window.open('', '_blank', 'width=300,height=600');
            
            if (!printWindow) {
                // Fallback: show alert if popup is blocked
                alert('Please allow popups for receipt printing, or use the browser print function.');
                return;
            }

            // Write receipt content to print window
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Receipt - ${this.receiptData.receiptNumber}</title>
                    <style>
                        body {
                            font-family: 'Courier New', monospace;
                            font-size: 12px;
                            line-height: 1.4;
                            margin: 0;
                            padding: 20px;
                            color: #000;
                            background: #fff;
                        }
                        .receipt-header {
                            text-align: center;
                            border-bottom: 2px solid #000;
                            padding-bottom: 10px;
                            margin-bottom: 15px;
                        }
                        .receipt-header h3 {
                            margin: 0 0 5px;
                            font-size: 16px;
                            font-weight: bold;
                        }
                        .receipt-info {
                            margin-bottom: 15px;
                            border-bottom: 1px dashed #000;
                            padding-bottom: 10px;
                        }
                        .receipt-items table {
                            width: 100%;
                            margin-bottom: 15px;
                            border-collapse: collapse;
                        }
                        .receipt-items th,
                        .receipt-items td {
                            text-align: left;
                            padding: 2px 5px;
                            border-bottom: 1px solid #ccc;
                        }
                        .receipt-items th {
                            border-bottom: 2px solid #000;
                            font-weight: bold;
                        }
                        .receipt-totals {
                            border-top: 1px dashed #000;
                            padding-top: 10px;
                        }
                        .total-line {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 5px;
                        }
                        .total-line.final {
                            border-top: 1px solid #000;
                            padding-top: 5px;
                            font-weight: bold;
                            font-size: 14px;
                        }
                        .receipt-footer {
                            text-align: center;
                            margin-top: 15px;
                            border-top: 1px dashed #000;
                            padding-top: 10px;
                            font-style: italic;
                        }
                        @media print {
                            body { margin: 0; padding: 10px; }
                        }
                    </style>
                </head>
                <body>
                    ${receiptContent}
                    <script>
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                                setTimeout(function() {
                                    window.close();
                                }, 1000);
                            }, 500);
                        };
                    </script>
                </body>
                </html>
            `);
            
            printWindow.document.close();

        } catch (error) {
            console.error('Print error:', error);
            this.showErrorMessage('Could not print receipt. Please try again.');
        }
    }

    generateReceiptContent() {
        const receipt = this.receiptData;
        const config = window.POSConfig;
        
        let itemsHtml = '';
        receipt.items.forEach(item => {
            const itemTotal = (parseFloat(item.price) * parseInt(item.quantity)).toFixed(2);
            itemsHtml += `
                <tr>
                    <td>${item.name}</td>
                    <td>${item.quantity}</td>
                    <td>${config.currencySymbol}${parseFloat(item.price).toFixed(2)}</td>
                    <td>${config.currencySymbol}${itemTotal}</td>
                </tr>
            `;
        });

        let cashDetailsHtml = '';
        if (receipt.paymentMethod === 'cash' && receipt.cashReceived) {
            cashDetailsHtml = `
                <div class="total-line">
                    <span>Cash Received:</span>
                    <span>${config.currencySymbol}${receipt.cashReceived.toFixed(2)}</span>
                </div>
                <div class="total-line">
                    <span>Change Given:</span>
                    <span>${config.currencySymbol}${receipt.changeAmount.toFixed(2)}</span>
                </div>
            `;
        }

        return `
            <div class="receipt-content">
                <div class="receipt-header">
                    <h3>${config.companyName}</h3>
                    <p>Point of Sale System</p>
                </div>
                
                <div class="receipt-info">
                    <p><strong>Receipt #:</strong> ${receipt.receiptNumber}</p>
                    <p><strong>Date:</strong> ${receipt.timestamp.toLocaleDateString()}</p>
                    <p><strong>Time:</strong> ${receipt.timestamp.toLocaleTimeString()}</p>
                    <p><strong>Payment:</strong> ${this.getPaymentMethodDisplayName(receipt.paymentMethod)}</p>
                </div>
                
                <div class="receipt-items">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                </div>
                
                <div class="receipt-totals">
                    <div class="total-line">
                        <span>Subtotal:</span>
                        <span>${config.currencySymbol}${receipt.subtotal.toFixed(2)}</span>
                    </div>
                    ${receipt.taxAmount > 0 ? `
                        <div class="total-line">
                            <span>Tax:</span>
                            <span>${config.currencySymbol}${receipt.taxAmount.toFixed(2)}</span>
                        </div>
                    ` : ''}
                    <div class="total-line final">
                        <span><strong>Total:</strong></span>
                        <span><strong>${config.currencySymbol}${receipt.totalAmount.toFixed(2)}</strong></span>
                    </div>
                    ${cashDetailsHtml}
                </div>
                
                <div class="receipt-footer">
                    <p>Thank you for your business!</p>
                    <p>Visit us again soon</p>
                </div>
            </div>
        `;
    }

    showErrorMessage(message) {
        // Create and show error toast/alert
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed';
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        alertDiv.innerHTML = `
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Error:</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 5000);
    }
}

// Global functions for page navigation
function closePage() {
    if (confirm('Are you sure you want to close without completing the payment?')) {
        window.location.href = 'sale.php';
    }
}

function goBack() {
    if (confirm('Return to POS without completing payment?')) {
        window.location.href = 'sale.php';
    }
}

function startNewSale() {
    // Clear cart session and redirect to POS
    window.location.href = 'sale.php?action=new';
}

// Initialize the application
function initializeQuickCheckout() {
    // Verify required configuration
    if (!window.POSConfig) {
        console.error('POS Configuration not found!');
        return;
    }
    
    // Initialize the quick checkout system
    new QuickCheckout();
    
    console.log('Quick Checkout initialized successfully');
}

// Export for use in other modules if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = QuickCheckout;
}
