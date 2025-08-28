// Settings JavaScript functionality
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
    
    // Theme color picker functionality
    const themeColor = document.getElementById('theme_color');
    const themeColorHex = document.getElementById('theme_color_hex');
    const previewHeader = document.getElementById('previewHeader');
    const previewButton = document.getElementById('previewButton');
    
    if (themeColor && themeColorHex) {
        // Update hex value when color changes
        themeColor.addEventListener('input', function() {
            const color = this.value;
            themeColorHex.value = color;
            
            // Update preview elements
            if (previewHeader) {
                previewHeader.style.backgroundColor = color;
            }
            if (previewButton) {
                previewButton.style.backgroundColor = color;
            }
            
            // Update CSS custom property for real-time preview
            document.documentElement.style.setProperty('--primary-color', color);
        });
        
        // Allow manual hex input
        themeColorHex.addEventListener('input', function() {
            const hexColor = this.value;
            if (isValidHexColor(hexColor)) {
                themeColor.value = hexColor;
                
                // Update preview elements
                if (previewHeader) {
                    previewHeader.style.backgroundColor = hexColor;
                }
                if (previewButton) {
                    previewButton.style.backgroundColor = hexColor;
                }
                
                // Update CSS custom property
                document.documentElement.style.setProperty('--primary-color', hexColor);
            }
        });
    }
    
    // Form validation
    const settingsForms = document.querySelectorAll('.settings-form');
    settingsForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            // Clear previous validation states
            form.querySelectorAll('.is-invalid').forEach(field => {
                field.classList.remove('is-invalid');
            });
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    showFieldError(field, 'This field is required');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                    clearFieldError(field);
                }
            });
            
            // Validate specific fields
            const emailFields = form.querySelectorAll('input[type="email"]');
            emailFields.forEach(field => {
                if (field.value && !isValidEmail(field.value)) {
                    field.classList.add('is-invalid');
                    showFieldError(field, 'Please enter a valid email address');
                    isValid = false;
                }
            });
            
            // Validate URL fields
            const urlFields = form.querySelectorAll('input[type="url"]');
            urlFields.forEach(field => {
                if (field.value && !isValidUrl(field.value)) {
                    field.classList.add('is-invalid');
                    showFieldError(field, 'Please enter a valid URL (e.g., https://example.com)');
                    isValid = false;
                }
            });
            
            // Validate tax rate
            const taxRate = form.querySelector('#tax_rate');
            if (taxRate && taxRate.value) {
                const rate = parseFloat(taxRate.value);
                if (rate < 0 || rate > 100) {
                    taxRate.classList.add('is-invalid');
                    showFieldError(taxRate, 'Tax rate must be between 0 and 100');
                    isValid = false;
                }
            }
            
            // Validate currency symbol
            const currencySymbol = form.querySelector('#currency_symbol');
            if (currencySymbol && currencySymbol.value.length > 10) {
                currencySymbol.classList.add('is-invalid');
                showFieldError(currencySymbol, 'Currency symbol must be 10 characters or less');
                isValid = false;
            }
            
            // Validate low stock threshold
            const lowStockThreshold = form.querySelector('#low_stock_threshold');
            if (lowStockThreshold && lowStockThreshold.value) {
                const threshold = parseInt(lowStockThreshold.value);
                if (threshold < 0) {
                    lowStockThreshold.classList.add('is-invalid');
                    showFieldError(lowStockThreshold, 'Low stock threshold cannot be negative');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                e.preventDefault();
                showAlert('Please fix the errors below before saving', 'danger');
                
                // Scroll to first error
                const firstError = form.querySelector('.is-invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after timeout as fallback
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 10000);
                }
            }
        });
        
        // Real-time validation
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                if (this.classList.contains('is-invalid')) {
                    validateField(this);
                }
                
                // Update character count for textareas
                if (this.tagName === 'TEXTAREA') {
                    updateCharacterCount(this);
                }
            });
        });
        
        // Initialize character counts
        form.querySelectorAll('textarea').forEach(textarea => {
            updateCharacterCount(textarea);
        });
    });
    
    // Character count for textareas with maxlength
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
        countElement.style.color = remaining < 20 ? 'var(--warning-color)' : 'var(--secondary-color)';
        
        if (remaining < 0) {
            countElement.style.color = 'var(--danger-color)';
            textarea.classList.add('is-invalid');
        } else if (textarea.classList.contains('is-invalid') && !textarea.hasAttribute('required')) {
            textarea.classList.remove('is-invalid');
        }
    }
    
    // Auto-save functionality (optional)
    let autoSaveTimeout;
    const autoSaveFields = document.querySelectorAll('.auto-save');
    autoSaveFields.forEach(field => {
        field.addEventListener('input', function() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Implement auto-save logic here if needed
                console.log('Auto-saving field:', field.name);
            }, 2000);
        });
    });
    
    // Reset form functionality
    const resetButtons = document.querySelectorAll('button[type="reset"]');
    resetButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            if (form && confirm('Are you sure you want to reset all changes? This will restore the original values.')) {
                form.reset();
                
                // Clear validation states
                form.querySelectorAll('.is-invalid').forEach(field => {
                    field.classList.remove('is-invalid');
                });
                
                // Clear character counts
                form.querySelectorAll('.char-count').forEach(count => {
                    count.remove();
                });
                
                // Reset theme preview if on appearance tab
                if (themeColor) {
                    const originalColor = themeColor.defaultValue;
                    document.documentElement.style.setProperty('--primary-color', originalColor);
                    if (previewHeader) previewHeader.style.backgroundColor = originalColor;
                    if (previewButton) previewButton.style.backgroundColor = originalColor;
                }
                
                showAlert('Form has been reset to original values', 'info');
            }
        });
    });
    
    // Tab navigation with URL updates
    const tabLinks = document.querySelectorAll('.nav-tabs .nav-link');
    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Update URL without page reload
            const url = new URL(this.href);
            const tab = url.searchParams.get('tab');
            if (tab) {
                history.pushState({tab: tab}, '', `?tab=${tab}`);
            }
        });
    });
    
    // Handle browser back/forward buttons
    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.tab) {
            // Reload page to show correct tab
            location.reload();
        }
    });
    
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Utility functions
    function validateField(field) {
        let isValid = true;
        
        if (field.hasAttribute('required') && !field.value.trim()) {
            field.classList.add('is-invalid');
            showFieldError(field, 'This field is required');
            isValid = false;
        } else if (field.type === 'email' && field.value && !isValidEmail(field.value)) {
            field.classList.add('is-invalid');
            showFieldError(field, 'Please enter a valid email address');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
            clearFieldError(field);
        }
        
        return isValid;
    }
    
    function showFieldError(field, message) {
        let feedback = field.parentNode.querySelector('.invalid-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            field.parentNode.appendChild(feedback);
        }
        feedback.textContent = message;
    }
    
    function clearFieldError(field) {
        const feedback = field.parentNode.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = '';
        }
    }
    
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }
    
    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
    
    function isValidHexColor(hex) {
        const hexRegex = /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/;
        return hexRegex.test(hex);
    }
    
    function showAlert(message, type = 'info') {
        // Remove existing auto alerts
        document.querySelectorAll('.alert.auto-alert').forEach(alert => {
            alert.remove();
        });
        
        const content = document.querySelector('.content');
        if (!content) return;
        
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} auto-alert`;
        alert.innerHTML = `
            <i class="bi bi-${type === 'danger' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
            ${message}
        `;
        
        content.insertBefore(alert, content.firstChild);
        
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
    
    console.log('Settings module initialized successfully');
});

// Global utility functions
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

// Export/Import settings functionality
window.exportSettings = function() {
    // Implement settings export logic
    console.log('Exporting settings...');
};

window.importSettings = function() {
    // Implement settings import logic
    console.log('Importing settings...');
};