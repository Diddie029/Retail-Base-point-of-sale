// Categories JavaScript functionality
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
    
    // Category filtering functionality
    const filterForm = document.getElementById('filterForm');
    const searchInput = document.getElementById('searchInput');
    
    if (filterForm && searchInput) {
        // Auto-submit filter form on input change with debounce
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                filterForm.submit();
            }, 500);
        });
    }
    
    // Category form validation
    const categoryForm = document.getElementById('categoryForm');
    if (categoryForm) {
        categoryForm.addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = categoryForm.querySelectorAll('[required]');
            
            // Clear previous validation states
            categoryForm.querySelectorAll('.is-invalid').forEach(field => {
                field.classList.remove('is-invalid');
            });
            
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
                    if (feedback && !feedback.textContent.includes('already exists')) {
                        feedback.textContent = '';
                    }
                }
            });
            
            // Validate category name length
            const nameField = document.getElementById('name');
            if (nameField && nameField.value.trim().length > 100) {
                nameField.classList.add('is-invalid');
                const feedback = nameField.parentNode.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.textContent = 'Category name must be 100 characters or less';
                }
                isValid = false;
            }
            
            // Validate description length
            const descField = document.getElementById('description');
            if (descField && descField.value.trim().length > 500) {
                descField.classList.add('is-invalid');
                const feedback = descField.parentNode.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.textContent = 'Description must be 500 characters or less';
                }
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                showAlert('Please fix the errors below', 'danger');
            }
        });
        
        // Real-time validation
        const inputs = categoryForm.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                if (this.classList.contains('is-invalid')) {
                    validateField(this);
                }
                
                // Update character count for textarea
                if (this.tagName === 'TEXTAREA') {
                    updateCharacterCount(this);
                }
            });
        });
        
        // Initialize character counts
        categoryForm.querySelectorAll('textarea').forEach(textarea => {
            updateCharacterCount(textarea);
        });
    }
    
    // Delete confirmation
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const categoryName = this.dataset.categoryName || 'this category';
            const productCount = parseInt(this.dataset.productCount) || 0;
            
            let message;
            if (productCount > 0) {
                message = `Are you sure you want to delete "${categoryName}"? This category contains ${productCount} product(s) that will need to be reassigned. This action cannot be undone.`;
            } else {
                message = `Are you sure you want to delete "${categoryName}"? This action cannot be undone.`;
            }
            
            if (confirm(message)) {
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
    
    // Sort functionality
    const sortLinks = document.querySelectorAll('.sort-link');
    sortLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Add loading indicator
            const icon = this.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-hourglass-split';
            }
        });
    });
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Character count for textareas
    function updateCharacterCount(textarea) {
        const maxLength = parseInt(textarea.getAttribute('maxlength'));
        if (!maxLength) return;
        
        const currentLength = textarea.value.length;
        const remaining = maxLength - currentLength;
        
        let countElement = textarea.parentNode.querySelector('.char-count');
        if (!countElement) {
            countElement = document.createElement('div');
            countElement.className = 'char-count';
            countElement.style.fontSize = '0.75rem';
            countElement.style.marginTop = '0.25rem';
            textarea.parentNode.appendChild(countElement);
        }
        
        countElement.textContent = `${currentLength}/${maxLength} characters`;
        countElement.style.color = remaining < 50 ? 'var(--warning-color)' : 'var(--secondary-color)';
        
        if (remaining < 0) {
            countElement.style.color = 'var(--danger-color)';
            textarea.classList.add('is-invalid');
        } else {
            textarea.classList.remove('is-invalid');
        }
    }
    
    // Helper function to validate individual fields
    function validateField(field) {
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        let isValid = true;
        
        if (field.hasAttribute('required') && !field.value.trim()) {
            field.classList.add('is-invalid');
            if (feedback) {
                feedback.textContent = 'This field is required';
            }
            isValid = false;
        } else if (field.id === 'name' && field.value.trim().length > 100) {
            field.classList.add('is-invalid');
            if (feedback) {
                feedback.textContent = 'Category name must be 100 characters or less';
            }
            isValid = false;
        } else if (field.id === 'description' && field.value.trim().length > 500) {
            field.classList.add('is-invalid');
            if (feedback) {
                feedback.textContent = 'Description must be 500 characters or less';
            }
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
            if (feedback && !feedback.textContent.includes('already exists')) {
                feedback.textContent = '';
            }
        }
        
        return isValid;
    }
    
    // Show alert messages
    function showAlert(message, type = 'info') {
        // Remove existing alerts
        document.querySelectorAll('.alert.auto-alert').forEach(alert => {
            alert.remove();
        });
        
        const alertContainer = document.querySelector('.content');
        if (!alertContainer) return;
        
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} auto-alert`;
        alert.innerHTML = `
            <i class="bi bi-${type === 'danger' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" aria-label="Close" onclick="this.parentElement.remove()"></button>
        `;
        
        alertContainer.insertBefore(alert, alertContainer.firstChild);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }
    
    // Handle search clear
    const clearSearchBtn = document.querySelector('a[href*="categories.php"]:not([href*="?"])');
    if (clearSearchBtn && clearSearchBtn.textContent.includes('Clear')) {
        clearSearchBtn.addEventListener('click', function(e) {
            if (searchInput) {
                searchInput.value = '';
            }
        });
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
    
    // Form submission loading states
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Processing...';
                submitBtn.disabled = true;
                
                // Re-enable after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 10000);
            }
        });
    });
    
    // Table row highlighting
    const tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(99, 102, 241, 0.05)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
    
    // Auto-focus on first input when page loads
    const firstInput = document.querySelector('input:not([type="hidden"]), textarea, select');
    if (firstInput && !firstInput.value) {
        firstInput.focus();
    }
    
    console.log('Categories module initialized successfully');
});

// Global utility functions
window.confirmDelete = function(url, name, productCount = 0) {
    let message;
    if (productCount > 0) {
        message = `Are you sure you want to delete "${name}"? This category contains ${productCount} product(s) that will need to be reassigned. This action cannot be undone.`;
    } else {
        message = `Are you sure you want to delete "${name}"? This action cannot be undone.`;
    }
    
    if (confirm(message)) {
        window.location.href = url;
    }
};

// Format numbers with commas
window.formatNumber = function(num) {
    return new Intl.NumberFormat().format(num);
};

// Debounce function for search
window.debounce = function(func, wait, immediate) {
    let timeout;
    return function executedFunction() {
        const context = this;
        const args = arguments;
        const later = function() {
            timeout = null;
            if (!immediate) func.apply(context, args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func.apply(context, args);
    };
};

// Copy to clipboard function
window.copyToClipboard = function(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            console.log('Text copied to clipboard');
        });
    } else {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            console.log('Text copied to clipboard');
        } catch (err) {
            console.error('Failed to copy text: ', err);
        }
        document.body.removeChild(textArea);
    }
};