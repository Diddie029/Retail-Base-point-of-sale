/**
 * Supplier Performance JavaScript
 * Handles interactive charts, filtering, and real-time updates
 */

class SupplierPerformance {
    constructor() {
        this.charts = {};
        this.currentPeriod = '90days';
        this.supplierId = null;
        this.init();
    }

    init() {
        this.supplierId = this.getUrlParameter('id');
        this.currentPeriod = this.getUrlParameter('period') || '90days';

        // Initialize charts when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            this.initializeCharts();
            this.bindEvents();
            this.loadPerformanceData();
        });
    }

    /**
     * Get URL parameter value
     */
    getUrlParameter(name) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(name);
    }

    /**
     * Initialize all charts
     */
    initializeCharts() {
        this.initializeCostComparisonChart();
        this.initializePerformanceTrendChart();
        this.initializeDeliveryPerformanceChart();
        this.initializeQualityMetricsChart();
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Period selector buttons
        const periodButtons = document.querySelectorAll('.period-button');
        periodButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const period = button.getAttribute('data-period');
                this.changePeriod(period);
            });
        });

        // Export buttons
        const exportButtons = document.querySelectorAll('[data-export]');
        exportButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const type = button.getAttribute('data-export');
                this.exportData(type);
            });
        });

        // Refresh data button
        const refreshButton = document.querySelector('[data-action="refresh"]');
        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                this.refreshData();
            });
        }

        // Window resize handler for responsive charts
        window.addEventListener('resize', this.debounce(() => {
            this.resizeCharts();
        }, 250));
    }

    /**
     * Load performance data via AJAX
     */
    async loadPerformanceData() {
        if (!this.supplierId) return;

        try {
            const response = await fetch(`api/get_supplier_performance.php?id=${this.supplierId}&period=${this.currentPeriod}`);
            const data = await response.json();

            if (data.success) {
                this.updateCharts(data.data);
                this.updateMetrics(data.data);
            }
        } catch (error) {
            console.error('Error loading performance data:', error);
            this.showError('Failed to load performance data');
        }
    }

    /**
     * Initialize cost comparison chart
     */
    initializeCostComparisonChart() {
        const canvas = document.getElementById('costComparisonChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        this.charts.costComparison = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Supplier Cost',
                    data: [],
                    backgroundColor: 'rgba(99, 102, 241, 0.8)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false,
                }, {
                    label: 'Market Average',
                    data: [],
                    backgroundColor: 'rgba(156, 163, 175, 0.8)',
                    borderColor: 'rgba(156, 163, 175, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toFixed(2);
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': $' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    /**
     * Initialize performance trend chart
     */
    initializePerformanceTrendChart() {
        const canvas = document.getElementById('performanceTrendChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        this.charts.performanceTrend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Overall Score',
                    data: [],
                    borderColor: 'rgba(99, 102, 241, 1)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: false,
                        min: 0,
                        max: 100,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: {
                            title: function(context) {
                                return 'Period: ' + context[0].label;
                            },
                            label: function(context) {
                                return 'Performance Score: ' + context.parsed.y.toFixed(1) + '/100';
                            }
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    /**
     * Initialize delivery performance chart
     */
    initializeDeliveryPerformanceChart() {
        const canvas = document.getElementById('deliveryPerformanceChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        this.charts.deliveryPerformance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['On-Time', 'Late'],
                datasets: [{
                    data: [],
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderColor: [
                        'rgba(34, 197, 94, 1)',
                        'rgba(239, 68, 68, 1)'
                    ],
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.parsed / total) * 100).toFixed(1);
                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                animation: {
                    duration: 1200,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    /**
     * Initialize quality metrics chart
     */
    initializeQualityMetricsChart() {
        const canvas = document.getElementById('qualityMetricsChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        this.charts.qualityMetrics = new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['Return Rate', 'Quality Score', 'Defect Rate', 'Customer Satisfaction'],
                datasets: [{
                    label: 'Current Period',
                    data: [],
                    borderColor: 'rgba(99, 102, 241, 1)',
                    backgroundColor: 'rgba(99, 102, 241, 0.2)',
                    borderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 20,
                            callback: function(value) {
                                return value + '%';
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        angleLines: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        pointLabels: {
                            font: {
                                size: 12
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: 'white',
                        bodyColor: 'white',
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        cornerRadius: 8
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    /**
     * Update charts with new data
     */
    updateCharts(data) {
        // Update cost comparison chart
        if (this.charts.costComparison && data.cost_comparison) {
            this.charts.costComparison.data.labels = data.cost_comparison.map(item => item.category);
            this.charts.costComparison.data.datasets[0].data = data.cost_comparison.map(item => item.supplier_avg_cost);
            this.charts.costComparison.data.datasets[1].data = data.cost_comparison.map(item => item.market_avg_cost);
            this.charts.costComparison.update();
        }

        // Update delivery performance chart
        if (this.charts.deliveryPerformance && data.delivery_performance) {
            this.charts.deliveryPerformance.data.datasets[0].data = [
                data.delivery_performance.on_time_deliveries,
                data.delivery_performance.late_deliveries
            ];
            this.charts.deliveryPerformance.update();
        }

        // Update quality metrics chart
        if (this.charts.qualityMetrics && data.quality_metrics) {
            this.charts.qualityMetrics.data.datasets[0].data = [
                100 - data.quality_metrics.return_rate, // Invert return rate
                data.quality_metrics.quality_score,
                95, // Placeholder for defect rate
                data.quality_metrics.quality_score // Placeholder for satisfaction
            ];
            this.charts.qualityMetrics.update();
        }

        // Update performance trend chart
        if (this.charts.performanceTrend && data.performance_history) {
            const history = data.performance_history.slice(-12); // Last 12 months
            this.charts.performanceTrend.data.labels = history.map(item =>
                new Date(item.metric_date).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
            );
            this.charts.performanceTrend.data.datasets[0].data = history.map(item => item.quality_score);
            this.charts.performanceTrend.update();
        }
    }

    /**
     * Update metrics display
     */
    updateMetrics(data) {
        // Update overall score
        const overallScore = document.querySelector('.overall-score-value');
        if (overallScore && data.overall_score !== undefined) {
            overallScore.textContent = data.overall_score.toFixed(1);
            this.animateValue(overallScore, 0, data.overall_score, 1000);
        }

        // Update individual metrics
        this.updateMetric('.delivery-rate', data.delivery_performance?.on_time_percentage, '%');
        this.updateMetric('.quality-score', data.quality_metrics?.quality_score, '/100');
        this.updateMetric('.return-rate', data.quality_metrics?.return_rate, '%');
        this.updateMetric('.avg-delivery-days', data.delivery_performance?.average_delivery_days, ' days');

        // Update trend indicators
        this.updateTrends(data);
    }

    /**
     * Update individual metric value with animation
     */
    updateMetric(selector, value, suffix = '') {
        const element = document.querySelector(selector);
        if (element && value !== undefined) {
            const currentValue = parseFloat(element.textContent.replace(/[^\d.]/g, '')) || 0;
            this.animateValue(element, currentValue, value, 800, suffix);
        }
    }

    /**
     * Update trend indicators
     */
    updateTrends(data) {
        // Update score trend
        const scoreTrend = document.querySelector('.score-trend');
        if (scoreTrend && data.score_change !== undefined) {
            this.updateTrendElement(scoreTrend, data.score_change, 'points');
        }

        // Update delivery trend
        const deliveryTrend = document.querySelector('.delivery-trend');
        if (deliveryTrend && data.delivery_change !== undefined) {
            this.updateTrendElement(deliveryTrend, data.delivery_change, '%');
        }

        // Update quality trend
        const qualityTrend = document.querySelector('.quality-trend');
        if (qualityTrend && data.quality_change !== undefined) {
            this.updateTrendElement(qualityTrend, data.quality_change, ' points');
        }
    }

    /**
     * Update trend element
     */
    updateTrendElement(element, change, suffix) {
        const isPositive = change > 0;
        const isNegative = change < 0;

        element.className = 'trend-indicator ' +
            (isPositive ? 'trend-positive' : isNegative ? 'trend-negative' : 'trend-neutral');

        const icon = isPositive ? 'bi-arrow-up' : isNegative ? 'bi-arrow-down' : 'bi-dash';
        const sign = isPositive ? '+' : '';

        element.innerHTML = `
            <i class="bi ${icon}"></i>
            ${sign}${Math.abs(change).toFixed(1)}${suffix}
        `;
    }

    /**
     * Change period and reload data
     */
    changePeriod(period) {
        this.currentPeriod = period;
        this.showLoading();

        // Update URL without page reload
        const url = new URL(window.location);
        url.searchParams.set('period', period);
        window.history.pushState({}, '', url);

        // Update active button
        document.querySelectorAll('.period-button').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-period="${period}"]`).classList.add('active');

        // Reload data
        this.loadPerformanceData();
    }

    /**
     * Show loading state
     */
    showLoading() {
        const cards = document.querySelectorAll('.performance-card');
        cards.forEach(card => {
            card.classList.add('loading');
        });
    }

    /**
     * Hide loading state
     */
    hideLoading() {
        const cards = document.querySelectorAll('.performance-card');
        cards.forEach(card => {
            card.classList.remove('loading');
        });
    }

    /**
     * Export data
     */
    exportData(type) {
        const data = {
            supplier_id: this.supplierId,
            period: this.currentPeriod,
            timestamp: new Date().toISOString()
        };

        if (type === 'pdf') {
            this.exportPDF(data);
        } else if (type === 'csv') {
            this.exportCSV(data);
        } else if (type === 'json') {
            this.exportJSON(data);
        }
    }

    /**
     * Export as PDF
     */
    exportPDF(data) {
        // Implementation would use a PDF library like jsPDF
        console.log('Exporting PDF...', data);
        this.showNotification('PDF export feature coming soon!', 'info');
    }

    /**
     * Export as CSV
     */
    exportCSV(data) {
        // Implementation would generate CSV from performance data
        console.log('Exporting CSV...', data);
        this.showNotification('CSV export feature coming soon!', 'info');
    }

    /**
     * Export as JSON
     */
    exportJSON(data) {
        const jsonData = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonData], { type: 'application/json' });
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = `supplier-performance-${this.supplierId}-${this.currentPeriod}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        this.showNotification('JSON export completed!', 'success');
    }

    /**
     * Refresh data
     */
    async refreshData() {
        const refreshBtn = document.querySelector('[data-action="refresh"]');
        if (refreshBtn) {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="bi bi-arrow-repeat spinning"></i> Refreshing...';
        }

        await this.loadPerformanceData();

        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Refresh';
        }

        this.showNotification('Data refreshed successfully!', 'success');
    }

    /**
     * Resize charts on window resize
     */
    resizeCharts() {
        Object.values(this.charts).forEach(chart => {
            if (chart) {
                chart.resize();
            }
        });
    }

    /**
     * Animate value change
     */
    animateValue(element, start, end, duration, suffix = '') {
        const startTime = performance.now();
        const endTime = startTime + duration;

        const animate = (currentTime) => {
            if (currentTime >= endTime) {
                element.textContent = end.toFixed(1) + suffix;
                return;
            }

            const progress = (currentTime - startTime) / duration;
            const easeProgress = 1 - Math.pow(1 - progress, 3); // Ease out cubic
            const currentValue = start + (end - start) * easeProgress;

            element.textContent = currentValue.toFixed(1) + suffix;

            requestAnimationFrame(animate);
        };

        requestAnimationFrame(animate);
    }

    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    /**
     * Show error message
     */
    showError(message) {
        this.showNotification(message, 'danger');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.supplierPerformance = new SupplierPerformance();
});

// Add CSS for spinning animation
const style = document.createElement('style');
style.textContent = `
    .spinning {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
