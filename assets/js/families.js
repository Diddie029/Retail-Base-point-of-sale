// Family Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Toggle family status functionality
    const toggleButtons = document.querySelectorAll('.toggle-status');

    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const familyId = this.getAttribute('data-id');
            const currentStatus = this.getAttribute('data-current-status');
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';

            // Show loading state
            const originalIcon = this.innerHTML;
            this.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            this.disabled = true;

            // Create form data
            const formData = new FormData();
            formData.append('toggle_family', '1');
            formData.append('family_id', familyId);

            // Send AJAX request
            fetch('families.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Update button appearance
                    if (newStatus === 'active') {
                        this.className = 'btn btn-sm btn-warning toggle-status';
                        this.setAttribute('data-current-status', 'active');
                        this.setAttribute('title', 'Deactivate Family');
                        this.innerHTML = '<i class="bi bi-pause-fill"></i>';
                    } else {
                        this.className = 'btn btn-sm btn-success toggle-status';
                        this.setAttribute('data-current-status', 'inactive');
                        this.setAttribute('title', 'Activate Family');
                        this.innerHTML = '<i class="bi bi-play-fill"></i>';
                    }

                    // Update status badge in the same row
                    const row = this.closest('tr');
                    const statusBadge = row.querySelector('.badge');
                    if (statusBadge) {
                        if (newStatus === 'active') {
                            statusBadge.className = 'badge badge-success';
                            statusBadge.textContent = 'Active';
                        } else {
                            statusBadge.className = 'badge badge-secondary';
                            statusBadge.textContent = 'Inactive';
                        }
                    }

                    // Show success message
                    showNotification('Family status updated successfully!', 'success');
                } else {
                    // Revert button state on error
                    this.innerHTML = originalIcon;
                    showNotification('Failed to update family status.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Revert button state on error
                this.innerHTML = originalIcon;
                showNotification('An error occurred while updating family status.', 'error');
            })
            .finally(() => {
                this.disabled = false;
            });
        });
    });

    // Delete confirmation
    const deleteButtons = document.querySelectorAll('.btn-delete');

    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const familyName = this.getAttribute('data-family-name');
            const productCount = this.getAttribute('data-product-count');

            let message = `Are you sure you want to delete the family "${familyName}"?`;

            if (productCount > 0) {
                message += `\n\nWarning: This family contains ${productCount} products. Deleting it will remove the family association from these products.`;
            }

            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const filterForm = document.getElementById('filterForm');

    if (searchInput && filterForm) {
        let searchTimeout;

        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterForm.submit();
            }, 500);
        });

        // Clear search on escape
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                filterForm.submit();
            }
        });
    }

    // Enhanced table interactions
    const tableRows = document.querySelectorAll('.table tbody tr');

    tableRows.forEach(row => {
        // Highlight row on hover
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-1px)';
            this.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.1)';
        });

        row.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });

    // Keyboard navigation for table
    document.addEventListener('keydown', function(e) {
        const activeElement = document.activeElement;

        // Only handle if we're in the table area
        if (!activeElement.closest('.table')) {
            return;
        }

        const currentRow = activeElement.closest('tr');
        if (!currentRow) return;

        let nextRow;

        switch (e.key) {
            case 'ArrowUp':
                nextRow = currentRow.previousElementSibling;
                if (nextRow && nextRow.tagName === 'TR') {
                    e.preventDefault();
                    focusRow(nextRow);
                }
                break;
            case 'ArrowDown':
                nextRow = currentRow.nextElementSibling;
                if (nextRow && nextRow.tagName === 'TR') {
                    e.preventDefault();
                    focusRow(nextRow);
                }
                break;
        }
    });

    function focusRow(row) {
        const firstLink = row.querySelector('a, button');
        if (firstLink) {
            firstLink.focus();
        }
    }

    // Auto-refresh stats (optional - can be enabled if needed)
    function refreshStats() {
        // This could fetch updated stats via AJAX if needed
        // For now, we'll keep it simple
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

    // Export functionality (if needed)
    function exportFamilies() {
        const url = new URL(window.location);
        url.searchParams.set('export', 'csv');
        window.open(url.toString(), '_blank');
    }

    // Make export function globally available
    window.exportFamilies = exportFamilies;

    // Bulk operations (if needed)
    function initializeBulkOperations() {
        // This can be expanded if bulk operations are needed
        // For now, individual operations are handled above
    }

    // Initialize bulk operations
    initializeBulkOperations();

    // Performance optimizations
    // Debounce scroll events
    let scrollTimeout;
    const tableContainer = document.querySelector('.table-responsive');

    if (tableContainer) {
        tableContainer.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                // Handle scroll-based loading or other features if needed
            }, 100);
        });
    }

    // Lazy load images if any (placeholder for future enhancement)
    const imageObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                // Load image if needed
                imageObserver.unobserve(img);
            }
        });
    });

    // Observe any images that might be added later
    document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
    });

    // Error handling for failed AJAX requests
    window.addEventListener('unhandledrejection', function(event) {
        console.error('Unhandled promise rejection:', event.reason);
        showNotification('An unexpected error occurred. Please try again.', 'error');
    });

    // Handle network connectivity
    window.addEventListener('online', function() {
        showNotification('Connection restored.', 'success');
    });

    window.addEventListener('offline', function() {
        showNotification('Connection lost. Some features may not work properly.', 'error');
    });

    // Memory cleanup
    window.addEventListener('beforeunload', function() {
        // Clear any timers or observers
        if (scrollTimeout) {
            clearTimeout(scrollTimeout);
        }
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
    });
});

// Utility functions for global use
window.FamilyUtils = {
    formatCurrency: function(amount, currencySymbol = '$') {
        return currencySymbol + parseFloat(amount).toFixed(2);
    },

    formatNumber: function(num) {
        return new Intl.NumberFormat().format(num);
    },

    formatDate: function(dateString) {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },

    truncateText: function(text, maxLength = 100) {
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
};
