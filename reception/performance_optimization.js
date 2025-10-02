/**
 * Performance Optimization Utilities
 * Helps prevent forced reflows and improves JavaScript performance
 */

// Debounce function to prevent excessive function calls
function debounce(func, wait, immediate) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            timeout = null;
            if (!immediate) func.apply(this, args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func.apply(this, args);
    };
}

// Throttle function for scroll/resize events
function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// Batch DOM updates to prevent forced reflows
function batchDOMUpdates(updates) {
    requestAnimationFrame(() => {
        updates.forEach(update => update());
    });
}

// Performance-optimized element creation
function createOptimizedElement(tag, className, innerHTML) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (innerHTML) element.innerHTML = innerHTML;
    return element;
}

// Efficient event delegation
function addDelegatedEventListener(parent, selector, event, handler) {
    parent.addEventListener(event, function(e) {
        if (e.target.matches(selector)) {
            handler.call(e.target, e);
        }
    });
}

// Export functions for global use
window.performanceUtils = {
    debounce,
    throttle,
    batchDOMUpdates,
    createOptimizedElement,
    addDelegatedEventListener
};
