/**
 * Expiry Tracker JavaScript Functions
 * Handles client-side functionality for expiry tracking system
 * 
 * Note: Some features (edit, bulk actions, alerts refresh) require API endpoints
 * that may not be implemented yet. These features will gracefully degrade
 * and show appropriate warning messages.
 */

class ExpiryTracker {
    constructor() {
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeCharts();
        this.setupRealTimeUpdates();
    }

    bindEvents() {
        // Auto-refresh functionality
        this.setupAutoRefresh();

        // Form validation
        this.setupFormValidation();

        // Modal handling
        this.setupModalHandling();

        // Search and filter functionality
        this.setupSearchAndFilters();

        // Bulk actions
        this.setupBulkActions();
    }

    setupAutoRefresh() {
        // Auto refresh dashboard every 5 minutes
        if (document.querySelector('.expiry-dashboard')) {
            setInterval(() => {
                this.refreshDashboard();
            }, 300000); // 5 minutes
        }

        // Auto refresh alerts every 2 minutes
        if (document.querySelector('.expiry-alerts')) {
            setInterval(() => {
                this.refreshAlerts();
            }, 120000); // 2 minutes
        }
    }

    setupFormValidation() {
        // Expiry date validation
        const expiryDateInputs = document.querySelectorAll('input[name="expiry_date"]');
        expiryDateInputs.forEach(input => {
            input.addEventListener('change', (e) => {
                this.validateExpiryDate(e.target);
            });
        });

        // Quantity validation
        const quantityInputs = document.querySelectorAll('input[name="quantity"], input[name="remaining_quantity"]');
        quantityInputs.forEach(input => {
            input.addEventListener('input', (e) => {
                this.validateQuantity(e.target);
            });
        });

        // Alert days validation
        const alertDaysInputs = document.querySelectorAll('input[name="alert_days_before"]');
        alertDaysInputs.forEach(input => {
            input.addEventListener('input', (e) => {
                this.validateAlertDays(e.target);
            });
        });
    }

    setupModalHandling() {
        // Handle modal show events
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('show.bs.modal', (e) => {
                this.onModalShow(e);
            });

            modal.addEventListener('hidden.bs.modal', (e) => {
                this.onModalHide(e);
            });
        });
    }

    setupSearchAndFilters() {
        // Debounced search
        const searchInputs = document.querySelectorAll('input[name="search"]');
        searchInputs.forEach(input => {
            let timeout;
            input.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.performSearch(e.target.value);
                }, 500);
            });
        });

        // Filter changes
        const filterSelects = document.querySelectorAll('select[name="status"], select[name="type"], select[name="category"]');
        filterSelects.forEach(select => {
            select.addEventListener('change', () => {
                this.applyFilters();
            });
        });
    }

    setupBulkActions() {
        // Bulk action checkboxes
        const selectAllCheckbox = document.querySelector('#selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (e) => {
                this.toggleSelectAll(e.target.checked);
            });
        }

        // Bulk action buttons
        const bulkActionButtons = document.querySelectorAll('.bulk-action-btn');
        bulkActionButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                this.performBulkAction(e.target.dataset.action);
            });
        });
    }

    validateExpiryDate(input) {
        const value = input.value;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const selectedDate = new Date(value);

        if (selectedDate < today) {
            this.showValidationError(input, 'Expiry date cannot be in the past');
            return false;
        }

        this.clearValidationError(input);
        return true;
    }

    validateQuantity(input) {
        const value = parseInt(input.value);
        const max = parseInt(input.max) || Infinity;

        if (value < 0) {
            this.showValidationError(input, 'Quantity cannot be negative');
            return false;
        }

        if (value > max) {
            this.showValidationError(input, `Quantity cannot exceed ${max}`);
            return false;
        }

        this.clearValidationError(input);
        return true;
    }

    validateAlertDays(input) {
        const value = parseInt(input.value);

        if (value < 1 || value > 365) {
            this.showValidationError(input, 'Alert days must be between 1 and 365');
            return false;
        }

        this.clearValidationError(input);
        return true;
    }

    showValidationError(input, message) {
        const formGroup = input.closest('.mb-3');
        let errorElement = formGroup.querySelector('.invalid-feedback');

        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'invalid-feedback';
            formGroup.appendChild(errorElement);
        }

        errorElement.textContent = message;
        input.classList.add('is-invalid');
    }

    clearValidationError(input) {
        const formGroup = input.closest('.mb-3');
        const errorElement = formGroup.querySelector('.invalid-feedback');

        if (errorElement) {
            errorElement.remove();
        }

        input.classList.remove('is-invalid');
    }

    onModalShow(event) {
        const modal = event.target;
        const trigger = event.relatedTarget;

        if (trigger && trigger.dataset.expiryId) {
            // Load expiry data for edit modal
            this.loadExpiryData(trigger.dataset.expiryId, modal);
        }
    }

    onModalHide(event) {
        // Reset modal forms
        const modal = event.target;
        const forms = modal.querySelectorAll('form');
        forms.forEach(form => form.reset());

        // Clear validation errors
        const invalidInputs = modal.querySelectorAll('.is-invalid');
        invalidInputs.forEach(input => {
            input.classList.remove('is-invalid');
            const errorElement = input.closest('.mb-3').querySelector('.invalid-feedback');
            if (errorElement) errorElement.remove();
        });
    }

    async loadExpiryData(expiryId, modal) {
        try {
            // Check if API endpoint exists
            const response = await fetch(`api/get_expiry.php?id=${expiryId}`);
            
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                if (data.success) {
                    this.populateEditForm(data.expiry, modal);
                } else {
                    this.showToast('Error loading expiry data: ' + data.message, 'error');
                }
            } else {
                throw new Error('API endpoint not found or returned HTML instead of JSON');
            }
        } catch (error) {
            console.error('Error loading expiry data:', error);
            this.showToast('Edit feature not available - API endpoint missing', 'warning');
        }
    }

    populateEditForm(expiry, modal) {
        const form = modal.querySelector('form');

        // Populate form fields
        Object.keys(expiry).forEach(key => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input) {
                if (input.type === 'checkbox') {
                    input.checked = expiry[key] == 1;
                } else {
                    input.value = expiry[key] || '';
                }
            }
        });

        // Handle date fields
        if (expiry.expiry_date) {
            const expiryDateInput = form.querySelector('[name="expiry_date"]');
            if (expiryDateInput) {
                expiryDateInput.value = expiry.expiry_date.split(' ')[0];
            }
        }

        if (expiry.manufacturing_date) {
            const manufacturingDateInput = form.querySelector('[name="manufacturing_date"]');
            if (manufacturingDateInput) {
                manufacturingDateInput.value = expiry.manufacturing_date.split(' ')[0];
            }
        }
    }

    async performSearch(query) {
        const url = new URL(window.location);
        url.searchParams.set('search', query);
        url.searchParams.set('page', '1'); // Reset to first page

        try {
            const response = await fetch(url);
            const html = await response.text();

            // Update table content
            const parser = new DOMParser();
            const newDoc = parser.parseFromString(html, 'text/html');
            const newTable = newDoc.querySelector('.table-responsive');
            const oldTable = document.querySelector('.table-responsive');

            if (newTable && oldTable) {
                oldTable.innerHTML = newTable.innerHTML;
            }

            // Update pagination
            const newPagination = newDoc.querySelector('.card-footer');
            const oldPagination = document.querySelector('.card-footer');

            if (newPagination && oldPagination) {
                oldPagination.innerHTML = newPagination.innerHTML;
            }

            // Update URL without page reload
            window.history.pushState({}, '', url);

        } catch (error) {
            console.error('Search error:', error);
        }
    }

    async applyFilters() {
        const url = new URL(window.location);

        // Get current filter values
        const statusFilter = document.querySelector('select[name="status"]');
        const typeFilter = document.querySelector('select[name="type"]');
        const categoryFilter = document.querySelector('select[name="category"]');

        if (statusFilter) url.searchParams.set('status', statusFilter.value);
        if (typeFilter) url.searchParams.set('type', typeFilter.value);
        if (categoryFilter) url.searchParams.set('category', categoryFilter.value);

        url.searchParams.set('page', '1'); // Reset to first page

        // Reload page with filters
        window.location.href = url.toString();
    }

    async refreshDashboard() {
        try {
            const response = await fetch(window.location.href);
            const html = await response.text();

            const parser = new DOMParser();
            const newDoc = parser.parseFromString(html, 'text/html');

            // Update statistics cards
            const statsCards = newDoc.querySelectorAll('.stats-card');
            statsCards.forEach((newCard, index) => {
                const oldCard = document.querySelectorAll('.stats-card')[index];
                if (oldCard) {
                    oldCard.innerHTML = newCard.innerHTML;
                }
            });

        } catch (error) {
            console.error('Dashboard refresh error:', error);
        }
    }

    async refreshAlerts() {
        try {
            const alertsContainer = document.querySelector('.alerts-container');
            if (!alertsContainer) return;

            const response = await fetch('api/get_alerts.php');
            
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                if (data.success) {
                    this.updateAlertsDisplay(data.alerts);
                }
            } else {
                // API endpoint not available, disable alerts refresh
                console.log('Alerts API not available');
                return;
            }
        } catch (error) {
            console.error('Alerts refresh error:', error);
            // Don't show error for missing API
        }
    }

    updateAlertsDisplay(alerts) {
        const container = document.querySelector('.alerts-container');
        if (!container) return;

        let html = '';

        if (alerts.length === 0) {
            html = `
                <div class="text-center py-4">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No pending alerts</p>
                </div>
            `;
        } else {
            html = '<div class="list-group list-group-flush">';
            alerts.forEach(alert => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">${alert.product_name}</h6>
                                <small class="text-muted">
                                    Expires: ${alert.expiry_date}
                                </small>
                            </div>
                            <span class="badge bg-warning">Pending</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
        }

        container.innerHTML = html;
    }

    toggleSelectAll(checked) {
        const checkboxes = document.querySelectorAll('.expiry-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked;
        });

        this.updateBulkActionButtons();
    }

    updateBulkActionButtons() {
        const selectedCount = document.querySelectorAll('.expiry-checkbox:checked').length;
        const bulkButtons = document.querySelectorAll('.bulk-action-btn');

        bulkButtons.forEach(button => {
            button.disabled = selectedCount === 0;
            button.textContent = button.dataset.baseText + (selectedCount > 0 ? ` (${selectedCount})` : '');
        });
    }

    async performBulkAction(action) {
        const selectedIds = Array.from(document.querySelectorAll('.expiry-checkbox:checked'))
            .map(checkbox => checkbox.value);

        if (selectedIds.length === 0) {
            this.showToast('No items selected', 'warning');
            return;
        }

        if (!confirm(`Are you sure you want to ${action} ${selectedIds.length} items?`)) {
            return;
        }

        try {
            const response = await fetch('api/bulk_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: action,
                    ids: selectedIds
                })
            });

            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                if (data.success) {
                    this.showToast(`Bulk ${action} completed successfully`, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    this.showToast('Error: ' + data.message, 'error');
                }
            } else {
                throw new Error('Bulk action API endpoint not found or returned HTML instead of JSON');
            }

        } catch (error) {
            console.error('Bulk action error:', error);
            if (error.message.includes('API endpoint not found')) {
                this.showToast('Bulk actions not available - API endpoint missing', 'warning');
            } else {
                this.showToast('Error performing bulk action', 'error');
            }
        }
    }

    initializeCharts() {
        // Initialize any charts if Chart.js is available
        if (typeof Chart !== 'undefined') {
            this.createExpiryChart();
            this.createCategoryChart();
        }
    }

    createExpiryChart() {
        const ctx = document.getElementById('expiryChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Expiring Items',
                    data: [12, 19, 3, 5, 2, 3],
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Monthly Expiry Trends'
                    }
                }
            }
        });
    }

    createCategoryChart() {
        const ctx = document.getElementById('categoryChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Food', 'Medicine', 'Cosmetics', 'Electronics', 'Chemicals'],
                datasets: [{
                    data: [30, 25, 20, 15, 10],
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Expiry by Category'
                    }
                }
            }
        });
    }

    setupRealTimeUpdates() {
        // WebSocket connection for real-time updates (if supported)
        if ('WebSocket' in window) {
            this.connectWebSocket();
        }
    }

    connectWebSocket() {
        // Placeholder for WebSocket implementation
        // This would connect to a WebSocket server for real-time expiry alerts
        console.log('WebSocket support detected - real-time updates available');
    }

    showToast(message, type = 'info') {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        // Add to toast container
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }

        toastContainer.appendChild(toast);

        // Initialize and show toast
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();

        // Remove toast after it's hidden
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }
}

// Utility functions
const ExpiryUtils = {
    formatDate: (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    formatDateTime: (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },

    calculateDaysUntilExpiry: (expiryDate) => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const expiry = new Date(expiryDate);
        expiry.setHours(0, 0, 0, 0);

        const diffTime = expiry.getTime() - today.getTime();
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        return diffDays;
    },

    getExpiryStatus: (daysUntilExpiry) => {
        if (daysUntilExpiry < 0) return 'expired';
        if (daysUntilExpiry <= 7) return 'critical';
        if (daysUntilExpiry <= 30) return 'warning';
        return 'normal';
    },

    getStatusBadgeClass: (status) => {
        const classes = {
            'expired': 'bg-secondary',
            'critical': 'bg-danger',
            'warning': 'bg-warning',
            'normal': 'bg-success',
            'pending': 'bg-info',
            'sent': 'bg-primary',
            'failed': 'bg-danger'
        };
        return classes[status] || 'bg-secondary';
    }
};

// Expiry Tracker JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Initialize expiry tracker functionality
    initializeExpiryTracker();
    
    // Set up real-time updates
    setupRealTimeUpdates();
    
    // Initialize filters and search
    initializeFilters();
    
    // Set up action buttons
    setupActionButtons();
});

function initializeExpiryTracker() {
    console.log('Expiry Tracker initialized');
    
    // Add loading states to buttons
    const actionButtons = document.querySelectorAll('.action-buttons .btn');
    actionButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (this.classList.contains('btn-disabled')) {
                e.preventDefault();
                return;
            }
            
            // Add loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            this.classList.add('btn-disabled');
            
            // Re-enable after a delay (in case of navigation)
            setTimeout(() => {
                this.innerHTML = originalText;
                this.classList.remove('btn-disabled');
            }, 3000);
        });
    });
    
    // Add hover effects to table rows
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.01)';
            this.style.transition = 'transform 0.2s ease';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
}

function setupRealTimeUpdates() {
    // Update critical alert count every 30 seconds
    setInterval(updateCriticalAlerts, 30000);
    
    // Update expiry dates display every minute
    setInterval(updateExpiryDates, 60000);
}

function updateCriticalAlerts() {
    const criticalCount = document.querySelector('.alert-card.active .alert-content h3');
    if (criticalCount) {
        // This would typically make an AJAX call to get updated counts
        // For now, we'll just add a visual indicator that it's updating
        criticalCount.style.opacity = '0.7';
        setTimeout(() => {
            criticalCount.style.opacity = '1';
        }, 1000);
    }
}

function updateExpiryDates() {
    const expiryDates = document.querySelectorAll('.days-left');
    expiryDates.forEach(dateElement => {
        const expiryDate = dateElement.getAttribute('data-expiry-date');
        if (expiryDate) {
            const daysLeft = calculateDaysLeft(expiryDate);
            updateDaysLeftDisplay(dateElement, daysLeft);
        }
    });
}

function calculateDaysLeft(expiryDate) {
    const today = new Date();
    const expiry = new Date(expiryDate);
    const timeDiff = expiry.getTime() - today.getTime();
    const daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
    return daysDiff;
}

function updateDaysLeftDisplay(element, daysLeft) {
    if (daysLeft < 0) {
        element.innerHTML = `<span class="expired">Expired ${Math.abs(daysLeft)} days ago</span>`;
        element.classList.add('expired');
    } else if (daysLeft <= 7) {
        element.innerHTML = `${daysLeft} days`;
        element.classList.add('critical');
    } else {
        element.innerHTML = `${daysLeft} days`;
        element.classList.remove('critical', 'expired');
    }
}

function initializeFilters() {
    // Auto-submit form when filters change
    const filterSelects = document.querySelectorAll('.filter-form select');
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            // Add a small delay to allow multiple selections
            clearTimeout(this.submitTimeout);
            this.submitTimeout = setTimeout(() => {
                this.closest('form').submit();
            }, 500);
        });
    });
    
    // Search with debouncing
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.closest('form').submit();
            }, 800);
        });
    }
    
    // Clear filters button
    const clearFiltersBtn = document.querySelector('.btn-secondary');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'expiry_tracker.php';
        });
    }
}

function setupActionButtons() {
    // Handle expiry actions
    const handleButtons = document.querySelectorAll('a[href*="handle_expiry"]');
    handleButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const itemId = this.getAttribute('href').split('=')[1];
            showHandleExpiryModal(itemId);
        });
    });
    
    // Handle view details
    const viewButtons = document.querySelectorAll('a[href*="view_expiry_item"]');
    viewButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Allow normal navigation for view buttons
            // Just add loading state
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        });
    });
}

function showHandleExpiryModal(itemId) {
    // Create modal for handling expiry
    const modal = document.createElement('div');
    modal.className = 'expiry-modal-overlay';
    modal.innerHTML = `
        <div class="expiry-modal">
            <div class="expiry-modal-header">
                <h3>Handle Expiry Item</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="expiry-modal-body">
                                 <form id="handleExpiryForm" method="POST" action="handle_expiry.php?id=${itemId}">
                    
                    <div class="form-group">
                        <label for="action_type">Action Type:</label>
                        <select name="action_type" id="action_type" required>
                            <option value="">Select Action</option>
                            <option value="dispose">Dispose</option>
                            <option value="return">Return to Supplier</option>
                            <option value="sell_at_discount">Sell at Discount</option>
                            <option value="donate">Donate</option>
                            <option value="recall">Recall</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity_affected">Quantity Affected:</label>
                        <input type="number" name="quantity_affected" id="quantity_affected" min="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="reason">Reason:</label>
                        <textarea name="reason" id="reason" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="cost">Cost (if applicable):</label>
                        <input type="number" name="cost" id="cost" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Additional Notes:</label>
                        <textarea name="notes" id="notes" rows="2"></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Submit Action</button>
                        <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    // Add modal to page
    document.body.appendChild(modal);
    
    // Show modal
    setTimeout(() => modal.classList.add('show'), 10);
    
    // Handle modal close
    const closeButtons = modal.querySelectorAll('.modal-close');
    closeButtons.forEach(button => {
        button.addEventListener('click', () => {
            modal.classList.remove('show');
            setTimeout(() => modal.remove(), 300);
        });
    });
    
    // Handle form submission
    const form = modal.querySelector('#handleExpiryForm');
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
        
                 // Submit form data
         const formData = new FormData(this);
         fetch(`handle_expiry.php?id=${itemId}`, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(response => {
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json();
            } else {
                // If not JSON, throw an error
                throw new Error('Server returned HTML instead of JSON. This usually means there was a PHP error.');
            }
        })
        .then(data => {
            if (data.success) {
                showNotification('Action completed successfully!', 'success');
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.remove();
                    location.reload(); // Refresh page to show updated data
                }, 300);
            } else {
                showNotification(data.message || 'An error occurred', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred while processing the request: ' + error.message, 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
            setTimeout(() => this.remove(), 300);
        }
    });
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close">&times;</button>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => notification.classList.add('show'), 10);
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 5000);
    
    // Handle close button
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.addEventListener('click', () => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    });
}

// Add CSS for modal and notifications
const style = document.createElement('style');
style.textContent = `
    .expiry-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .expiry-modal-overlay.show {
        opacity: 1;
    }
    
    .expiry-modal {
        background: white;
        border-radius: 10px;
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        transform: scale(0.9);
        transition: transform 0.3s ease;
    }
    
    .expiry-modal-overlay.show .expiry-modal {
        transform: scale(1);
    }
    
    .expiry-modal-header {
        padding: 20px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .expiry-modal-header h3 {
        margin: 0;
        color: #334155;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #64748b;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background-color 0.3s ease;
    }
    
    .modal-close:hover {
        background-color: #f1f5f9;
    }
    
    .expiry-modal-body {
        padding: 20px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    
    .modal-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 30px;
    }
    
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        padding: 15px 20px;
        z-index: 1001;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        max-width: 400px;
    }
    
    .notification.show {
        transform: translateX(0);
    }
    
    .notification-content {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .notification-success {
        border-left: 4px solid #10b981;
    }
    
    .notification-error {
        border-left: 4px solid #ef4444;
    }
    
    .notification-info {
        border-left: 4px solid #3b82f6;
    }
    
    .notification-close {
        position: absolute;
        top: 10px;
        right: 10px;
        background: none;
        border: none;
        font-size: 18px;
        cursor: pointer;
        color: #64748b;
        padding: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    
    .notification-close:hover {
        background-color: #f1f5f9;
    }
    
    .btn-disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    
    @media (max-width: 768px) {
        .expiry-modal {
            width: 95%;
            margin: 20px;
        }
        
        .modal-actions {
            flex-direction: column;
        }
        
        .notification {
            right: 10px;
            left: 10px;
            max-width: none;
        }
    }
`;

document.head.appendChild(style);

// Export functions for global use
window.ExpiryTracker = {
    showHandleExpiryModal,
    showNotification,
    updateCriticalAlerts,
    updateExpiryDates
};
