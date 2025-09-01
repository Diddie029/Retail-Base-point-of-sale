// Expense Management JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Form validation
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Amount calculation
    var amountInput = document.getElementById('amount');
    var taxInput = document.getElementById('tax_amount');
    var totalInput = document.getElementById('total_amount');

    if (amountInput && taxInput && totalInput) {
        function calculateTotal() {
            var amount = parseFloat(amountInput.value) || 0;
            var tax = parseFloat(taxInput.value) || 0;
            var total = amount + tax;
            totalInput.value = total.toFixed(2);
        }

        amountInput.addEventListener('input', calculateTotal);
        taxInput.addEventListener('input', calculateTotal);
        calculateTotal(); // Initial calculation
    }

    // Recurring expense toggle
    var recurringCheckbox = document.getElementById('is_recurring');
    var recurringOptions = document.getElementById('recurring_options');

    if (recurringCheckbox && recurringOptions) {
        function toggleRecurringOptions() {
            recurringOptions.style.display = recurringCheckbox.checked ? 'block' : 'none';
        }

        recurringCheckbox.addEventListener('change', toggleRecurringOptions);
        toggleRecurringOptions(); // Initial state
    }

    // Category change handler
    var categorySelect = document.getElementById('category_id');
    var subcategorySelect = document.getElementById('subcategory_id');

    if (categorySelect && subcategorySelect) {
        categorySelect.addEventListener('change', function() {
            var categoryId = this.value;
            loadSubcategories(categoryId);
        });
    }

    // Date picker enhancements
    var dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(function(input) {
        // Set max date to today for expense date
        if (input.name === 'expense_date') {
            input.max = new Date().toISOString().split('T')[0];
        }
        
        // Set min date to today for due date
        if (input.name === 'due_date') {
            input.min = new Date().toISOString().split('T')[0];
        }
    });

    // File upload validation
    var fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            var file = this.files[0];
            if (file) {
                // Check file size (5MB limit)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }

                // Check file type
                var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid file type (JPG, PNG, PDF, DOC, DOCX)');
                    this.value = '';
                    return;
                }
            }
        });
    });

    // Table row selection
    var tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach(function(row) {
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on buttons or links
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('button') || e.target.closest('a')) {
                return;
            }
            
            // Toggle selection
            this.classList.toggle('table-active');
        });
    });

    // Bulk actions
    var selectAllCheckbox = document.getElementById('select-all');
    var rowCheckboxes = document.querySelectorAll('.row-checkbox');
    var bulkActions = document.getElementById('bulk-actions');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            rowCheckboxes.forEach(function(checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateBulkActions();
        });
    }

    if (rowCheckboxes.length > 0) {
        rowCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateBulkActions();
                updateSelectAll();
            });
        });
    }

    function updateBulkActions() {
        var checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
        if (bulkActions) {
            bulkActions.style.display = checkedBoxes.length > 0 ? 'block' : 'none';
        }
    }

    function updateSelectAll() {
        var checkedBoxes = document.querySelectorAll('.row-checkbox:checked');
        var totalBoxes = rowCheckboxes.length;
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = checkedBoxes.length === totalBoxes;
            selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < totalBoxes;
        }
    }

    // Search functionality
    var searchInput = document.getElementById('search-input');
    if (searchInput) {
        var searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performSearch();
            }, 300);
        });
    }

    function performSearch() {
        var searchTerm = searchInput.value.toLowerCase();
        var tableRows = document.querySelectorAll('.table tbody tr');
        
        tableRows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            if (text.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Export functionality
    var exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            exportData();
        });
    }

    function exportData() {
        // Get current filters
        var filters = new URLSearchParams(window.location.search);
        
        // Create export URL
        var exportUrl = 'export.php?' + filters.toString();
        
        // Trigger download
        window.location.href = exportUrl;
    }

    // Print functionality
    var printBtn = document.getElementById('print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }

    // Confirmation dialogs
    var deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Loading states
    var forms = document.querySelectorAll('form');
    forms.forEach(function(form) {
        form.addEventListener('submit', function() {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            }
        });
    });

    // Auto-save draft
    var autoSaveTimeout;
    var formInputs = document.querySelectorAll('form input, form textarea, form select');
    
    formInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(function() {
                saveDraft();
            }, 2000);
        });
    });

    function saveDraft() {
        var formData = new FormData();
        var form = document.querySelector('form');
        
        if (form) {
            var inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(function(input) {
                if (input.name) {
                    formData.append(input.name, input.value);
                }
            });
            
            // Save to localStorage
            var draftKey = 'expense_draft_' + Date.now();
            localStorage.setItem(draftKey, JSON.stringify(Object.fromEntries(formData)));
            
            // Show notification
            showNotification('Draft saved automatically', 'info');
        }
    }

    // Notification system
    function showNotification(message, type = 'info') {
        var notification = document.createElement('div');
        notification.className = 'alert alert-' + type + ' alert-dismissible fade show position-fixed';
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + N for new expense
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'add.php';
        }
        
        // Ctrl/Cmd + F for search
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            var searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Escape to close modals
        if (e.key === 'Escape') {
            var modals = document.querySelectorAll('.modal.show');
            modals.forEach(function(modal) {
                var modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            });
        }
    });

    // Responsive table
    var tables = document.querySelectorAll('.table-responsive');
    tables.forEach(function(table) {
        var wrapper = table.parentElement;
        if (wrapper && wrapper.scrollWidth > wrapper.clientWidth) {
            wrapper.classList.add('has-horizontal-scroll');
        }
    });

    // Initialize any additional plugins or features
    initializeExpenseFeatures();
});

// Load subcategories function
function loadSubcategories(categoryId) {
    var subcategorySelect = document.getElementById('subcategory_id');
    if (!subcategorySelect) return;

    subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
    
    if (categoryId) {
        fetch('../api/get_subcategories.php?category_id=' + categoryId)
            .then(response => response.json())
            .then(data => {
                data.forEach(subcategory => {
                    var option = document.createElement('option');
                    option.value = subcategory.id;
                    option.textContent = subcategory.name;
                    subcategorySelect.appendChild(option);
                });
            })
            .catch(error => {
                console.error('Error loading subcategories:', error);
            });
    }
}

// Initialize expense-specific features
function initializeExpenseFeatures() {
    // Category color indicators
    var categoryBadges = document.querySelectorAll('.badge[style*="background-color"]');
    categoryBadges.forEach(function(badge) {
        var color = badge.style.backgroundColor;
        if (color) {
            badge.style.border = '1px solid ' + color;
        }
    });

    // Status indicators
    var statusCells = document.querySelectorAll('td:has(.badge)');
    statusCells.forEach(function(cell) {
        var badge = cell.querySelector('.badge');
        if (badge) {
            var status = badge.textContent.toLowerCase();
            var indicator = document.createElement('span');
            indicator.className = 'status-indicator status-' + status;
            badge.parentNode.insertBefore(indicator, badge);
        }
    });

    // Amount formatting
    var amountCells = document.querySelectorAll('td:contains("KES")');
    amountCells.forEach(function(cell) {
        var text = cell.textContent;
        var amount = parseFloat(text.replace(/[^\d.-]/g, ''));
        if (amount < 0) {
            cell.classList.add('amount-negative');
        } else if (amount > 0) {
            cell.classList.add('amount-positive');
        }
    });
}

// Utility functions
function formatCurrency(amount, currency = 'KES') {
    return currency + ' ' + parseFloat(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-KE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function debounce(func, wait) {
    var timeout;
    return function executedFunction(...args) {
        var later = function() {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Export functions for use in other scripts
window.ExpenseManager = {
    formatCurrency: formatCurrency,
    formatDate: formatDate,
    loadSubcategories: loadSubcategories,
    showNotification: showNotification
};
