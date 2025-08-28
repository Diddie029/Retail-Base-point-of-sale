// Products JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    
    // Mobile sidebar toggle
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('show');
    }
    
    // Make toggleSidebar globally accessible
    window.toggleSidebar = toggleSidebar;
    
    // Add mobile menu button if needed
    if (window.innerWidth <= 768) {
        const header = document.querySelector('.header-content');
        if (header && !document.querySelector('.mobile-menu-btn')) {
            const menuBtn = document.createElement('button');
            menuBtn.className = 'btn btn-outline-secondary mobile-menu-btn me-3';
            menuBtn.innerHTML = '<i class="bi bi-list"></i>';
            menuBtn.onclick = toggleSidebar;
            header.insertBefore(menuBtn, header.firstChild);
        }
    }
    
    // Product filtering functionality
    const filterForm = document.getElementById('filterForm');
    const searchInput = document.getElementById('searchInput');
    const categorySelect = document.getElementById('categoryFilter');
    const statusSelect = document.getElementById('statusFilter');
    
    if (filterForm) {
        // Auto-submit filter form on input change
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    filterForm.submit();
                }, 500);
            });
        }
        
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                filterForm.submit();
            });
        }
        
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                filterForm.submit();
            });
        }
    }
    
    // Product form validation
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = productForm.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                const feedback = field.parentNode.querySelector('.invalid-feedback');
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    if (feedback) {
                        feedback.textContent = 'This field is required';
                    }
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                    if (feedback) {
                        feedback.textContent = '';
                    }
                }
            });
            
            // Validate price
            const priceField = document.getElementById('price');
            if (priceField && priceField.value) {
                const price = parseFloat(priceField.value);
                if (isNaN(price) || price < 0) {
                    priceField.classList.add('is-invalid');
                    const feedback = priceField.parentNode.querySelector('.invalid-feedback');
                    if (feedback) {
                        feedback.textContent = 'Please enter a valid price';
                    }
                    isValid = false;
                }
            }
            
            // Validate quantity
            const quantityField = document.getElementById('quantity');
            if (quantityField && quantityField.value) {
                const quantity = parseInt(quantityField.value);
                if (isNaN(quantity) || quantity < 0) {
                    quantityField.classList.add('is-invalid');
                    const feedback = quantityField.parentNode.querySelector('.invalid-feedback');
                    if (feedback) {
                        feedback.textContent = 'Please enter a valid quantity';
                    }
                    isValid = false;
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                showAlert('Please fix the errors below', 'danger');
            }
        });
        
        // Real-time validation
        const inputs = productForm.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                if (this.classList.contains('is-invalid')) {
                    validateField(this);
                }
            });
        });
    }
    
    // Auto-generate barcode
    const generateBarcodeBtn = document.getElementById('generateBarcode');
    const barcodeInput = document.getElementById('barcode');
    
    if (generateBarcodeBtn && barcodeInput) {
        generateBarcodeBtn.addEventListener('click', function() {
            const timestamp = Date.now();
            const random = Math.floor(Math.random() * 1000);
            const barcode = `${timestamp}${random}`.substring(0, 13);
            barcodeInput.value = barcode;
            barcodeInput.dispatchEvent(new Event('input'));
        });
    }
    
    // Delete confirmation
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const productName = this.dataset.productName || 'this product';
            if (confirm(`Are you sure you want to delete "${productName}"? This action cannot be undone.`)) {
                // Add loading state
                this.innerHTML = '<i class="bi bi-hourglass-split"></i> Deleting...';
                this.disabled = true;
                
                // Submit delete form or redirect
                const deleteUrl = this.href || this.dataset.deleteUrl;
                if (deleteUrl) {
                    window.location.href = deleteUrl;
                }
            }
        });
    });
    
    // Bulk actions
    const selectAllCheckbox = document.getElementById('selectAll');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    const bulkActionsContainer = document.getElementById('bulkActions');
    
    if (selectAllCheckbox && productCheckboxes.length > 0) {
        selectAllCheckbox.addEventListener('change', function() {
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
        
        productCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                
                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.product-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === productCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < productCheckboxes.length;
            });
        });
    }
    
    // Bulk delete
    const bulkDeleteBtn = document.getElementById('bulkDelete');
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const checkedProducts = document.querySelectorAll('.product-checkbox:checked');
            if (checkedProducts.length === 0) {
                showAlert('Please select products to delete', 'warning');
                return;
            }
            
            if (confirm(`Are you sure you want to delete ${checkedProducts.length} selected products? This action cannot be undone.`)) {
                const productIds = Array.from(checkedProducts).map(cb => cb.value);
                bulkDeleteProducts(productIds);
            }
        });
    }
    
    // Import functionality
    const importForm = document.getElementById('importForm');
    const fileInput = document.getElementById('csvFile');
    const uploadArea = document.getElementById('uploadArea');
    
    if (uploadArea && fileInput) {
        uploadArea.addEventListener('click', function() {
            fileInput.click();
        });
        
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileDisplay(files[0]);
            }
        });
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateFileDisplay(this.files[0]);
            }
        });
    }
    
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            const file = fileInput.files[0];
            if (!file) {
                e.preventDefault();
                showAlert('Please select a CSV file to import', 'warning');
                return;
            }
            
            if (!file.name.toLowerCase().endsWith('.csv')) {
                e.preventDefault();
                showAlert('Please select a valid CSV file', 'danger');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="spinner"></span> Importing...';
                submitBtn.disabled = true;
            }
        });
    }
    
    // Export functionality
    const exportBtns = document.querySelectorAll('.btn-export');
    exportBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Show loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<span class="spinner"></span> Exporting...';
            this.disabled = true;
            
            // Determine export URL
            let exportUrl = this.href || this.dataset.exportUrl;
            
            if (!exportUrl) {
                // Use default export handler
                const exportType = this.dataset.exportType || 'all';
                const format = this.dataset.format || 'csv';
                exportUrl = `export_handler.php?type=${exportType}&format=${format}`;
                
                // Add category filter if specified
                const categoryId = this.dataset.categoryId;
                if (categoryId) {
                    exportUrl += `&category_id=${categoryId}`;
                }
            }
            
            // Create temporary link and trigger download
            const tempLink = document.createElement('a');
            tempLink.href = exportUrl;
            tempLink.style.display = 'none';
            document.body.appendChild(tempLink);
            tempLink.click();
            document.body.removeChild(tempLink);
            
            // Reset button state
            setTimeout(() => {
                this.innerHTML = originalText;
                this.disabled = false;
            }, 2000);
        });
    });
    
    // Quick export buttons
    const quickExportBtns = [
        { selector: '.export-all', type: 'all' },
        { selector: '.export-low-stock', type: 'low_stock' },
        { selector: '.export-out-stock', type: 'out_of_stock' },
        { selector: '.export-in-stock', type: 'in_stock' }
    ];
    
    quickExportBtns.forEach(config => {
        const btns = document.querySelectorAll(config.selector);
        btns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                
                const originalText = this.innerHTML;
                this.innerHTML = '<span class="spinner"></span> Exporting...';
                this.disabled = true;
                
                // Use products.php with action parameter
                const exportUrl = `products.php?action=export&type=${config.type}&format=csv`;
                
                // Trigger download
                window.location.href = exportUrl;
                
                // Reset button state
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.disabled = false;
                }, 2000);
            });
        });
    });
    
    // Stock level indicators
    updateStockIndicators();
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Helper functions
    function validateField(field) {
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        
        if (field.hasAttribute('required') && !field.value.trim()) {
            field.classList.add('is-invalid');
            if (feedback) {
                feedback.textContent = 'This field is required';
            }
            return false;
        }
        
        if (field.type === 'number') {
            const value = parseFloat(field.value);
            if (field.value && (isNaN(value) || value < 0)) {
                field.classList.add('is-invalid');
                if (feedback) {
                    feedback.textContent = 'Please enter a valid number';
                }
                return false;
            }
        }
        
        field.classList.remove('is-invalid');
        if (feedback) {
            feedback.textContent = '';
        }
        return true;
    }
    
    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.product-checkbox:checked').length;
        if (bulkActionsContainer) {
            if (checkedCount > 0) {
                bulkActionsContainer.style.display = 'block';
                const countSpan = bulkActionsContainer.querySelector('.selected-count');
                if (countSpan) {
                    countSpan.textContent = checkedCount;
                }
            } else {
                bulkActionsContainer.style.display = 'none';
            }
        }
    }
    
    function updateFileDisplay(file) {
        if (uploadArea) {
            uploadArea.innerHTML = `
                <i class="bi bi-file-earmark-check" style="font-size: 2rem; color: var(--success-color);"></i>
                <p class="mb-0 mt-2"><strong>${file.name}</strong></p>
                <p class="text-muted mb-0">${formatFileSize(file.size)}</p>
            `;
        }
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function updateStockIndicators() {
        const stockCells = document.querySelectorAll('.stock-quantity');
        stockCells.forEach(cell => {
            const quantity = parseInt(cell.textContent);
            const badge = cell.querySelector('.badge');
            
            if (badge) {
                if (quantity === 0) {
                    badge.className = 'badge badge-danger';
                    badge.textContent = 'Out of Stock';
                } else if (quantity <= 10) {
                    badge.className = 'badge badge-warning';
                    badge.textContent = 'Low Stock';
                } else {
                    badge.className = 'badge badge-success';
                    badge.textContent = 'In Stock';
                }
            }
        });
    }
    
    function bulkDeleteProducts(productIds) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        productIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'bulk_delete';
        actionInput.value = '1';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    function showAlert(message, type = 'info') {
        const alertContainer = document.getElementById('alertContainer') || document.querySelector('.content');
        if (!alertContainer) return;
        
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" aria-label="Close" onclick="this.parentElement.remove()"></button>
        `;
        
        if (document.getElementById('alertContainer')) {
            alertContainer.appendChild(alert);
        } else {
            alertContainer.insertBefore(alert, alertContainer.firstChild);
        }
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }
    
    // Handle window resize for responsive behavior
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('.sidebar');
        if (window.innerWidth > 768 && sidebar) {
            sidebar.classList.remove('show');
        }
    });
    
    // Click outside to close sidebar on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.querySelector('.sidebar');
        const menuBtn = document.querySelector('.mobile-menu-btn');
        
        if (window.innerWidth <= 768 && 
            sidebar && sidebar.classList.contains('show') && 
            !sidebar.contains(event.target) && 
            !menuBtn?.contains(event.target)) {
            sidebar.classList.remove('show');
        }
    });
    
    console.log('Products module initialized successfully');
    
    // Dropdown functionality (fallback if Bootstrap JS isn't loaded)
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Close other dropdowns
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                if (menu !== this.nextElementSibling) {
                    menu.classList.remove('show');
                }
            });
            
            // Toggle current dropdown
            const menu = this.nextElementSibling;
            if (menu && menu.classList.contains('dropdown-menu')) {
                menu.classList.toggle('show');
            }
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.btn-group')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
});

// Global utility functions
window.confirmDelete = function(url, name) {
    if (confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) {
        window.location.href = url;
    }
};

window.formatCurrency = function(amount, currency = 'KES') {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency === 'KES' ? 'USD' : currency,
        minimumFractionDigits: 2
    }).format(amount).replace('$', currency + ' ');
};