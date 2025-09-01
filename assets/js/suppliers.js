// Global functions for supplier management
let currentSupplierId = null;

// Toggle supplier status functionality
function showDeactivationOptions(supplierId, button) {
    console.log('Showing deactivation options for supplier:', supplierId);
    currentSupplierId = supplierId;
    
    const modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.innerHTML = `
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Choose Deactivation Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-3" id="deactivationTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="simple-tab" data-bs-toggle="tab" data-bs-target="#simple" type="button" role="tab">
                                <i class="bi bi-pause-circle"></i> Simple Deactivation
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                                <i class="bi bi-box-seam"></i> Deactivate + Products
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="keep-products-tab" data-bs-toggle="tab" data-bs-target="#keep-products" type="button" role="tab">
                                <i class="bi bi-cart-check"></i> Keep Products Active
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="notice-tab" data-bs-toggle="tab" data-bs-target="#notice" type="button" role="tab">
                                <i class="bi bi-exclamation-triangle"></i> Issue Notice
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="deactivationTabContent">
                        <!-- Simple Deactivation Tab -->
                        <div class="tab-pane fade show active" id="simple" role="tabpanel">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Simple Deactivation:</strong> Only deactivate the supplier. Products remain unchanged.
                            </div>
                            <div class="mb-3">
                                <label for="simpleReason" class="form-label">
                                    <strong>Reason for deactivation <span class="text-danger">*</span></strong>
                                </label>
                                <textarea class="form-control" id="simpleReason" rows="3" placeholder="Please provide a reason for deactivating this supplier..." required></textarea>
                            </div>
                        </div>
                        
                        <!-- Deactivate + Products Tab -->
                        <div class="tab-pane fade" id="products" role="tabpanel">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Deactivate + Deactivate Products:</strong> This will deactivate the supplier AND all associated products.
                            </div>
                            <div class="mb-3">
                                <label for="productsReason" class="form-label">
                                    <strong>Reason for deactivation <span class="text-danger">*</span></strong>
                                </label>
                                <textarea class="form-control" id="productsReason" rows="3" placeholder="Please provide a reason for deactivating this supplier and products..." required></textarea>
                            </div>
                        </div>
                        
                        <!-- Keep Products Active Tab -->
                        <div class="tab-pane fade" id="keep-products" role="tabpanel">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Deactivate + Keep Products Active:</strong> Deactivate supplier but keep products active for selling.
                            </div>
                            <div class="mb-3">
                                <label for="keepProductsReason" class="form-label">
                                    <strong>Reason for deactivation <span class="text-danger">*</span></strong>
                                </label>
                                <textarea class="form-control" id="keepProductsReason" rows="3" placeholder="Please provide a reason for deactivating this supplier..." required></textarea>
                            </div>
                        </div>
                        
                        <!-- Issue Notice Tab -->
                        <div class="tab-pane fade" id="notice" role="tabpanel">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Supplier Notice:</strong> Issue formal notice while keeping supplier active.
                            </div>
                            <div class="mb-3">
                                <label for="noticeReason" class="form-label">
                                    <strong>Notice Reason <span class="text-danger">*</span></strong>
                                </label>
                                <select class="form-control" id="noticeReason" required>
                                    <option value="">Select a reason</option>
                                    <option value="quality_issues">Quality Issues</option>
                                    <option value="delivery_delays">Delivery Delays</option>
                                    <option value="pricing_concerns">Pricing Concerns</option>
                                    <option value="contract_violation">Contract Violation</option>
                                    <option value="communication_issues">Communication Issues</option>
                                    <option value="documentation_problems">Documentation Problems</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="noticeDetails" class="form-label">
                                    <strong>Additional Details</strong>
                                </label>
                                <textarea class="form-control" id="noticeDetails" rows="3" placeholder="Additional details about the notice..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Confirmation Section -->
                    <div class="mt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirmAction" required>
                            <label class="form-check-label" for="confirmAction">
                                <strong>I confirm that I want to proceed with this action <span class="text-danger">*</span></strong>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeactivateSupplier()" id="confirmDeactivateBtn">
                        Proceed with Action
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    console.log('Modal created and added to DOM');
    
    // Debug: Check if footer is visible
    const footer = modal.querySelector('.modal-footer');
    const proceedBtn = modal.querySelector('#confirmDeactivateBtn');
    console.log('Footer element:', footer);
    console.log('Proceed button:', proceedBtn);
    console.log('Footer visibility:', footer ? footer.offsetHeight : 'not found');
    
    // Add event listeners for form validation
    const confirmAction = modal.querySelector('#confirmAction');
    const confirmDeactivateBtn = modal.querySelector('#confirmDeactivateBtn');
    
    function validateForm() {
        // Simple validation - just check if confirmation checkbox is checked
        const isValid = confirmAction.checked;
        confirmDeactivateBtn.disabled = !isValid;
    }
    
    // Add validation to all form elements
    const allInputs = modal.querySelectorAll('input, textarea, select');
    allInputs.forEach(input => {
        input.addEventListener('input', validateForm);
        input.addEventListener('change', validateForm);
    });
    
    confirmAction.addEventListener('change', validateForm);
    
    // Initialize Bootstrap modal
    const bootstrapModal = new bootstrap.Modal(modal);
    bootstrapModal.show();
    
    // Initialize Bootstrap tabs after modal is shown
    modal.addEventListener('shown.bs.modal', function() {
        // Initialize tabs
        const tabElements = modal.querySelectorAll('[data-bs-toggle="tab"]');
        tabElements.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const target = this.getAttribute('data-bs-target');
                const targetPane = modal.querySelector(target);
                
                // Remove active class from all tabs and panes
                modal.querySelectorAll('.nav-link').forEach(nav => nav.classList.remove('active'));
                modal.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('show', 'active'));
                
                // Add active class to clicked tab and target pane
                this.classList.add('active');
                targetPane.classList.add('show', 'active');
                
                // Re-validate form when switching tabs
                validateForm();
            });
        });
        
        // Ensure first tab is active by default
        const firstTab = modal.querySelector('#simple-tab');
        const firstPane = modal.querySelector('#simple');
        if (firstTab && firstPane) {
            firstTab.classList.add('active');
            firstPane.classList.add('show', 'active');
        }
    });
    
    // Clean up when modal is hidden
    modal.addEventListener('hidden.bs.modal', function() {
        document.body.removeChild(modal);
    });
}

// Function to confirm deactivation
function confirmDeactivateSupplier() {
    const activeTab = document.querySelector('.tab-pane.active');
    let deactivationType = 'simple';
    let reason = '';
    
    if (activeTab.id === 'simple') {
        deactivationType = 'simple';
        reason = document.getElementById('simpleReason').value.trim();
    } else if (activeTab.id === 'products') {
        deactivationType = 'deactivate_products';
        reason = document.getElementById('productsReason').value.trim();
    } else if (activeTab.id === 'keep-products') {
        deactivationType = 'allow_selling';
        reason = document.getElementById('keepProductsReason').value.trim();
    } else if (activeTab.id === 'notice') {
        deactivationType = 'supplier_notice';
        const noticeReason = document.getElementById('noticeReason').value;
        const noticeDetails = document.getElementById('noticeDetails').value.trim();
        reason = `Notice Issued: ${noticeReason}${noticeDetails ? ' - ' + noticeDetails : ''}`;
    }
    
    // For simple deactivation, require a reason
    if (activeTab.id === 'simple' && !reason) {
        alert('Please provide a reason for deactivating this supplier.');
        return;
    }
    
    // For other actions, reason is optional but recommended
    if (!reason) {
        reason = 'No specific reason provided';
    }
    
    // Create form data
    const formData = new FormData();
    formData.append('toggle_supplier', '1');
    formData.append('supplier_id', currentSupplierId);
    formData.append('deactivation_type', deactivationType);
    formData.append('supplier_block_note', reason);
    
    // Send deactivation request
    fetch('suppliers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        console.log('Deactivation response:', data);
        // Close modal
        const modal = document.querySelector('.modal');
        if (modal) {
            const bootstrapModal = bootstrap.Modal.getInstance(modal);
            bootstrapModal.hide();
        }
        // Reload page to show updated status
        location.reload();
    })
    .catch(error => {
        console.error('Error deactivating supplier:', error);
        alert('An error occurred while processing the request. Please try again.');
    });
}

function activateSupplier(supplierId, button) {
    const originalIcon = button.innerHTML;
    
    // Show loading state
    button.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    button.disabled = true;

    // Create form data
    const formData = new FormData();
    formData.append('toggle_supplier', '1');
    formData.append('supplier_id', supplierId);

    // Send AJAX request
    fetch('suppliers.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            // Update button appearance
            button.className = 'btn btn-sm btn-warning toggle-status';
            button.setAttribute('data-current-status', '1');
            button.setAttribute('title', 'Deactivate Supplier');
            button.innerHTML = '<i class="bi bi-pause-fill"></i>';

            // Update status badge in the same row
            const row = button.closest('tr');
            const statusBadge = row.querySelector('.badge');
            if (statusBadge) {
                statusBadge.className = 'badge badge-success';
                statusBadge.textContent = 'Active';
            }

            // Show success message
            showNotification('Supplier activated successfully!', 'success');
        } else {
            // Revert button state on error
            button.innerHTML = originalIcon;
            showNotification('Failed to activate supplier.', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Revert button state on error
        button.innerHTML = originalIcon;
        showNotification('An error occurred while activating supplier.', 'error');
    })
    .finally(() => {
        button.disabled = false;
    });
}

// Notification function
function showNotification(message, type) {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.alert');
    existingNotifications.forEach(notification => notification.remove());

    // Create new notification
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
    notification.innerHTML = `
        <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    // Insert at the top of the content area
    const content = document.querySelector('.content');
    if (content) {
        content.insertBefore(notification, content.firstChild);
    }

    // Auto-dismiss after 3 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 3000);
}

// Enhanced bulk action functions
function showBulkProgress(totalItems, action) {
    const progressContainer = document.createElement('div');
    progressContainer.className = 'bulk-progress fade-in';
    progressContainer.innerHTML = `
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0">Processing ${action} for ${totalItems} suppliers...</h6>
            <span class="text-muted"><span id="progressCurrent">0</span>/${totalItems}</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar" id="progressBar" style="width: 0%"></div>
        </div>
        <div class="text-center mt-2">
            <small class="text-muted" id="progressStatus">Initializing...</small>
        </div>
    `;
    
    const bulkActionsContainer = document.querySelector('.bulk-actions-container');
    if (bulkActionsContainer) {
        bulkActionsContainer.appendChild(progressContainer);
        progressContainer.style.display = 'block';
    }
    
    return progressContainer;
}

function updateBulkProgress(current, total, status) {
    const progressBar = document.getElementById('progressBar');
    const progressCurrent = document.getElementById('progressCurrent');
    const progressStatus = document.getElementById('progressStatus');
    
    if (progressBar && progressCurrent && progressStatus) {
        const percentage = (current / total) * 100;
        progressBar.style.width = percentage + '%';
        progressCurrent.textContent = current;
        progressStatus.textContent = status;
    }
}

function hideBulkProgress() {
    const progressContainer = document.querySelector('.bulk-progress');
    if (progressContainer) {
        progressContainer.style.display = 'none';
    }
}

// Enhanced bulk operations with progress tracking
function processBulkAction(action, supplierIds, reason = '') {
    if (supplierIds.length === 0) {
        showNotification('Please select suppliers to perform bulk action.', 'error');
        return;
    }
    
    // Show loading overlay
    showLoadingOverlay();
    
    // Show progress bar
    const progressContainer = showBulkProgress(supplierIds.length, action);
    
    // Process suppliers one by one for better user feedback
    let processed = 0;
    let successful = 0;
    let failed = 0;
    
    function processNext() {
        if (processed >= supplierIds.length) {
            // All done
            hideLoadingOverlay();
            hideBulkProgress();
            
            if (successful > 0) {
                showNotification(`Successfully ${action}d ${successful} supplier(s).`, 'success');
            }
            if (failed > 0) {
                showNotification(`Failed to ${action} ${failed} supplier(s).`, 'error');
            }
            
            // Reload page to show updated data
            setTimeout(() => location.reload(), 1500);
            return;
        }
        
        const supplierId = supplierIds[processed];
        updateBulkProgress(processed + 1, supplierIds.length, `Processing supplier ${processed + 1}...`);
        
        // Create form data for this supplier
        const formData = new FormData();
        formData.append('bulk_action', action);
        formData.append('supplier_ids[]', supplierId);
        formData.append('bulk_confirm_action', 'on');
        if (reason) {
            formData.append('supplier_block_note', reason);
        }
        
        // Send request
        fetch('suppliers.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.ok) {
                successful++;
            } else {
                failed++;
            }
        })
        .catch(error => {
            console.error('Bulk action error:', error);
            failed++;
        })
        .finally(() => {
            processed++;
            // Small delay for better user experience
            setTimeout(processNext, 200);
        });
    }
    
    processNext();
}

// Show/hide loading overlay
function showLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loadingOverlay';
    overlay.innerHTML = `
        <div class="loading-spinner"></div>
    `;
    document.body.appendChild(overlay);
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

// Enhanced selection management
function updateSupplierRowSelection() {
    const supplierCheckboxes = document.querySelectorAll('.supplier-checkbox');
    supplierCheckboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (checkbox.checked) {
            row.classList.add('supplier-row', 'selected');
        } else {
            row.classList.remove('supplier-row', 'selected');
        }
    });
}

// Advanced search functionality
function setupAdvancedSearch() {
    const searchInput = document.getElementById('searchInput');
    const filterToggle = document.querySelector('.filter-toggle');
    const advancedFilters = document.querySelector('.advanced-filters');
    
    // Real-time search with debounce
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                // Add search suggestions or live filtering here
                console.log('Search term:', this.value);
            }, 300);
        });
    }
    
    // Toggle advanced filters
    if (filterToggle && advancedFilters) {
        filterToggle.addEventListener('click', function() {
            advancedFilters.classList.toggle('show');
            this.textContent = advancedFilters.classList.contains('show') ? 
                'Hide Advanced Filters' : 'Show Advanced Filters';
        });
    }
}

// Document ready function
document.addEventListener('DOMContentLoaded', function() {
    // Initialize advanced search
    setupAdvancedSearch();
    
    // Toggle supplier status functionality
    const toggleButtons = document.querySelectorAll('.toggle-status');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const supplierId = this.getAttribute('data-id');
            const currentStatus = parseInt(this.getAttribute('data-current-status'));
            
            if (currentStatus === 1) {
                // Show deactivation options modal
                showDeactivationOptions(supplierId, this);
            } else {
                // Activate supplier directly
                activateSupplier(supplierId, this);
            }
        });
    });

    // Enhanced bulk selection functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const supplierCheckboxes = document.querySelectorAll('.supplier-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    const bulkActionSelect = document.getElementById('bulkAction');
    const bulkBlockNote = document.getElementById('bulkBlockNote');
    const selectionCountElement = document.querySelector('.selection-count');

    if (selectAllCheckbox && supplierCheckboxes.length > 0) {
        selectAllCheckbox.addEventListener('change', function() {
            supplierCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
            updateSupplierRowSelection();
        });

        supplierCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                updateSupplierRowSelection();

                // Update select all checkbox
                const checkedCount = document.querySelectorAll('.supplier-checkbox:checked').length;
                selectAllCheckbox.checked = checkedCount === supplierCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < supplierCheckboxes.length;
                
                // Update selection count
                if (selectionCountElement) {
                    selectionCountElement.textContent = checkedCount;
                }
            });
        });
    }

    // Enhanced bulk action handling
    if (bulkActionSelect) {
        bulkActionSelect.addEventListener('change', function() {
            const bulkConfirmationSection = document.getElementById('bulkConfirmationSection');
            
            if (this.value === 'deactivate') {
                if (bulkBlockNote) {
                    bulkBlockNote.style.display = 'inline-block';
                    bulkBlockNote.required = true;
                }
                if (bulkConfirmationSection) bulkConfirmationSection.style.display = 'inline-block';
            } else if (this.value === 'activate' || this.value === 'delete' || this.value === 'export') {
                if (bulkBlockNote) {
                    bulkBlockNote.style.display = 'none';
                    bulkBlockNote.required = false;
                }
                if (bulkConfirmationSection) bulkConfirmationSection.style.display = 'inline-block';
            } else {
                if (bulkBlockNote) {
                    bulkBlockNote.style.display = 'none';
                    bulkBlockNote.required = false;
                }
                if (bulkConfirmationSection) bulkConfirmationSection.style.display = 'none';
            }
        });
    }

    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.supplier-checkbox:checked').length;
        const bulkActionsContainer = document.querySelector('.bulk-actions-container');
        
        if (bulkActionsContainer) {
            if (checkedCount > 0) {
                bulkActionsContainer.style.display = 'block';
                bulkActionsContainer.classList.add('slide-down');
            } else {
                bulkActionsContainer.style.display = 'none';
                bulkActionsContainer.classList.remove('slide-down');
            }
        }
        
        // Legacy support for old bulk actions element
        if (bulkActions) {
            if (checkedCount > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }
        
        // Update selection count in header
        const selectionInfo = document.querySelector('.bulk-selection-info');
        if (selectionInfo) {
            selectionInfo.innerHTML = `
                <span class="selection-count">${checkedCount}</span>
                <span>supplier${checkedCount !== 1 ? 's' : ''} selected</span>
            `;
        }
    }
    
    // Enhanced form submission with validation
    const bulkForm = document.getElementById('bulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selectedSuppliers = document.querySelectorAll('.supplier-checkbox:checked');
            const action = bulkActionSelect ? bulkActionSelect.value : '';
            const reason = bulkBlockNote ? bulkBlockNote.value.trim() : '';
            const confirmCheckbox = document.getElementById('bulkConfirmAction');
            
            // Validation
            if (selectedSuppliers.length === 0) {
                showNotification('Please select at least one supplier.', 'error');
                return;
            }
            
            if (!action) {
                showNotification('Please select an action to perform.', 'error');
                return;
            }
            
            if (action === 'deactivate' && !reason) {
                showNotification('Please provide a reason for deactivation.', 'error');
                return;
            }
            
            if (!confirmCheckbox || !confirmCheckbox.checked) {
                showNotification('Please confirm the action by checking the confirmation box.', 'error');
                return;
            }
            
            // Show confirmation dialog for destructive actions
            if (action === 'delete') {
                const confirmed = confirm(`Are you sure you want to delete ${selectedSuppliers.length} supplier(s)? This action cannot be undone.`);
                if (!confirmed) return;
            }
            
            // Get supplier IDs
            const supplierIds = Array.from(selectedSuppliers).map(cb => cb.value);
            
            // Process bulk action with enhanced feedback
            processBulkAction(action, supplierIds, reason);
        });
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+A to select all (when not in input field)
        if (e.ctrlKey && e.key === 'a' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
            e.preventDefault();
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.dispatchEvent(new Event('change'));
            }
        }
        
        // Escape to clear selection
        if (e.key === 'Escape') {
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.dispatchEvent(new Event('change'));
            }
        }
    });
    
    // Auto-save filter preferences
    const filterInputs = document.querySelectorAll('.filter-section input, .filter-section select');
    filterInputs.forEach(input => {
        input.addEventListener('change', function() {
            const filterData = {
                search: document.querySelector('input[name="search"]')?.value || '',
                status: document.querySelector('select[name="status"]')?.value || 'all',
                sort: document.querySelector('select[name="sort"]')?.value || 'name'
            };
            localStorage.setItem('supplierFilters', JSON.stringify(filterData));
        });
    });
    
    // Load saved filter preferences
    const savedFilters = localStorage.getItem('supplierFilters');
    if (savedFilters) {
        try {
            const filters = JSON.parse(savedFilters);
            const searchInput = document.querySelector('input[name="search"]');
            const statusSelect = document.querySelector('select[name="status"]');
            const sortSelect = document.querySelector('select[name="sort"]');
            
            if (searchInput && !searchInput.value) searchInput.value = filters.search;
            if (statusSelect && statusSelect.value === 'all') statusSelect.value = filters.status;
            if (sortSelect && sortSelect.value === 'name') sortSelect.value = filters.sort;
        } catch (e) {
            console.log('Could not load saved filters:', e);
        }
    }
});
