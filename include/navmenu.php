<!-- Sidebar Navigation -->
<nav class="sidebar" style="background-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;">
    <div class="sidebar-header">
        <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
        <small>Point of Sale System</small>
    </div>
    <div class="sidebar-nav">
        <div class="nav-item">
            <a href="/dashboard/dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF'], '.php') === 'dashboard' ? 'active' : ''; ?>" style="background-color: <?php echo basename($_SERVER['PHP_SELF'], '.php') === 'dashboard' ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>
        </div>

        <?php if (hasPermission('process_sales', $permissions)): ?>
        <div class="nav-item">
            <a href="/pos/sale.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/pos/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/pos/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-cart-plus"></i>
                Point of Sale
            </a>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('manage_inventory', $permissions) || hasPermission('manage_categories', $permissions) || hasPermission('manage_product_brands', $permissions) || hasPermission('manage_product_suppliers', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-box me-2"></i>
                Products
            </div>
            <div class="nav-item">
                <a href="/products/products.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/products/products.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/products/products.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-list"></i>
                    All Products
                </a>
            </div>
            <div class="nav-item">
                <a href="/categories/categories.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/categories/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/categories/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <div class="nav-item">
                <a href="/product_families/families.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/product_families/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/product_families/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-diagram-3"></i>
                    Product Families
                </a>
            </div>
            <div class="nav-item">
                <a href="/brands/brands.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/brands/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/brands/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-star"></i>
                    Brands
                </a>
            </div>
            <div class="nav-item">
                <a href="/suppliers/suppliers.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/suppliers/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/suppliers/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-truck"></i>
                    Suppliers
                </a>
            </div>
            <div class="nav-item">
                <a href="/inventory/inventory.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/inventory/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/inventory/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-boxes"></i>
                    Inventory
                </a>
            </div>
            <div class="nav-item">
                <a href="/products/bulk_operations.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/products/bulk_operations.php') !== false || strpos($_SERVER['REQUEST_URI'], '/products/bulk_') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/products/bulk_operations.php') !== false || strpos($_SERVER['REQUEST_URI'], '/products/bulk_') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-lightning-charge"></i>
                    Bulk Operations
                </a>
            </div>
            <div class="nav-item">
                <a href="/shelf_label/shelf_labels.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/shelf_label/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/shelf_label/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-tags"></i>
                    Shelf Labels
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('view_expiry_alerts', $permissions) || hasPermission('manage_expiry_tracker', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-clock-history me-2"></i>
                Expiry Management
            </div>
            <div class="nav-item">
                <a href="/expiry_tracker/expiry_tracker.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expiry_tracker/expiry_tracker.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expiry_tracker/expiry_tracker.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-clock-history"></i>
                    Expiry Tracker
                </a>
            </div>
            <?php if (hasPermission('manage_expiry_tracker', $permissions)): ?>
            <div class="nav-item">
                <a href="/expiry_tracker/add_expiry_date.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expiry_tracker/add_expiry_date.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expiry_tracker/add_expiry_date.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-plus-circle"></i>
                    Add Expiry Date
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('create_boms', $permissions) || hasPermission('edit_boms', $permissions) || hasPermission('delete_boms', $permissions) || hasPermission('view_boms', $permissions) || hasPermission('view_bom_components', $permissions) || hasPermission('view_bom_costing', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-file-earmark-text me-2"></i>
                Bill of Materials
            </div>
            <div class="nav-item">
                <a href="/bom/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/') !== false && !strpos($_SERVER['REQUEST_URI'], '/bom/production.php') && !strpos($_SERVER['REQUEST_URI'], '/bom/reports.php') && !strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/') !== false && !strpos($_SERVER['REQUEST_URI'], '/bom/production.php') && !strpos($_SERVER['REQUEST_URI'], '/bom/reports.php') && !strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-list-ul"></i>
                    BOM Management
                </a>
            </div>
            <div class="nav-item">
                <a href="/bom/auto_bom_index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_index.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_index.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-gear-fill"></i>
                    Auto BOM
                </a>
            </div>
            <div class="nav-item">
                <a href="/bom/auto_bom_products.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_products.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/auto_bom_products.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-list-ul"></i>
                    Auto BOM Products
                </a>
            </div>
            <?php if (hasPermission('create_boms', $permissions)): ?>
            <div class="nav-item">
                <a href="/bom/add.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/add.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/add.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-plus-circle"></i>
                    Create BOM
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('create_production_orders', $permissions) || hasPermission('manage_production_orders', $permissions) || hasPermission('approve_production_orders', $permissions) || hasPermission('view_production_orders', $permissions)): ?>
            <div class="nav-item">
                <a href="/bom/production.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/production.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/production.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-gear"></i>
                    Production Orders
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('view_production_reports', $permissions) || hasPermission('view_bom_reports', $permissions) || hasPermission('analyze_bom_performance', $permissions)): ?>
            <div class="nav-item">
                <a href="/bom/reports.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/reports.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/reports.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-graph-up"></i>
                    BOM Reports
                </a>
            </div>
            <?php endif; ?>
            <div class="nav-item">
                <a href="/bom/demo_multilevel.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/demo_multilevel.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/bom/demo_multilevel.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-diagram-3"></i>
                    Multi-Level Demo
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('manage_sales', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-receipt me-2"></i>
                Sales
            </div>
            <div class="nav-item">
                <a href="/sales/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/sales/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/sales/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-receipt"></i>
                    Sales History
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('view_customers', $permissions)): ?>
        <div class="nav-item">
            <a href="/customers/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/customers/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/customers/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-people"></i>
                Customers
            </a>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('view_analytics', $permissions)): ?>
        <div class="nav-item">
            <a href="/analytics/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/analytics/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/analytics/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-graph-up"></i>
                Analytics
            </a>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('view_expense_reports', $permissions) || hasPermission('create_expenses', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-cash-stack me-2"></i>
                Expense Management
            </div>
            <div class="nav-item">
                <a href="/expenses/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/') !== false && !strpos($_SERVER['REQUEST_URI'], '/expenses/categories.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/departments.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/vendors.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/reports.php') ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/') !== false && !strpos($_SERVER['REQUEST_URI'], '/expenses/categories.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/departments.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/vendors.php') && !strpos($_SERVER['REQUEST_URI'], '/expenses/reports.php') ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-list-ul"></i>
                    All Expenses
                </a>
            </div>
            <?php if (hasPermission('create_expenses', $permissions)): ?>
            <div class="nav-item">
                <a href="/expenses/add.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/add.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/add.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-plus-circle"></i>
                    Add Expense
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('manage_expense_categories', $permissions)): ?>
            <div class="nav-item">
                <a href="/expenses/categories.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/categories.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/categories.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('manage_expense_departments', $permissions)): ?>
            <div class="nav-item">
                <a href="/expenses/departments.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/departments.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/departments.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-building"></i>
                    Departments
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('manage_expense_vendors', $permissions)): ?>
            <div class="nav-item">
                <a href="/expenses/vendors.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/vendors.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/vendors.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-shop"></i>
                    Vendors
                </a>
            </div>
            <?php endif; ?>
            <?php if (hasPermission('view_expense_reports', $permissions)): ?>
            <div class="nav-item">
                <a href="/expenses/reports.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/reports.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/expenses/reports.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-graph-up"></i>
                    Reports
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (hasPermission('manage_users', $permissions) || hasPermission('manage_settings', $permissions) || hasPermission('manage_backup', $permissions)): ?>
        <div class="nav-section">
            <div class="nav-section-title">
                <i class="bi bi-shield me-2"></i>
                Administration
            </div>

            <?php if (hasPermission('manage_users', $permissions)): ?>
            <div class="nav-item">
                <a href="/dashboard/users/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-person-gear"></i>
                    User Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_roles', $permissions)): ?>
            <div class="nav-item">
                <a href="/dashboard/roles/index.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/roles/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/roles/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-shield-check"></i>
                    Role Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_backup', $permissions)): ?>
            <div class="nav-item">
                <a href="/admin/backup/manage_backups.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/admin/backup/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/admin/backup/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-server"></i>
                    Backup & Security
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_settings', $permissions)): ?>
            <div class="nav-item">
                <a href="/admin/settings/adminsetting.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/admin/settings/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/admin/settings/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- My Profile - Available to all users -->
        <div class="nav-item">
            <a href="/dashboard/users/profile.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/profile.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/profile.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-person-gear"></i>
                My Profile
            </a>
        </div>

        <div class="nav-item mt-auto">
            <a href="/auth/logout.php" class="nav-link text-danger">
                <i class="bi bi-box-arrow-right"></i>
                Logout
            </a>
        </div>
    </div>
</nav>

<style>
:root {
    --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
    --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
}

.nav-section {
    margin-bottom: 1rem;
}

.nav-section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: rgba(255,255,255,0.8);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.75rem 1rem 0.25rem;
    margin-bottom: 0.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.nav-link.active {
    background-color: var(--primary-color) !important;
    color: white !important;
    border-radius: 8px;
}

.nav-link:hover {
    background-color: rgba(255,255,255,0.1);
    color: white;
}

/* Sidebar Layout */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background-color: var(--sidebar-color);
    color: white;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

.sidebar-header {
    padding: 1.5rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-align: center;
}

.sidebar-header h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.sidebar-header small {
    opacity: 0.8;
    font-size: 0.875rem;
}

.sidebar-nav {
    padding: 1rem 0;
}

.nav-item {
    margin: 0.25rem 0;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: rgba(255,255,255,0.8);
    text-decoration: none;
    transition: all 0.2s;
    border-radius: 8px;
    margin: 0 0.5rem;
}

.nav-link i {
    font-size: 1.1rem;
    width: 1.25rem;
    text-align: center;
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.nav-link:hover {
    background-color: rgba(255,255,255,0.1);
    color: white;
}

.nav-link.active {
    background-color: var(--primary-color) !important;
    color: white !important;
}

.nav-link.active i {
    color: white !important;
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.nav-section {
    margin-bottom: 1rem;
}

.nav-section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: rgba(255,255,255,0.8);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.75rem 1rem 0.25rem;
    margin-bottom: 0.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.nav-item.mt-auto {
    margin-top: auto;
}

/* Responsive Design */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
}
</style>
