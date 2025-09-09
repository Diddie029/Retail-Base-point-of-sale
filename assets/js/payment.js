// Payment Processing for POS System
(function(){
  let paymentAmount = 0;
  let selectedPaymentMethod = null;
  let transactionData = null;
  let currency = '$';
  
  function init(options = {}) {
    currency = options.currency || '$';
    paymentAmount = options.amount || 0;
    transactionData = options.transactionData || null;
    
    renderPaymentModal();
    bindPaymentMethods();
    bindPaymentActions();
  }
  
  function renderPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (!modal) return;
    
    const amountEl = modal.querySelector('.payment-amount');
    if (amountEl) {
      amountEl.textContent = `${currency} ${formatAmount(paymentAmount)}`;
    }
  }
  
  function bindPaymentMethods() {
    const methods = document.querySelectorAll('.payment-method');
    methods.forEach(method => {
      method.addEventListener('click', () => {
        methods.forEach(m => m.classList.remove('selected'));
        method.classList.add('selected');
        selectedPaymentMethod = method.dataset.method;
        
        // Show/hide cash payment section
        const cashSection = document.getElementById('cashPaymentSection');
        if (selectedPaymentMethod === 'cash') {
          cashSection.style.display = 'block';
          // Focus on cash input
          setTimeout(() => {
            const cashInput = document.getElementById('cashReceived');
            if (cashInput) {
              cashInput.focus();
            }
          }, 100);
        } else {
          cashSection.style.display = 'none';
        }
        
        // Enable/disable confirm button
        updateConfirmButton();
      });
    });
    
    // Bind cash input events
    const cashInput = document.getElementById('cashReceived');
    if (cashInput) {
      cashInput.addEventListener('input', calculateChange);
      cashInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          const confirmBtn = document.querySelector('.payment-btn.confirm');
          if (confirmBtn && !confirmBtn.disabled) {
            confirmBtn.click();
          }
        }
      });
    }
    
    // Bind quick amount buttons
    const quickAmountBtns = document.querySelectorAll('.quick-amount');
    quickAmountBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const amount = parseFloat(btn.dataset.amount) || 0;
        if (cashInput) {
          cashInput.value = amount;
          cashInput.focus();
          calculateChange();
        }
      });
    });
    
    // Bind exact amount button
    const exactAmountBtn = document.getElementById('exactAmountBtn');
    if (exactAmountBtn) {
      exactAmountBtn.addEventListener('click', () => {
        if (cashInput) {
          cashInput.value = paymentAmount;
          cashInput.focus();
          calculateChange();
        }
      });
    }
  }
  
  function bindPaymentActions() {
    // Cancel payment
    const cancelBtn = document.querySelector('.payment-btn.cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => {
        const modal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (modal) modal.hide();
      });
    }
    
    // Confirm payment
    const confirmBtn = document.querySelector('.payment-btn.confirm');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', () => {
        if (!selectedPaymentMethod) return;
        
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        
        // Simulate payment processing (in production, this would connect to payment processor)
        setTimeout(() => {
          processPayment(selectedPaymentMethod);
        }, 1500);
      });
    }
    
    // New transaction button in receipt modal
    const newTransactionBtn = document.querySelector('.receipt-btn.new-transaction');
    if (newTransactionBtn) {
      newTransactionBtn.addEventListener('click', () => {
        // Hide receipt modal
        const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
        if (receiptModal) receiptModal.hide();
        
        // Clear cart and redirect to sale page
        window.location.href = 'sale.php';
      });
    }
    
    // Cancel receipt button
    const cancelReceiptBtn = document.querySelector('.receipt-btn.cancel');
    if (cancelReceiptBtn) {
      cancelReceiptBtn.addEventListener('click', () => {
        // Hide receipt modal
        const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
        if (receiptModal) receiptModal.hide();
      });
    }
    
    // Print receipt button
    const printBtn = document.querySelector('.receipt-btn.print');
    if (printBtn) {
      printBtn.addEventListener('click', () => {
        // Open dedicated print receipt page
        const receiptData = getCurrentReceiptData();
        if (receiptData) {
          const printUrl = `print_receipt.php?data=${encodeURIComponent(JSON.stringify(receiptData))}`;
          window.open(printUrl, '_blank', 'width=800,height=600');
        }
      });
    }
    
    // Download receipt button
    const downloadBtn = document.querySelector('.receipt-btn.download');
    if (downloadBtn) {
      downloadBtn.addEventListener('click', () => {
        // In a real implementation, this would generate and download a PDF receipt
        alert('Receipt download functionality will be implemented in the next update.');
      });
    }
  }
  
  function processPayment(method) {
    // In production, this would make an API call to process payment
    // For now, simulate a successful payment
    
    const paymentData = {
      amount: paymentAmount,
      method: method,
      transaction_id: generateTransactionId(),
      timestamp: new Date().toISOString(),
      items: transactionData?.items || [],
      customer: transactionData?.customer || { name: 'Walk-in Customer' }
    };
    
    // Add cash payment details if cash method
    if (method === 'cash') {
      const cashInput = document.getElementById('cashReceived');
      const cashReceived = parseFloat(cashInput?.value) || 0;
      const changeDue = cashReceived - paymentAmount;
      
      paymentData.cash_received = cashReceived;
      paymentData.change_due = changeDue;
    }
    
    // Store payment data
    fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=process_payment&payment_data=${encodeURIComponent(JSON.stringify(paymentData))}`
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Hide payment modal
        const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (paymentModal) paymentModal.hide();
        
        // Show receipt modal
        showReceiptModal(paymentData, data.receipt_id);
      } else {
        alert('Payment processing failed: ' + (data.error || 'Unknown error'));
        
        // Reset confirm button
        const confirmBtn = document.querySelector('.payment-btn.confirm');
        if (confirmBtn) {
          confirmBtn.disabled = false;
          confirmBtn.innerHTML = 'Confirm Payment';
        }
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Payment processing failed. Please try again.');
      
      // Reset confirm button
      const confirmBtn = document.querySelector('.payment-btn.confirm');
      if (confirmBtn) {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = 'Confirm Payment';
      }
    });
  }
  
  function showReceiptModal(paymentData, receiptId) {
    const modal = document.getElementById('receiptModal');
    if (!modal) return;
    
    // Show modal
    const receiptModal = new bootstrap.Modal(modal);
    receiptModal.show();
    
    // Populate receipt data
    const receiptDate = new Date();
    const receiptItems = modal.querySelector('.receipt-items');
    const subtotalEl = modal.querySelector('.receipt-subtotal');
    const taxEl = modal.querySelector('.receipt-tax');
    const totalEl = modal.querySelector('.receipt-total');
    const transactionIdEl = modal.querySelector('.receipt-transaction-id');
    const dateEl = modal.querySelector('.receipt-date');
    const timeEl = modal.querySelector('.receipt-time');
    const paymentMethodEl = modal.querySelector('.receipt-payment-method');
    
    // Set transaction details
    if (transactionIdEl) transactionIdEl.textContent = paymentData.transaction_id;
    if (dateEl) dateEl.textContent = receiptDate.toLocaleDateString();
    if (timeEl) timeEl.textContent = receiptDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
    if (paymentMethodEl) paymentMethodEl.textContent = formatPaymentMethod(paymentData.method);
    
    // Calculate totals using actual tax rate from settings
    const taxRate = (window.POSConfig?.taxRate || 0) / 100; // Convert percentage to decimal
    const subtotal = paymentData.subtotal || paymentData.amount;
    const tax = subtotal * taxRate;
    const total = subtotal + tax;
    
    // Use currency symbol from global config
    const currencySymbol = window.POSConfig?.currencySymbol || '$';
    
    if (subtotalEl) subtotalEl.textContent = `${currencySymbol}${formatAmount(subtotal)}`;
    if (taxEl) taxEl.textContent = `${currencySymbol}${formatAmount(tax)}`;
    if (totalEl) totalEl.textContent = `${currencySymbol}${formatAmount(total)}`;
    
    // Show cash payment details if cash payment
    const cashDetailsEl = modal.querySelector('#receiptCashDetails');
    if (paymentData.method === 'cash' && paymentData.cash_received !== undefined && cashDetailsEl) {
      const cashReceivedEl = modal.querySelector('.receipt-cash-received');
      const changeDueEl = modal.querySelector('.receipt-change-due');
      
      if (cashReceivedEl) cashReceivedEl.textContent = `${currencySymbol}${formatAmount(paymentData.cash_received)}`;
      if (changeDueEl) changeDueEl.textContent = `${currencySymbol}${formatAmount(paymentData.change_due)}`;
      
      cashDetailsEl.style.display = 'block';
    } else if (cashDetailsEl) {
      cashDetailsEl.style.display = 'none';
    }
    
    // Populate items
    if (receiptItems && paymentData.items && paymentData.items.length > 0) {
      let itemsHtml = '';
      paymentData.items.forEach(item => {
        const itemTotal = item.price * item.quantity;
        itemsHtml += `
          <div class="receipt-item">
            <div class="receipt-item-details">
              <div class="receipt-item-name">${item.name}</div>
              <div class="receipt-item-qty">${item.quantity} × ${currencySymbol}${formatAmount(item.price)}</div>
            </div>
            <div class="receipt-item-price">${currencySymbol}${formatAmount(itemTotal)}</div>
          </div>
        `;
      });
      receiptItems.innerHTML = itemsHtml;
    }
  }
  
  function generateTransactionId() {
    // Generate a unique transaction ID
    // In production, this would be generated by the server
    const prefix = 'txn-';
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 10000);
    return `${prefix}${timestamp}${random}`;
  }
  
  function formatPaymentMethod(method) {
    const methods = {
      card: 'Credit/Debit Card',
      contactless: 'Contactless',
      cash: 'Cash',
      giftcard: 'Gift Card'
    };
    return methods[method] || method;
  }
  
  function formatAmount(amount) {
    return parseFloat(amount).toFixed(2);
  }
  
  function getCurrentReceiptData() {
    // Get current receipt data from the DOM
    const modal = document.getElementById('receiptModal');
    if (!modal) return null;
    
    return {
      transaction_id: modal.querySelector('.receipt-transaction-id')?.textContent || '',
      date: modal.querySelector('.receipt-date')?.textContent || '',
      time: modal.querySelector('.receipt-time')?.textContent || '',
      payment_method: modal.querySelector('.receipt-payment-method')?.textContent || '',
      subtotal: modal.querySelector('.receipt-subtotal')?.textContent || '',
      tax: modal.querySelector('.receipt-tax')?.textContent || '',
      total: modal.querySelector('.receipt-total')?.textContent || '',
      items: extractItemsFromDOM(modal),
      company_name: modal.querySelector('.receipt-shop-name')?.textContent || (window.POSConfig?.companyName || 'POS System'),
      company_address: modal.querySelector('.receipt-shop-address')?.textContent || (window.POSConfig?.companyAddress || 'No address provided')
    };
  }
  
  function extractItemsFromDOM(modal) {
    const items = [];
    const itemElements = modal.querySelectorAll('.receipt-item');
    
    itemElements.forEach(element => {
      const name = element.querySelector('.receipt-item-name')?.textContent || '';
      const qty = element.querySelector('.receipt-item-qty')?.textContent || '';
      const price = element.querySelector('.receipt-item-price')?.textContent || '';
      
      if (name) {
        items.push({ name, qty, price });
      }
    });
    
    return items;
  }
  
  function calculateChange() {
    const cashInput = document.getElementById('cashReceived');
    const changeDisplay = document.getElementById('changeDisplay');
    const changeAmount = document.getElementById('changeAmount');
    const changeStatus = document.getElementById('changeStatus');
    
    if (!cashInput || !changeDisplay || !changeAmount) return;
    
    const cashReceived = parseFloat(cashInput.value) || 0;
    const change = cashReceived - paymentAmount;
    const currencySymbol = window.POSConfig?.currencySymbol || '$';
    
    // Update change display
    if (change >= 0) {
      changeAmount.textContent = `${currencySymbol} ${formatAmount(change)}`;
      changeAmount.style.color = '#10b981'; // Success color
      
      // Update status indicator
      if (changeStatus) {
        changeStatus.innerHTML = '<i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>';
      }
      
      // Update display styling
      changeDisplay.style.background = 'linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%)';
      changeDisplay.style.borderColor = '#10b981 !important';
    } else {
      changeAmount.textContent = `${currencySymbol} ${formatAmount(Math.abs(change))} insufficient`;
      changeAmount.style.color = '#dc3545'; // Danger color
      
      // Update status indicator
      if (changeStatus) {
        changeStatus.innerHTML = '<i class="bi bi-exclamation-triangle text-warning" style="font-size: 1.2rem;"></i>';
      }
      
      // Update display styling
      changeDisplay.style.background = 'linear-gradient(135deg, #fef2f2 0%, #fecaca 100%)';
      changeDisplay.style.borderColor = '#dc3545 !important';
    }
    
    // Update confirm button
    updateConfirmButton();
  }
  
  function updateConfirmButton() {
    const confirmBtn = document.querySelector('.payment-btn.confirm');
    if (!confirmBtn) return;
    
    let canConfirm = selectedPaymentMethod !== null;
    
    // For cash payments, check if sufficient amount is received
    if (selectedPaymentMethod === 'cash') {
      const cashInput = document.getElementById('cashReceived');
      const cashReceived = parseFloat(cashInput?.value) || 0;
      canConfirm = cashReceived >= paymentAmount;
    }
    
    confirmBtn.disabled = !canConfirm;
  }
  
  function resetPaymentModal() {
    // Reset payment method selection
    selectedPaymentMethod = null;
    document.querySelectorAll('.payment-method').forEach(method => {
      method.classList.remove('selected');
    });
    
    // Hide cash payment section
    const cashSection = document.getElementById('cashPaymentSection');
    if (cashSection) {
      cashSection.style.display = 'none';
    }
    
    // Reset cash input
    const cashInput = document.getElementById('cashReceived');
    if (cashInput) {
      cashInput.value = '';
    }
    
    // Reset change display
    const changeAmount = document.getElementById('changeAmount');
    if (changeAmount) {
      const currencySymbol = window.POSConfig?.currencySymbol || '$';
      changeAmount.textContent = `${currencySymbol}0.00`;
    }
    
    const changeDisplay = document.getElementById('changeDisplay');
    if (changeDisplay) {
      changeDisplay.classList.remove('positive', 'negative');
    }
    
    // Disable confirm button
    const confirmBtn = document.querySelector('.payment-btn.confirm');
    if (confirmBtn) {
      confirmBtn.disabled = true;
      confirmBtn.innerHTML = 'Confirm Payment';
    }
  }
  
  // Expose functions to window
  window.PaymentProcessor = { init, getCurrentReceiptData, resetPaymentModal };
})();
