// Dashboard JavaScript functionality
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
    
    // Auto-refresh stats every 5 minutes (300000ms)
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            // Only refresh if page is visible to user
            location.reload();
        }
    }, 300000);
    
    // Add current time display in title
    function updateTime() {
        const now = new Date();
        const timeString = now.toLocaleString();
        const originalTitle = document.title.split(' - ')[0];
        document.title = `${originalTitle} - ${timeString}`;
    }
    
    // Update time every second
    setInterval(updateTime, 1000);
    updateTime();
    
    // Add smooth scrolling to anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Add loading states to action buttons
    document.querySelectorAll('.action-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const icon = this.querySelector('i');
            const text = this.textContent.trim();
            
            // Add loading state
            icon.className = 'bi bi-hourglass-split';
            this.style.pointerEvents = 'none';
            this.style.opacity = '0.7';
            
            // Reset after navigation (in case of back button)
            setTimeout(() => {
                this.style.pointerEvents = '';
                this.style.opacity = '';
            }, 2000);
        });
    });
    
    // Add tooltips to stat cards
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            const label = this.querySelector('.stat-label').textContent;
            const value = this.querySelector('.stat-value').textContent;
            this.title = `${label}: ${value}`;
        });
    });
    
    // Handle window resize for responsive behavior
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('.sidebar');
        if (window.innerWidth > 768) {
            sidebar.classList.remove('show');
        }
    });
    
    // Add click outside to close sidebar on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.querySelector('.sidebar');
        const menuBtn = document.querySelector('.mobile-menu-btn');
        
        if (window.innerWidth <= 768 && 
            sidebar.classList.contains('show') && 
            !sidebar.contains(event.target) && 
            !menuBtn?.contains(event.target)) {
            sidebar.classList.remove('show');
        }
    });
    
    // Initialize theme color from CSS custom property
    function initThemeColor() {
        const themeColorMeta = document.querySelector('meta[name="theme-color"]');
        if (themeColorMeta) {
            document.documentElement.style.setProperty('--primary-color', themeColorMeta.content);
        }
    }
    
    initThemeColor();
    
    // Add fade-in animation to stat cards
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    // Apply fade-in to stat cards and data sections
    document.querySelectorAll('.stat-card, .data-section').forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(element);
    });
    
    // Add number formatting for currency values
    function formatCurrency(amount, currency = 'KES') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency === 'KES' ? 'USD' : currency,
            minimumFractionDigits: 2
        }).format(amount).replace('$', currency + ' ');
    }
    
    // Update currency formatting if needed
    document.querySelectorAll('.currency').forEach(element => {
        const text = element.textContent.trim();
        const matches = text.match(/([A-Z]{3})\s*([\d,]+\.?\d*)/);
        if (matches) {
            const currency = matches[1];
            const amount = parseFloat(matches[2].replace(/,/g, ''));
            if (!isNaN(amount)) {
                element.textContent = formatCurrency(amount, currency);
            }
        }
    });
    
    console.log('Dashboard initialized successfully');
});
