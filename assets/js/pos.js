// Complete POS System Logic for sale.php
// Handles all POS functionality including cart, payments, transactions
// Uses Bootstrap modals and integrates with existing sale.php endpoints
(function(){
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  let cart = [];
  let currentCustomer = null;
  let currency = '$';

  function format(n){
    return (Number(n)||0).toFixed(2);
  }

  function init(opts){
    currency = opts.currency || '$';
    currentCustomer = opts.customer;

    // Handle cart cleared notification
    if (opts.cartCleared) {
      showNotification('Started new sale - cart cleared', 'success');
    }

    // Restore cart from session if available
    console.log('Cart restoration data:', opts.storedCart);
    if (opts.storedCart && Array.isArray(opts.storedCart) && opts.storedCart.length > 0) {
      console.log('Restoring cart with', opts.storedCart.length, 'items');
      
      // Clear existing cart first
      cart = [];
      
      // Restore each item
      opts.storedCart.forEach(item => {
        if (item && item.id && item.name && item.price) {
          cart.push({
            id: item.id,
            name: item.name,
            price: parseFloat(item.price),
            quantity: parseInt(item.quantity) || 1,
            tax_rate: parseFloat(item.tax_rate || 0),
            is_auto_bom: item.is_auto_bom || false,
            base_product_id: item.base_product_id || null,
            selling_unit_id: item.selling_unit_id || null
          });
        }
      });

      console.log('Restored cart:', cart);
      console.log('Total items in restored cart:', cart.length);
      console.log('Total quantity in restored cart:', cart.reduce((sum, item) => sum + item.quantity, 0));

      // Show notification that cart was restored (only if not just cleared)
      if (!opts.cartCleared) {
        const totalQuantity = cart.reduce((sum, item) => sum + item.quantity, 0);
        showNotification(`Cart restored from previous session - ${totalQuantity} item${totalQuantity !== 1 ? 's' : ''}`, 'info');
      }
    } else {
      console.log('No cart data to restore or cart is empty');
      cart = []; // Ensure cart is empty
    }

    bindFilters();
    bindProductGrid();
    bindCartActions();
    updateCartUI();
    
    // Load held transactions count on initialization
    loadHeldCount();
    
    // Save current cart state to session after initialization
    setTimeout(saveCartToSession, 200);

    // Autofocus product search so barcode scanners type into it by default
    setTimeout(() => {
      const searchInput = document.getElementById('productSearch');
      if (searchInput) {
        try { searchInput.focus(); } catch(e) { /* ignore */ }
      }
    }, 300);
  }

  function bindFilters(){
    const search = $('#productSearch');
    const grid = $('#productGrid');
    const searchSuggestions = $('#searchSuggestions');
    const suggestionsList = $('#suggestionsList');
    
    let searchTimeout;
    let selectedSuggestionIndex = -1;
    let allProducts = [];
    
    // Initialize products data from the DOM
    if (grid) {
      allProducts = $$('.product-card', grid).map(card => ({
        id: card.dataset.productId,
        name: card.dataset.productName,
        sku: card.dataset.productSku,
        barcode: card.dataset.productBarcode,
    price: parseFloat(card.dataset.productPrice || card.dataset.productPrice === 0 ? card.dataset.productPrice : card.dataset.productPrice) || 0,
        stock: parseInt(card.dataset.productStock),
        category: card.dataset.categoryId,
        searchText: card.dataset.searchText,
        element: card
      }));
    }
    
    // Enhanced search function with suggestions
    const applySearch = (term) => {
      const activeCategory = $('.category-tab.active')?.dataset.category;
      
      // Filter products based on search term and active category
      const filteredProducts = allProducts.filter(product => {
        const matchesSearch = !term || 
          product.searchText.includes(term) || 
          product.name.toLowerCase().includes(term) ||
          (product.sku && product.sku.toLowerCase().includes(term)) ||
          (product.barcode && product.barcode.toLowerCase().includes(term));
        
        const matchesCategory = !activeCategory || activeCategory === '' || product.category === activeCategory;
        
        return matchesSearch && matchesCategory;
      });
      
      // Filter product grid
      allProducts.forEach(product => {
        const shouldShow = filteredProducts.includes(product);
        product.element.style.display = shouldShow ? '' : 'none';
      });
      
      return filteredProducts;
    };
    
    // Category tab filtering
    const applyCategoryFilter = (categoryId) => {
      // Update active category tab
      $$('.category-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.category === categoryId) {
          tab.classList.add('active');
        }
      });
      
      // Re-apply search with new category
      const term = (search?.value || '').toLowerCase().trim();
      applySearch(term);
      
      // Update suggestions if search is active
      if (term && searchSuggestions) {
        const filteredProducts = applySearch(term);
        displaySuggestions(filteredProducts, term);
      }
    };
    
    // Search input handler with suggestions
    if (search && searchSuggestions && suggestionsList) {
      search.addEventListener('input', function() {
        const raw = this.value.trim();
        const term = raw.toLowerCase();
        selectedSuggestionIndex = -1;
        
        clearTimeout(searchTimeout);

        // If user scanned or typed an exact barcode/sku/product code (no spaces)
        // and it matches a product, auto-add it to cart. We require length >= 3
        // to avoid false positives during normal typing.
        if (raw.length >= 3 && !raw.includes(' ')) {
          const exactMatch = allProducts.find(p => (p.barcode && p.barcode.toLowerCase() === term) ||
                                                 (p.sku && p.sku.toLowerCase() === term) ||
                                                 (p.id && String(p.id) === raw));
          if (exactMatch) {
            // Simulate click to reuse existing add-to-cart flow (handles auto-bom etc.)
            exactMatch.element.click();
            // Clear search box and suggestions
            this.value = '';
            hideSuggestions();
            applySearch('');
            return;
          }
        }

        if (term.length === 0) {
          hideSuggestions();
          applySearch('');
          return;
        }

        searchTimeout = setTimeout(() => {
          const filteredProducts = applySearch(term);
          displaySuggestions(filteredProducts, term);
        }, 150);
      });
      
      // Handle keyboard navigation
      search.addEventListener('keydown', function(e) {
        const suggestions = $$('.suggestion-item', suggestionsList);
        
        switch (e.key) {
          case 'ArrowDown':
            e.preventDefault();
            selectedSuggestionIndex = Math.min(selectedSuggestionIndex + 1, suggestions.length - 1);
            updateSuggestionHighlight();
            break;
          case 'ArrowUp':
            e.preventDefault();
            selectedSuggestionIndex = Math.max(selectedSuggestionIndex - 1, -1);
            updateSuggestionHighlight();
            break;
          case 'Enter':
            e.preventDefault();
            // If a suggestion is selected, use it
            if (selectedSuggestionIndex >= 0 && suggestions[selectedSuggestionIndex]) {
              selectSuggestion(suggestions[selectedSuggestionIndex]);
              return;
            }

            // Otherwise, try exact-match add (useful for scanner Enter)
            const raw = this.value.trim();
            const termLower = raw.toLowerCase();
            if (raw.length >= 1 && !raw.includes(' ')) {
              const exactMatch = allProducts.find(p => (p.barcode && p.barcode.toLowerCase() === termLower) ||
                                                     (p.sku && p.sku.toLowerCase() === termLower) ||
                                                     (p.id && String(p.id) === raw));
              if (exactMatch) {
                exactMatch.element.click();
                this.value = '';
                hideSuggestions();
                applySearch('');
                return;
              }
            }

            // Fall back to selecting first suggestion if present
            if (suggestions.length > 0) selectSuggestion(suggestions[0]);
            break;
          case 'Escape':
            hideSuggestions();
            this.blur();
            break;
        }
      });
    } else if (search) {
      // Fallback to simple search if suggestions elements not found
      search.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        applySearch(term);
      });
    }
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
      if (search && !search.parentElement.contains(e.target)) {
        hideSuggestions();
      }
    });
    
    function displaySuggestions(products, searchTerm) {
      if (!suggestionsList) return;
      
      suggestionsList.innerHTML = '';
      
      if (products.length === 0) {
        suggestionsList.innerHTML = `
          <div class="no-suggestions">
            <i class="bi bi-search"></i>
            No products found for "${escapeHtml(searchTerm)}"
          </div>
        `;
      } else {
        // Limit to 8 suggestions for performance
        const limitedProducts = products.slice(0, 8);
        
        limitedProducts.forEach((product, index) => {
          const suggestionItem = createSuggestionItem(product, searchTerm);
          suggestionsList.appendChild(suggestionItem);
        });
      }
      
      if (searchSuggestions) {
        searchSuggestions.style.display = 'block';
      }
    }
    
    function createSuggestionItem(product, searchTerm) {
      const item = document.createElement('div');
      item.className = 'suggestion-item';
      item.dataset.productId = product.id;
      
      const stockClass = product.stock <= 0 ? 'out-of-stock' : (product.stock <= 10 ? 'low-stock' : '');
      
      item.innerHTML = `
        <div class="suggestion-icon">
          <i class="bi bi-box"></i>
        </div>
        <div class="suggestion-content">
          <div class="suggestion-name">${highlightText(escapeHtml(product.name), searchTerm)}</div>
          <div class="suggestion-details">
            ${product.sku ? `SKU: ${highlightText(escapeHtml(product.sku), searchTerm)}` : ''}
            ${product.barcode ? `• Barcode: ${highlightText(escapeHtml(product.barcode), searchTerm)}` : ''}
            <span class="suggestion-stock ${stockClass}">${product.stock} in stock</span>
          </div>
        </div>
        <div class="suggestion-price">
          ${currency} ${format(product.price)}
        </div>
      `;
      
      // Add click handler
      item.addEventListener('click', function() {
        selectSuggestion(this);
      });
      
      return item;
    }
    
    function selectSuggestion(suggestionItem) {
      const productId = suggestionItem.dataset.productId;
      const product = allProducts.find(p => p.id === productId);
      
      if (product) {
        // Simulate click on product to add to cart
        product.element.click();
        
        // Clear search and hide suggestions
        if (search) {
          search.value = '';
        }
        hideSuggestions();
        applySearch('');
      }
    }
    
    function updateSuggestionHighlight() {
      if (!suggestionsList) return;
      
      const suggestions = $$('.suggestion-item', suggestionsList);
      suggestions.forEach((suggestion, index) => {
        suggestion.classList.toggle('highlighted', index === selectedSuggestionIndex);
      });
    }
    
    function hideSuggestions() {
      if (searchSuggestions) {
        searchSuggestions.style.display = 'none';
      }
      selectedSuggestionIndex = -1;
    }
    
    function highlightText(text, searchTerm) {
      if (!searchTerm) return text;
      
      const regex = new RegExp(`(${escapeRegex(searchTerm)})`, 'gi');
      return text.replace(regex, '<strong>$1</strong>');
    }
    
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
    function escapeRegex(text) {
      return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    
    // Bind category tabs
    $$('.category-tab').forEach(tab => {
      tab.addEventListener('click', (e) => {
        e.preventDefault();
        const categoryId = tab.dataset.category || '';
        applyCategoryFilter(categoryId);
        
        // Clear search when switching categories
        if (search) {
          search.value = '';
          hideSuggestions();
        }
      });
    });
    
    // Bind barcode scanner button
    const scanBtn = $('#barcodeScanBtn');
    scanBtn?.addEventListener('click', () => {
      new bootstrap.Modal($('#barcodeScanModal')).show();
    });
  }

  function bindProductGrid(){
    const grid = $('#productGrid');
    if(!grid) return;
    
    grid.addEventListener('click', (e)=>{
      const card = e.target.closest('.product-card');
      if(!card) return;
      
      // Check if product is out of stock
      const stock = parseInt(card.dataset.productStock) || 0;
      if (stock <= 0) {
        showNotification('Product is out of stock', 'warning');
        return;
      }
      
      const isAuto = card.dataset.isAutoBom === 'true';
      
      // Add visual feedback immediately
      card.classList.add('adding-to-cart');
      
      // Handle Auto BOM products - add directly to cart like regular products
      if(isAuto){
        // For Auto BOM products, we'll add them as regular products for now
        // In the future, you can add logic to select the default selling unit
        console.log('Auto BOM product clicked - adding as regular product');
      }
      
      // Simple add to cart for regular products
      const product = {
        id: card.dataset.productId,
        name: card.dataset.productName,
        price: parseFloat(card.dataset.productPrice),
        quantity: 1,
        tax_rate: parseFloat(card.dataset.productTaxRate) || 0,
        is_auto_bom: false
      };
      
      // Add to cart
      addToCart(product);
      
      // Show success feedback
      setTimeout(() => {
        card.classList.remove('adding-to-cart');
        showSuccessFeedback(card, 'Added to cart!');
        
        // Optional: Brief highlight effect
        card.classList.add('just-added');
        setTimeout(() => {
          card.classList.remove('just-added');
        }, 1000);
      }, 100);
    });
  }

  function feedback(node){
    const tag = document.createElement('div');
    tag.className = 'success-feedback';
    tag.innerHTML = '<i class="bi bi-check2-circle"></i> Added';
    node.style.position = 'relative';
    node.appendChild(tag);
    setTimeout(()=>tag.remove(), 1200);
  }
  
  // Enhanced success feedback function
  function showSuccessFeedback(node, message = 'Added to cart!') {
    const tag = document.createElement('div');
    tag.className = 'success-feedback';
    tag.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${message}`;
    node.style.position = 'relative';
    node.appendChild(tag);
    setTimeout(() => tag.remove(), 1500);
  }
  
  // Notification system
  function showNotification(message, type = 'info') {
    // Create notification container if it doesn't exist
    let container = document.querySelector('.notification-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'notification-container';
      document.body.appendChild(container);
    }
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    const icon = type === 'success' ? 'check-circle-fill' : 
                type === 'warning' ? 'exclamation-triangle-fill' : 
                type === 'error' ? 'x-circle-fill' : 'info-circle-fill';
    
    notification.innerHTML = `
      <i class="bi bi-${icon}"></i>
      <span>${message}</span>
      <button class="notification-close" onclick="this.parentElement.remove()">
        <i class="bi bi-x"></i>
      </button>
    `;
    
    container.appendChild(notification);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
      if (notification.parentElement) {
        notification.remove();
      }
    }, 5000);
    
    // Add entrance animation
    setTimeout(() => {
      notification.classList.add('show');
    }, 10);
  }

  function bindCartActions(){
    $('#clearCart')?.addEventListener('click', ()=>{
      if(cart.length===0){
        showNotification('Cart is already empty', 'info');
        return;
      }
      
      const totalQuantity = cart.reduce((sum, item) => sum + item.quantity, 0);
      if(confirm(`Clear the cart? This will remove ${totalQuantity} item${totalQuantity !== 1 ? 's' : ''}.`)){
        console.log('Clearing cart, current cart:', cart);
        cart = [];
        updateCartUI();
        saveCartToSession();
        showNotification(`Cart cleared - ${totalQuantity} item${totalQuantity !== 1 ? 's' : ''} removed`, 'success');
      }
    });

    $('#holdTransactionBtn')?.addEventListener('click', ()=>{
      if(cart.length===0) return;
      $('#holdTransactionModal') && new bootstrap.Modal($('#holdTransactionModal')).show();
    });

    $('#confirmHoldTransaction')?.addEventListener('click', ()=>{
      const reason = $('#holdReason')?.value || '';
      const customerRef = $('#customerReference')?.value || '';
      const payload = {
        items: cart,
        customer: currentCustomer,
        total: cart.reduce((t,i)=>t+i.price*i.quantity,0)
      };
      fetch('',{
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=hold_transaction&cart_data=${encodeURIComponent(JSON.stringify(payload))}&reason=${encodeURIComponent(reason)}&customer_reference=${encodeURIComponent(customerRef)}`
      }).then(r=>r.json()).then(d=>{
        if(d.success){
          showNotification(`Transaction held successfully - ID: #${d.hold_id}`, 'success');
          cart=[]; updateCartUI();
          saveCartToSession(); // Clear session cart
          bootstrap.Modal.getInstance($('#holdTransactionModal'))?.hide();
        } else {
          showNotification(d.error || 'Failed to hold transaction', 'error');
        }
      }).catch(error => {
        console.error('Error holding transaction:', error);
        showNotification('Network error - failed to hold transaction', 'error');
      })
    });

    $('#viewHeldBtn')?.addEventListener('click', ()=>{
      loadHeldTransactions();
    });

    // Add event listener for modal close to refresh held count
    $('#heldTransactionsModal')?.addEventListener('hidden.bs.modal', ()=>{
      loadHeldCount();
    });

    $('#checkoutBtn')?.addEventListener('click', ()=>{
      if(cart.length===0) return;
      // Use the new enhanced quick checkout system
      openEnhancedQuickCheckout();
    });
  }

  function updateCartUI(){
    console.log('Updating cart UI, current cart:', cart);
    
    const itemsEl = $('#cartItems');
    const totalEl = $('#cartTotal');
    const subtotalEl = $('#cartSubtotal');
    const taxEl = $('#cartTax');
    const taxRowEl = $('#cartTaxRow');
    const taxLabelEl = $('#cartTaxLabel');
    const multipleTaxRowsEl = $('#cartMultipleTaxRows');
    const countEl = $('#cartItemCount');
    const checkout = $('#checkoutBtn');
    const hold = $('#holdTransactionBtn');

    if(cart.length===0){
      console.log('Cart is empty, showing empty state');
      itemsEl.innerHTML = '<div class="empty-cart"><i class="bi bi-cart"></i><p>No items in cart</p></div>';
      if(totalEl) totalEl.textContent = `${currency} 0.00`;
      if(subtotalEl) subtotalEl.textContent = `${currency} 0.00`;
      if(taxEl) taxEl.textContent = `${currency} 0.00`;
      if(taxRowEl) taxRowEl.style.display = 'none';
      if(multipleTaxRowsEl) multipleTaxRowsEl.innerHTML = '';
      countEl.textContent = '0';
      if(checkout) checkout.disabled = true;
      if(hold) hold.disabled = true;
      
      // Save empty cart to session
      setTimeout(saveCartToSession, 100);
      return;
    }

    // Render cart items
    let html = '';
    let count = 0;
    cart.forEach((it, index) => {
      const line = it.price*it.quantity; 
      count+=it.quantity;
      const taxRate = it.tax_rate || 0;
      
      console.log(`Rendering cart item ${index + 1}:`, it);
      
      html += `
        <div class="cart-item">
          <div class="cart-item-image"><i class="bi bi-box"></i></div>
          <div class="cart-item-info">
            <div class="cart-item-name">${it.name}</div>
            <div class="cart-item-price">${currency} ${format(it.price)} each ${it.is_auto_bom?'<span class="badge bg-info ms-1">Auto BOM</span>':''} ${taxRate > 0 ? `<small class="text-muted">(Tax: ${taxRate}%)</small>` : ''}</div>
          </div>
          <div class="cart-item-controls">
            <div class="quantity-controls">
              <button class="quantity-btn" data-act="dec" data-id="${it.id}"><i class="bi bi-dash"></i></button>
              <div class="quantity-display">${it.quantity}</div>
              <button class="quantity-btn" data-act="inc" data-id="${it.id}"><i class="bi bi-plus"></i></button>
            </div>
            <div class="cart-item-total">${currency} ${format(line)}</div>
            <button class="remove-item-btn" data-act="del" data-id="${it.id}"><i class="bi bi-trash"></i></button>
          </div>
        </div>`;
    });
    
    console.log('Total quantity to display:', count);
    console.log('Cart items HTML length:', html.length);
    
    itemsEl.innerHTML = html;
    countEl.textContent = String(count);
    if(checkout) checkout.disabled = false;
    if(hold) hold.disabled = false;

    // Bind cart item actions
    itemsEl.querySelectorAll('[data-act]')?.forEach(btn=>{
      btn.addEventListener('click', (e)=>{
        const id = e.currentTarget.dataset.id;
        const act = e.currentTarget.dataset.act;
        const idx = cart.findIndex(x=>x.id===id);
        if(idx<0) return;
        if(act==='inc') cart[idx].quantity++;
        if(act==='dec') cart[idx].quantity = Math.max(1, cart[idx].quantity-1);
        if(act==='del') cart.splice(idx,1);
        updateCartUI();

        // Auto-save cart after quantity/item changes
        setTimeout(saveCartToSession, 100);
      });
    });

    // Calculate tax via API
    calculateCartTax();
    
    // Save cart to session after UI update
    setTimeout(saveCartToSession, 100);
  }

  function calculateCartTax() {
    const payload = { items: cart };
    
    fetch('', {
      method: 'POST', 
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: `action=calculate_tax&cart_data=${encodeURIComponent(JSON.stringify(payload))}`
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        updateTaxDisplay(data);
      } else {
        console.error('Tax calculation failed:', data.error);
        // Fallback to simple calculation
        updateTaxDisplay({
          subtotal: cart.reduce((t,i) => t + i.price * i.quantity, 0),
          total_tax: 0,
          total: cart.reduce((t,i) => t + i.price * i.quantity, 0),
          unique_tax_rates: [],
          tax_name: 'Tax'
        });
      }
    })
    .catch(err => {
      console.error('Tax calculation error:', err);
      // Fallback calculation
      const subtotal = cart.reduce((t,i) => t + i.price * i.quantity, 0);
      updateTaxDisplay({
        subtotal: subtotal,
        total_tax: 0,
        total: subtotal,
        unique_tax_rates: [],
        tax_name: 'Tax'
      });
    });
  }

  function updateTaxDisplay(data) {
    const subtotalEl = $('#cartSubtotal');
    const totalEl = $('#cartTotal');
    const taxEl = $('#cartTax');
    const taxRowEl = $('#cartTaxRow');
    const taxLabelEl = $('#cartTaxLabel');
    const multipleTaxRowsEl = $('#cartMultipleTaxRows');

    // Update subtotal and total
    if(subtotalEl) subtotalEl.textContent = `${currency} ${format(data.subtotal)}`;
    if(totalEl) totalEl.textContent = `${currency} ${format(data.total)}`;

    // Handle tax display
    if (data.total_tax > 0) {
      if (data.unique_tax_rates && data.unique_tax_rates.length > 1) {
        // Multiple tax rates - show breakdown
        if(taxRowEl) taxRowEl.style.display = 'none';
        if(multipleTaxRowsEl) {
          let taxHtml = '';
          data.unique_tax_rates.forEach(tax => {
            taxHtml += `
              <div class="cart-total-row">
                <span>${data.tax_name} (${format(tax.rate)}%):</span>
                <span>${currency} ${format(tax.amount)}</span>
              </div>
            `;
          });
          multipleTaxRowsEl.innerHTML = taxHtml;
        }
      } else {
        // Single tax rate or mixed rates shown as total
        if(multipleTaxRowsEl) multipleTaxRowsEl.innerHTML = '';
        if(taxRowEl) taxRowEl.style.display = 'flex';
        if(taxLabelEl) {
          const avgRate = data.subtotal > 0 ? (data.total_tax / data.subtotal) * 100 : 0;
          taxLabelEl.textContent = `${data.tax_name} (${format(avgRate)}%):`;
        }
        if(taxEl) taxEl.textContent = `${currency} ${format(data.total_tax)}`;
      }
    } else {
      // No tax
      if(taxRowEl) taxRowEl.style.display = 'none';
      if(multipleTaxRowsEl) multipleTaxRowsEl.innerHTML = '';
    }
  }

  // Auto-save cart to session
  function saveCartToSession() {
    const cartData = {
      items: cart,
      customer: currentCustomer,
      total: cart.reduce((t,i)=>t+i.price*i.quantity,0),
      timestamp: new Date().toISOString()
    };

    console.log('Saving cart to session:', cartData);

    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=store_cart&cart_data=' + encodeURIComponent(JSON.stringify(cartData))
    }).then(response => {
      if (response.ok) {
        console.log('Cart saved to session successfully');
      } else {
        console.error('Failed to save cart to session:', response.status);
      }
    }).catch(err => {
      console.error('Failed to save cart to session:', err);
    });
  }

  // Clear cart from session
  function clearCartFromSession() {
    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=store_cart&cart_data=' + encodeURIComponent(JSON.stringify({items: [], customer: currentCustomer, total: 0}))
    }).catch(err => {
      console.error('Failed to clear cart from session:', err);
    });
  }

  function addToCart(item){
    console.log('Adding to cart:', item);
    
    const exist = cart.find(x=>x.id===item.id);
    if(exist) {
      exist.quantity += item.quantity || 1;
      console.log('Updated existing item quantity to:', exist.quantity);
    } else {
      cart.push({...item, quantity: item.quantity||1});
      console.log('Added new item to cart');
    }
    
    console.log('Cart after adding:', cart);
    console.log('Total items in cart:', cart.length);
    console.log('Total quantity in cart:', cart.reduce((sum, item) => sum + item.quantity, 0));
    
    updateCartUI();

    // Auto-save cart after adding item
    setTimeout(saveCartToSession, 100);
  }

  // Auto BOM Selling Units Modal Handler
  function showSellingUnitsModal(productId, productName) {
    const modal = $('#sellingUnitsModal');
    const modalTitle = modal?.querySelector('.modal-title');
    const sellingUnitsList = $('#sellingUnitsList');
    const confirmBtn = $('#confirmSellingUnit');
    
    if (!modal || !sellingUnitsList) {
      console.error('Selling units modal elements not found');
      return;
    }
    
    // Update modal title
    if (modalTitle) {
      modalTitle.textContent = `Select Selling Unit - ${productName}`;
    }
    
    // Show loading state
    sellingUnitsList.innerHTML = `
      <div class="text-center p-4">
        <div class="spinner-border" role="status"></div>
        <p class="mt-2">Loading selling units...</p>
      </div>
    `;
    
    // Disable confirm button initially
    if (confirmBtn) {
      confirmBtn.disabled = true;
    }
    
    // Show modal
    new bootstrap.Modal(modal).show();
    
    // Fetch selling units from server
    fetch(`?action=get_auto_bom_units&base_product_id=${productId}`)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.selling_units) {
          renderSellingUnits(data.selling_units, productId, productName);
        } else {
          sellingUnitsList.innerHTML = `
            <div class="alert alert-warning">
              <i class="bi bi-exclamation-triangle"></i>
              ${data.error || 'No selling units available for this product'}
            </div>
          `;
        }
      })
      .catch(error => {
        console.error('Error loading selling units:', error);
        sellingUnitsList.innerHTML = `
          <div class="alert alert-danger">
            <i class="bi bi-x-circle"></i>
            Failed to load selling units. Please try again.
          </div>
        `;
      });
  }
  
  function renderSellingUnits(units, baseProductId, baseProductName) {
    const sellingUnitsList = $('#sellingUnitsList');
    const confirmBtn = $('#confirmSellingUnit');
    
    if (!units || units.length === 0) {
      sellingUnitsList.innerHTML = `
        <div class="alert alert-info">
          <i class="bi bi-info-circle"></i>
          No selling units configured for this product.
        </div>
      `;
      return;
    }
    
    let html = '<div class="selling-units-grid">';
    
    units.forEach(unit => {
      html += `
        <div class="selling-unit-card" data-unit-id="${unit.id}" data-unit-name="${unit.unit_name}" data-unit-price="${unit.calculated_price || 0}">
          <div class="selling-unit-icon">
            <i class="bi bi-box-seam"></i>
          </div>
          <div class="selling-unit-info">
            <h6 class="selling-unit-name">${unit.unit_name}</h6>
            <p class="selling-unit-description">${unit.description || 'No description'}</p>
            <div class="selling-unit-price">${unit.formatted_price || 'Price unavailable'}</div>
            <small class="text-muted">Quantity: ${unit.unit_quantity}</small>
          </div>
        </div>
      `;
    });
    
    html += '</div>';
    sellingUnitsList.innerHTML = html;
    
    // Bind click handlers to selling unit cards
    let selectedUnit = null;
    
    sellingUnitsList.querySelectorAll('.selling-unit-card').forEach(card => {
      card.addEventListener('click', () => {
        // Remove previous selection
        sellingUnitsList.querySelectorAll('.selling-unit-card').forEach(c => 
          c.classList.remove('selected')
        );
        
        // Select current card
        card.classList.add('selected');
        selectedUnit = {
          id: card.dataset.unitId,
          name: card.dataset.unitName,
          price: parseFloat(card.dataset.unitPrice)
        };
        
        // Enable confirm button
        if (confirmBtn) {
          confirmBtn.disabled = false;
        }
      });
    });
    
    // Handle confirm button
    if (confirmBtn) {
      // Remove existing event listeners
      const newBtn = confirmBtn.cloneNode(true);
      confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
      
      newBtn.addEventListener('click', () => {
        if (selectedUnit) {
          // Add Auto BOM product to cart
          const product = {
            id: `${baseProductId}_${selectedUnit.id}`,
            name: `${baseProductName} (${selectedUnit.name})`,
            price: selectedUnit.price,
            quantity: 1,
            is_auto_bom: true,
            base_product_id: baseProductId,
            selling_unit_id: selectedUnit.id
          };
          
          addToCart(product);
          
          // Hide modal
          bootstrap.Modal.getInstance($('#sellingUnitsModal'))?.hide();
          
          // Show success notification
          showNotification(`Added ${product.name} to cart`, 'success');
        }
      });
    }
  }

  function renderHeld(list){
    const container = $('#heldTransactionsList');
    if(!list || list.length===0){
      container.innerHTML = '<div class="text-center text-muted p-4"><i class="bi bi-inbox" style="font-size: 3rem;"></i><p class="mt-2">No held transactions</p></div>';
      return;
    }
    let html='';
    list.forEach(t=>{
      const dt = new Date(t.created_at);
      const timeAgo = getTimeAgo(t.created_at);
      const duration = getDurationHeld(t.created_at);
      const timestamp = formatTimestamp(t.created_at);
      
      html += `
        <div class="card mb-2"><div class="card-body d-flex justify-content-between align-items-start">
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="fw-bold">Hold #${t.id}</div>
              <div class="text-end">
                <small class="text-muted d-block">${timeAgo}</small>
                <small class="text-info fw-bold">${duration}</small>
              </div>
            </div>
            <div class="mb-2">
              <small class="text-muted">
                <i class="bi bi-person-circle me-1"></i>
                <strong>Held by:</strong> ${t.cashier_name}
              </small>
            </div>
            <div class="mb-2">
              <small class="text-muted">
                <i class="bi bi-clock me-1"></i>
                <strong>Held at:</strong> ${timestamp}
              </small>
            </div>
            <div class="mb-1">
              <small class="text-muted">
                <i class="bi bi-box-seam me-1"></i>
                <strong>Items:</strong> ${t.item_count} · 
                <i class="bi bi-currency-dollar me-1"></i>
                <strong>Total:</strong> ${currency} ${format(t.total_amount)}
              </small>
            </div>
            ${t.reason?`<div class="mb-1"><small class="text-muted"><i class="bi bi-chat-text me-1"></i><strong>Reason:</strong> ${t.reason}</small></div>`:''}
            ${t.customer_reference?`<div class="mb-1"><small class="text-muted"><i class="bi bi-person-badge me-1"></i><strong>Customer:</strong> ${t.customer_reference}</small></div>`:''}
          </div>
          <div class="btn-group-vertical ms-3">
            <button class="btn btn-sm btn-success" data-held-act="resume" data-id="${t.id}">
              <i class="bi bi-play-circle me-1"></i> Resume
            </button>
            <button class="btn btn-sm btn-outline-danger" data-held-act="delete" data-id="${t.id}">
              <i class="bi bi-trash me-1"></i> Delete
            </button>
          </div>
        </div></div>`;
    });
    container.innerHTML = html;

    container.querySelectorAll('[data-held-act]')?.forEach(btn=>{
      btn.addEventListener('click', (e)=>{
        const id = e.currentTarget.dataset.id;
        const act = e.currentTarget.dataset.heldAct;
        if(act==='resume') resumeHeld(id);
        if(act==='delete') deleteHeld(id);
      });
    });
  }

  function resumeHeld(id){
    const wasCartEmpty = cart.length === 0;

    fetch('',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=resume_held_transaction&hold_id=${id}`})
      .then(r=>r.json()).then(d=>{
        if(d.success){
          const data = JSON.parse(d.cart_data);
          const heldItems = data.items || [];
          const heldItemCount = heldItems.length;

          cart = heldItems;
          currentCustomer = data.customer || currentCustomer;
          updateCartUI();
          saveCartToSession(); // Save restored cart to session
          bootstrap.Modal.getInstance($('#heldTransactionsModal'))?.hide();

          // Show appropriate success notification
          if (wasCartEmpty && heldItemCount > 0) {
            showNotification(`Held transaction restored - ${heldItemCount} item${heldItemCount !== 1 ? 's' : ''} added to cart`, 'success');
          } else if (heldItemCount > 0) {
            showNotification(`Held transaction restored - cart updated with ${heldItemCount} item${heldItemCount !== 1 ? 's' : ''}`, 'success');
          } else {
            showNotification('Held transaction restored - empty cart loaded', 'info');
          }
        } else {
          showNotification(d.error || 'Failed to resume held transaction', 'error');
        }
      })
      .catch(error => {
        console.error('Error resuming held transaction:', error);
        showNotification('Network error - failed to resume held transaction', 'error');
      });
  }

  function deleteHeld(id){
    if(!confirm('Delete held transaction? This action cannot be undone.')) return;
    fetch('',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=delete_held_transaction&hold_id=${id}`})
      .then(r=>r.json()).then(d=>{
        if(d.success){
          showNotification('Held transaction deleted successfully', 'success');
          $('#viewHeldBtn')?.click(); // Refresh the held transactions list
        } else {
          showNotification(d.error || 'Failed to delete held transaction', 'error');
        }
      })
      .catch(error => {
        console.error('Error deleting held transaction:', error);
        showNotification('Network error - failed to delete held transaction', 'error');
      });
  }

  // Update held count badge
  function updateHeldCount(count) {
    const badge = $('#heldCountBadge');
    if (badge) {
      if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  }

  // Get time ago string
  function getTimeAgo(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) {
      return 'Just now';
    } else if (diffInSeconds < 3600) {
      const minutes = Math.floor(diffInSeconds / 60);
      return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
    } else if (diffInSeconds < 86400) {
      const hours = Math.floor(diffInSeconds / 3600);
      return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
    } else {
      const days = Math.floor(diffInSeconds / 86400);
      return `${days} day${days !== 1 ? 's' : ''} ago`;
    }
  }

  // Format timestamp for display
  function formatTimestamp(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    });
  }

  // Get duration held
  function getDurationHeld(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) {
      return `Held for ${diffInSeconds}s`;
    } else if (diffInSeconds < 3600) {
      const minutes = Math.floor(diffInSeconds / 60);
      const seconds = diffInSeconds % 60;
      return `Held for ${minutes}m ${seconds}s`;
    } else if (diffInSeconds < 86400) {
      const hours = Math.floor(diffInSeconds / 3600);
      const minutes = Math.floor((diffInSeconds % 3600) / 60);
      return `Held for ${hours}h ${minutes}m`;
    } else {
      const days = Math.floor(diffInSeconds / 86400);
      const hours = Math.floor((diffInSeconds % 86400) / 3600);
      return `Held for ${days}d ${hours}h`;
    }
  }

  // Load held transactions count
  function loadHeldCount() {
    fetch('',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=get_held_transactions'})
      .then(r=>r.json()).then(d=>{
        if(d.success){
          updateHeldCount(d.transactions.length);
        }
      })
      .catch(error => {
        console.error('Error loading held count:', error);
      });
  }

  // Load held transactions and show modal
  function loadHeldTransactions() {
    fetch('',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=get_held_transactions'})
      .then(r=>r.json()).then(d=>{
        if(d.success){
          renderHeld(d.transactions);
          updateHeldCount(d.transactions.length);
          new bootstrap.Modal($('#heldTransactionsModal')).show();
        } else {
          showNotification(d.error || 'Failed to load held transactions', 'error');
        }
      })
      .catch(error => {
        console.error('Error loading held transactions:', error);
        showNotification('Network error - failed to load held transactions', 'error');
      });
  }

  // Barcode scanning functionality
  function initBarcodeScanner() {
    const modal = $('#barcodeScanModal');
    const barcodeInput = $('#barcodeInput');
    const startCameraBtn = $('#startCameraBtn');
    const stopScanBtn = $('#stopScanBtn');
    const cameraView = $('#cameraView');
    const manualInput = $('#manualInput');
    const scanResults = $('#scanResults');
    const scanResultText = $('#scanResultText');
    const addScannedProduct = $('#addScannedProduct');
    
    let videoStream = null;
    let scannedProduct = null;
    
    // Reset modal when shown
    modal?.addEventListener('show.bs.modal', () => {
      barcodeInput.value = '';
      scanResults.style.display = 'none';
      cameraView.style.display = 'none';
      manualInput.style.display = 'block';
      addScannedProduct.disabled = true;
      scannedProduct = null;
    });
    
    // Clean up when modal is hidden
    modal?.addEventListener('hide.bs.modal', () => {
      stopCamera();
    });
    
    // Manual barcode input
    barcodeInput?.addEventListener('input', (e) => {
      const barcode = e.target.value.trim();
      if (barcode.length > 0) {
        searchProductByBarcode(barcode);
      }
    });
    
    // Enter key to search
    barcodeInput?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const barcode = barcodeInput.value.trim();
        if (barcode) {
          searchProductByBarcode(barcode);
        }
      }
    });
    
    // Start camera scanning
    startCameraBtn?.addEventListener('click', async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: { ideal: 'environment' }, // Use back camera if available
            width: { ideal: 1280 },
            height: { ideal: 720 }
          }
        });
        
        const video = $('#previewVideo');
        video.srcObject = stream;
        videoStream = stream;
        
        manualInput.style.display = 'none';
        cameraView.style.display = 'block';
        
        // Try to use QuaggaJS or ZXing if available for barcode detection
        // For now, we'll just show the camera and let users manually enter
        
      } catch (error) {
        console.error('Camera access denied:', error);
        alert('Camera access is required for barcode scanning. Please enable camera permissions and try again.');
      }
    });
    
    // Stop camera
    stopScanBtn?.addEventListener('click', () => {
      stopCamera();
      cameraView.style.display = 'none';
      manualInput.style.display = 'block';
    });
    
    // Add scanned product to cart
    addScannedProduct?.addEventListener('click', () => {
      if (scannedProduct) {
        addToCart({
          id: scannedProduct.id,
          name: scannedProduct.name,
          price: parseFloat(scannedProduct.price),
          quantity: 1,
          is_auto_bom: false
        });
        bootstrap.Modal.getInstance(modal)?.hide();
        
        // Find and show feedback on the product card
        const productCard = $(`.product-card[data-product-id="${scannedProduct.id}"]`);
        if (productCard) {
          productCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
          feedback(productCard);
        }
      }
    });
    
    function stopCamera() {
      if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
      }
    }
    
    function searchProductByBarcode(barcode) {
      // Search in the DOM for matching barcode
      const productCard = $(`.product-card[data-product-barcode="${barcode}"]`);
      
      if (productCard) {
        scannedProduct = {
          id: productCard.dataset.productId,
          name: productCard.dataset.productName,
    price: parseFloat(productCard.dataset.productPrice) || parseFloat(productCard.dataset.productSalePrice) || 0,
          barcode: barcode
        };
        
        scanResultText.textContent = `Found: ${scannedProduct.name} - $${scannedProduct.price}`;
        scanResults.style.display = 'block';
        addScannedProduct.disabled = false;
        
      } else {
        // Try SKU search as fallback
        const skuCard = $(`.product-card[data-product-sku="${barcode}"]`);
        if (skuCard) {
          scannedProduct = {
            id: skuCard.dataset.productId,
            name: skuCard.dataset.productName,
            price: skuCard.dataset.productPrice,
            sku: barcode
          };
          
          scanResultText.textContent = `Found by SKU: ${scannedProduct.name} - $${scannedProduct.price}`;
          scanResults.style.display = 'block';
          addScannedProduct.disabled = false;
          
        } else {
          scanResultText.textContent = `No product found with barcode/SKU: ${barcode}`;
          scanResults.style.display = 'block';
          scanResults.querySelector('.alert').className = 'alert alert-warning';
          addScannedProduct.disabled = true;
          scannedProduct = null;
          
          // Reset alert style after a delay
          setTimeout(() => {
            scanResults.querySelector('.alert').className = 'alert alert-success';
          }, 3000);
        }
      }
    }
  }
  
  
  function fallbackToCheckoutPage() {
    // Fallback to original checkout page method
    const payload = { items: cart, customer: currentCustomer, total: cart.reduce((t,i)=>t+i.price*i.quantity,0) };
    fetch('',{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=store_cart&cart_data='+encodeURIComponent(JSON.stringify(payload))})
      .then(()=>location.href='checkout.php')
      .catch(()=>{
        showNotification('Failed to process checkout. Please try again.', 'error');
      });
  }
  
  // Enhanced tax calculation that returns a promise
  function calculateCartTaxAsync() {
    return new Promise((resolve, reject) => {
      const payload = { items: cart };
      
      fetch('', {
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=calculate_tax&cart_data=${encodeURIComponent(JSON.stringify(payload))}`
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          updateTaxDisplay(data);
          resolve(data);
        } else {
          // Fallback calculation
          const subtotal = cart.reduce((t,i) => t + i.price * i.quantity, 0);
          const fallbackData = {
            subtotal: subtotal,
            total_tax: 0,
            total: subtotal,
            unique_tax_rates: [],
            tax_name: 'Tax'
          };
          updateTaxDisplay(fallbackData);
          resolve(fallbackData);
        }
      })
      .catch(err => {
        console.error('Tax calculation error:', err);
        // Fallback calculation
        const subtotal = cart.reduce((t,i) => t + i.price * i.quantity, 0);
        const fallbackData = {
          subtotal: subtotal,
          total_tax: 0,
          total: subtotal,
          unique_tax_rates: [],
          tax_name: 'Tax'
        };
        updateTaxDisplay(fallbackData);
        resolve(fallbackData);
      });
    });
  }
  
  // Helper function to escape HTML
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // Enhanced Quick Checkout function
  function openEnhancedQuickCheckout() {
    if (cart.length === 0) {
      showNotification('Cart is empty. Please add items before checkout.', 'warning');
      return;
    }
    
    // Check if quick checkout is available
    if (typeof window.quickCheckout === 'undefined') {
      // Fallback to standard checkout if quick checkout isn't loaded
      console.warn('Quick checkout not available, falling back to standard checkout');
      fallbackToCheckoutPage();
      return;
    }
    
    try {
      // Prepare cart data in the expected format for quick checkout
      const cartData = {
        items: cart.map(item => ({
          id: item.id,
          name: item.name,
          price: item.price,
          quantity: item.quantity,
          is_auto_bom: item.is_auto_bom || false,
          selling_unit_id: item.selling_unit_id || null,
          base_product_id: item.base_product_id || null,
          tax_rate: item.tax_rate || 0
        })),
        subtotal: cart.reduce((total, item) => total + (item.price * item.quantity), 0),
        customer: currentCustomer || null
      };
      
      // Open the quick checkout modal
      window.quickCheckout.open(cartData);
      
    } catch (error) {
      console.error('Error opening enhanced quick checkout:', error);
      showNotification('Failed to open quick checkout. Using standard checkout.', 'error');
      fallbackToCheckoutPage();
    }
  }

  // Initialize barcode scanner when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBarcodeScanner);
  } else {
    initBarcodeScanner();
  }

  // Expose init and cart access so sale.php can pass options and quick checkout can access cart
  window.POSUI = {
    init,
    getCart: () => cart,
    getCurrentCustomer: () => currentCustomer,
    clearCart: () => {
      cart = [];
      updateCartUI();
      clearCartFromSession();
    },
    saveCart: saveCartToSession,
    clearCartFromSession: clearCartFromSession
  };
})();

