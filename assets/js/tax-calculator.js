/**
 * Advanced Tax Calculator for POS System
 * Handles multiple tax rates, compound taxes, and exemptions
 */

class TaxCalculator {
    constructor(config) {
        this.config = config || {};
        this.taxRates = {};
        this.taxCategories = {};
        this.customerExemptions = {};
        this.productExemptions = {};
    }

    /**
     * Initialize tax calculator with data from server
     */
    async initialize() {
        try {
            // Load tax categories and rates
            await this.loadTaxData();
            console.log('Tax Calculator initialized successfully');
        } catch (error) {
            console.error('Failed to initialize Tax Calculator:', error);
        }
    }

    /**
     * Load tax data from server
     */
    async loadTaxData() {
        try {
            const response = await fetch('api/tax/get_tax_data.php');
            const data = await response.json();
            
            if (data.success) {
                this.taxRates = data.tax_rates || {};
                this.taxCategories = data.tax_categories || {};
                this.customerExemptions = data.customer_exemptions || {};
                this.productExemptions = data.product_exemptions || {};
            }
        } catch (error) {
            console.error('Error loading tax data:', error);
        }
    }

    /**
     * Calculate taxes for cart items
     */
    calculateCartTaxes(cartItems, customerId = null, saleDate = null) {
        if (!saleDate) {
            saleDate = new Date().toISOString().split('T')[0];
        }

        const taxes = [];
        let totalTaxableAmount = 0;
        let totalTaxAmount = 0;

        // Check if customer is tax exempt
        if (customerId && this.isCustomerTaxExempt(customerId, saleDate)) {
            return {
                taxes: [],
                totalTaxableAmount: 0,
                totalTaxAmount: 0,
                customerExempt: true
            };
        }

        // Group items by tax category
        const categoryTotals = {};
        
        cartItems.forEach(item => {
            const productId = item.product_id;
            const quantity = item.quantity;
            const unitPrice = item.unit_price;
            const lineTotal = quantity * unitPrice;

            // Check if product is tax exempt
            if (this.isProductTaxExempt(productId, saleDate)) {
                return;
            }

            // Get product tax category
            const taxCategoryId = this.getProductTaxCategory(productId);
            if (!taxCategoryId) {
                return;
            }

            if (!categoryTotals[taxCategoryId]) {
                categoryTotals[taxCategoryId] = 0;
            }
            categoryTotals[taxCategoryId] += lineTotal;
        });

        // Calculate taxes for each category
        Object.keys(categoryTotals).forEach(categoryId => {
            const categoryTaxes = this.calculateCategoryTaxes(
                categoryId, 
                categoryTotals[categoryId], 
                saleDate
            );
            taxes.push(...categoryTaxes);
            totalTaxableAmount += categoryTotals[categoryId];
        });

        // Calculate compound taxes
        const compoundTaxes = taxes.filter(tax => tax.is_compound);
        
        if (compoundTaxes.length > 0) {
            const compoundBase = totalTaxableAmount + taxes.reduce((sum, tax) => sum + tax.tax_amount, 0);
            
            compoundTaxes.forEach(tax => {
                tax.tax_amount = compoundBase * tax.tax_rate;
                tax.taxable_amount = compoundBase;
            });
        }

        totalTaxAmount = taxes.reduce((sum, tax) => sum + tax.tax_amount, 0);

        return {
            taxes,
            totalTaxableAmount,
            totalTaxAmount,
            customerExempt: false
        };
    }

    /**
     * Calculate taxes for a specific category
     */
    calculateCategoryTaxes(categoryId, taxableAmount, saleDate) {
        const categoryRates = this.getActiveTaxRates(categoryId, saleDate);
        const taxes = [];

        categoryRates.forEach(rate => {
            const taxAmount = taxableAmount * rate.rate;
            
            taxes.push({
                tax_rate_id: rate.id,
                tax_category_name: rate.category_name,
                tax_name: rate.name,
                tax_rate: rate.rate,
                taxable_amount: taxableAmount,
                tax_amount: taxAmount,
                is_compound: rate.is_compound
            });
        });

        return taxes;
    }

    /**
     * Get active tax rates for a category
     */
    getActiveTaxRates(categoryId, saleDate) {
        if (!this.taxRates[categoryId]) {
            return [];
        }

        return this.taxRates[categoryId].filter(rate => {
            const effectiveDate = new Date(rate.effective_date);
            const endDate = rate.end_date ? new Date(rate.end_date) : null;
            const currentDate = new Date(saleDate);

            return rate.is_active && 
                   effectiveDate <= currentDate && 
                   (!endDate || endDate >= currentDate);
        }).sort((a, b) => {
            // Sort by compound status (non-compound first), then by effective date
            if (a.is_compound !== b.is_compound) {
                return a.is_compound - b.is_compound;
            }
            return new Date(b.effective_date) - new Date(a.effective_date);
        });
    }

    /**
     * Check if customer is tax exempt
     */
    isCustomerTaxExempt(customerId, saleDate) {
        if (!this.customerExemptions[customerId]) {
            return false;
        }

        const exemptions = this.customerExemptions[customerId];
        const currentDate = new Date(saleDate);

        return exemptions.some(exemption => {
            const effectiveDate = new Date(exemption.effective_date);
            const endDate = exemption.end_date ? new Date(exemption.end_date) : null;

            return exemption.is_active &&
                   effectiveDate <= currentDate &&
                   (!endDate || endDate >= currentDate);
        });
    }

    /**
     * Check if product is tax exempt
     */
    isProductTaxExempt(productId, saleDate) {
        if (!this.productExemptions[productId]) {
            return false;
        }

        const exemptions = this.productExemptions[productId];
        const currentDate = new Date(saleDate);

        return exemptions.some(exemption => {
            const effectiveDate = new Date(exemption.effective_date);
            const endDate = exemption.end_date ? new Date(exemption.end_date) : null;

            return exemption.is_active &&
                   effectiveDate <= currentDate &&
                   (!endDate || endDate >= currentDate);
        });
    }

    /**
     * Get product tax category
     */
    getProductTaxCategory(productId) {
        // This would typically come from product data
        // For now, we'll assume it's passed in the product object
        return null; // Will be implemented based on product data structure
    }

    /**
     * Format tax amount for display
     */
    formatTaxAmount(amount, currencySymbol = '$') {
        return `${currencySymbol}${amount.toFixed(2)}`;
    }

    /**
     * Format tax rate for display
     */
    formatTaxRate(rate) {
        return `${(rate * 100).toFixed(2)}%`;
    }

    /**
     * Get tax summary for display
     */
    getTaxSummary(taxes) {
        const summary = {};
        
        taxes.forEach(tax => {
            const key = `${tax.tax_category_name} - ${tax.tax_name}`;
            
            if (!summary[key]) {
                summary[key] = {
                    category: tax.tax_category_name,
                    name: tax.tax_name,
                    rate: tax.tax_rate,
                    taxable_amount: 0,
                    tax_amount: 0,
                    is_compound: tax.is_compound
                };
            }
            
            summary[key].taxable_amount += tax.taxable_amount;
            summary[key].tax_amount += tax.tax_amount;
        });

        return Object.values(summary);
    }

    /**
     * Update cart display with tax information
     */
    updateCartTaxDisplay(cartItems, customerId = null) {
        const taxCalculation = this.calculateCartTaxes(cartItems, customerId);
        const taxSummary = this.getTaxSummary(taxCalculation.taxes);

        // Update tax rows in cart
        this.updateTaxRows(taxSummary);
        
        // Update totals
        this.updateCartTotals(taxCalculation);
    }

    /**
     * Update tax rows in cart display
     */
    updateTaxRows(taxSummary) {
        const taxRowsContainer = document.getElementById('cartMultipleTaxRows');
        if (!taxRowsContainer) return;

        // Clear existing tax rows
        taxRowsContainer.innerHTML = '';

        if (taxSummary.length === 0) {
            return;
        }

        // Add individual tax rows
        taxSummary.forEach(tax => {
            const taxRow = document.createElement('div');
            taxRow.className = 'cart-total-row';
            
            const compoundLabel = tax.is_compound ? ' (Compound)' : '';
            const rateDisplay = this.formatTaxRate(tax.rate);
            
            taxRow.innerHTML = `
                <span>${tax.category} - ${tax.name} (${rateDisplay})${compoundLabel}:</span>
                <span>${this.formatTaxAmount(tax.tax_amount)}</span>
            `;
            
            taxRowsContainer.appendChild(taxRow);
        });
    }

    /**
     * Update cart totals
     */
    updateCartTotals(taxCalculation) {
        const subtotalElement = document.getElementById('cartSubtotal');
        const totalElement = document.getElementById('cartTotal');
        
        if (subtotalElement) {
            subtotalElement.textContent = this.formatTaxAmount(taxCalculation.totalTaxableAmount);
        }
        
        if (totalElement) {
            const total = taxCalculation.totalTaxableAmount + taxCalculation.totalTaxAmount;
            totalElement.textContent = this.formatTaxAmount(total);
        }
    }

    /**
     * Save tax details for a sale
     */
    async saveSaleTaxes(saleId, taxes) {
        try {
            const response = await fetch('api/tax/save_sale_taxes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    sale_id: saleId,
                    taxes: taxes
                })
            });

            const result = await response.json();
            return result.success;
        } catch (error) {
            console.error('Error saving sale taxes:', error);
            return false;
        }
    }
}

// Global tax calculator instance
window.taxCalculator = new TaxCalculator();

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (window.taxCalculator) {
        window.taxCalculator.initialize();
    }
});
