<?php
// Dynamic role-based navigation logic using database-driven menu access
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get menu access settings for this role from database
$showSections = [];
$prioritySections = [];

// Check if user is admin - admins get full access to everything
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('view_all_menus', $permissions) ||
    hasPermission('manage_roles', $permissions) ||
    hasPermission('manage_users', $permissions) ||
    hasPermission('view_reports', $permissions)
);


if ($isAdmin) {
    // Admin gets full access to all menu sections
    $stmt = $conn->prepare("
        SELECT section_key, section_name, section_icon, section_description
        FROM menu_sections 
        WHERE is_active = 1
        ORDER BY sort_order, section_name
    ");
    $stmt->execute();
    $menuAccess = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($menuAccess as $access) {
        $sectionKey = $access['section_key'];
        $showSections[$sectionKey] = true; // Admin sees everything
        $prioritySections[] = $sectionKey; // All sections are priority for admin
    }
    
        // Ensure critical sections are always visible for admin (hardcoded fallback)
        $showSections['dashboard'] = true;
        $showSections['pos'] = true;
        $showSections['quotations'] = true;
        $showSections['reports'] = true;

    if (!in_array('dashboard', $prioritySections)) {
        $prioritySections[] = 'dashboard';
    }
    if (!in_array('pos', $prioritySections)) {
        $prioritySections[] = 'pos';
    }
    if (!in_array('quotations', $prioritySections)) {
        $prioritySections[] = 'quotations';
    }
    if (!in_array('reports', $prioritySections)) {
        $prioritySections[] = 'reports';
    }
} elseif ($role_id) {
    $stmt = $conn->prepare("
        SELECT ms.section_key, rma.is_visible, rma.is_priority, ms.section_name, ms.section_icon, ms.section_description
        FROM menu_sections ms
        LEFT JOIN role_menu_access rma ON ms.id = rma.menu_section_id AND rma.role_id = :role_id
        WHERE ms.is_active = 1
        ORDER BY ms.sort_order, ms.section_name
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $menuAccess = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Require BOTH: role_menu_access visibility AND appropriate permission(s)
    $requires = [
        'dashboard' => function($perms){
            return hasPermission('view_dashboard', $perms) || hasPermission('view_reports', $perms) || hasPermission('view_analytics', $perms);
        },
        'customer_crm' => function($perms){
            return hasPermission('view_customers', $perms) || hasPermission('manage_customers', $perms) || hasPermission('manage_loyalty', $perms);
        },
        'inventory' => function($perms){
            return hasPermission('manage_inventory', $perms) || hasPermission('manage_categories', $perms) || hasPermission('manage_product_brands', $perms) || hasPermission('manage_product_suppliers', $perms);
        },
        'expiry' => function($perms){
            return hasPermission('view_expiry_alerts', $perms) || hasPermission('manage_expiry_tracker', $perms);
        },
        'bom' => function($perms){
            return hasPermission('view_boms', $perms) || hasPermission('create_boms', $perms) || hasPermission('edit_boms', $perms) || hasPermission('delete_boms', $perms);
        },
        'finance' => function($perms){
            return hasPermission('view_finance', $perms);
        },
        'expenses' => function($perms){
            return hasPermission('view_expense_reports', $perms) || hasPermission('create_expenses', $perms);
        },
        'analytics' => function($perms){
            return hasPermission('view_analytics', $perms);
        },
        'pos_management' => function($perms){
            return hasPermission('manage_sales', $perms) || hasPermission('view_sales', $perms) || hasPermission('manage_tills', $perms) || hasPermission('cash_drop', $perms) || hasPermission('process_sales', $perms);
        },
        'reports' => function($perms){
            return hasPermission('view_reports', $perms) || hasPermission('view_sales', $perms) || hasPermission('view_finance', $perms) || hasPermission('view_analytics', $perms) || hasPermission('manage_sales', $perms);
        },
        'shelf_labels' => function($perms){
            return hasPermission('manage_shelf_labels', $perms) || hasPermission('print_labels', $perms);
        },
        'admin' => function($perms){
            return hasPermission('manage_users', $perms) || hasPermission('manage_settings', $perms) || hasPermission('manage_backup', $perms) || hasPermission('view_security_logs', $perms);
        },
        'quotations' => function($perms){
            return hasPermission('process_sales', $perms) || hasPermission('manage_quotations', $perms) || hasPermission('create_quotations', $perms) || hasPermission('view_quotations', $perms);
        }
    ];

    foreach ($menuAccess as $access) {
        $sectionKey = $access['section_key'];
        $isVisible = (int)($access['is_visible'] ?? 0);
        $isPriority = (int)($access['is_priority'] ?? 0);

        $permOk = true;
        if (isset($requires[$sectionKey]) && is_callable($requires[$sectionKey])) {
            $permOk = $requires[$sectionKey]($permissions);
        }
        $showSections[$sectionKey] = (bool)($isVisible && $permOk);
        if ($isPriority && $showSections[$sectionKey]) {
            $prioritySections[] = $sectionKey;
        }
    }

    // Special sections not in menu_sections: currently only POS
    $showSections['pos'] = hasPermission('process_sales', $permissions);

} else {
    // Fallback for users without roles - show basic sections based on permissions
    // Check if user has admin-like permissions and give them full access
    $hasAdminPermissions = (
        hasPermission('manage_roles', $permissions) || 
        hasPermission('manage_users', $permissions) || 
        hasPermission('manage_settings', $permissions) ||
        hasPermission('view_all_menus', $permissions)
    );
    
    if ($hasAdminPermissions) {
        // User has admin permissions - give them access to everything
        $stmt = $conn->prepare("
            SELECT section_key, section_name, section_icon, section_description
            FROM menu_sections 
            WHERE is_active = 1
            ORDER BY sort_order, section_name
        ");
        $stmt->execute();
        $menuAccess = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($menuAccess as $access) {
            $sectionKey = $access['section_key'];
            $showSections[$sectionKey] = true; // Admin sees everything
            $prioritySections[] = $sectionKey; // All sections are priority for admin
        }
        
        // Ensure critical sections are always visible for admin users
        $showSections['dashboard'] = true;
        $showSections['pos'] = true;
        $showSections['quotations'] = true;
        $showSections['reports'] = true;
        
        if (!in_array('dashboard', $prioritySections)) {
            $prioritySections[] = 'dashboard';
        }
        if (!in_array('pos', $prioritySections)) {
            $prioritySections[] = 'pos';
        }
        if (!in_array('quotations', $prioritySections)) {
            $prioritySections[] = 'quotations';
        }
        if (!in_array('reports', $prioritySections)) {
            $prioritySections[] = 'reports';
        }
    } else {
        // Regular user - show sections based on permissions
        $showSections = [
            'dashboard' => hasPermission('view_dashboard', $permissions) || hasPermission('view_reports', $permissions) || hasPermission('view_analytics', $permissions),
            'pos' => hasPermission('process_sales', $permissions),
            'quotations' => hasPermission('process_sales', $permissions) || hasPermission('manage_quotations', $permissions) || hasPermission('create_quotations', $permissions) || hasPermission('view_quotations', $permissions),
            'customer_crm' => hasPermission('view_customers', $permissions) || hasPermission('manage_customers', $permissions) || hasPermission('manage_loyalty', $permissions),
            'inventory' => hasPermission('manage_inventory', $permissions) || hasPermission('manage_categories', $permissions) || hasPermission('manage_product_brands', $permissions) || hasPermission('manage_product_suppliers', $permissions),
            'expiry' => hasPermission('view_expiry_alerts', $permissions) || hasPermission('manage_expiry_tracker', $permissions),
            'bom' => hasPermission('create_boms', $permissions) || hasPermission('edit_boms', $permissions) || hasPermission('delete_boms', $permissions) || hasPermission('view_boms', $permissions) || hasPermission('view_bom_components', $permissions) || hasPermission('view_bom_costing', $permissions),
            'finance' => hasPermission('view_finance', $permissions),
            'expenses' => hasPermission('view_expense_reports', $permissions) || hasPermission('create_expenses', $permissions),
            'analytics' => hasPermission('view_analytics', $permissions),
            'pos_management' => hasPermission('manage_sales', $permissions) || hasPermission('view_sales', $permissions) || hasPermission('manage_tills', $permissions) || hasPermission('cash_drop', $permissions) || hasPermission('process_sales', $permissions),
            'sales' => hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions),
            'reports' => hasPermission('view_reports', $permissions) || hasPermission('view_analytics', $permissions) || hasPermission('view_sales', $permissions) || hasPermission('manage_sales', $permissions) || hasPermission('view_finance', $permissions),
            'shelf_labels' => hasPermission('manage_shelf_labels', $permissions) || hasPermission('print_labels', $permissions),
            'admin' => hasPermission('manage_users', $permissions) || hasPermission('manage_settings', $permissions) || hasPermission('manage_backup', $permissions) || hasPermission('view_security_logs', $permissions)
        ];
        $prioritySections = array_keys(array_filter($showSections));
    }
}

// Count visible sections for role-based styling
$visibleSections = array_filter($showSections);
$totalVisibleSections = count($visibleSections);

// Emergency fallback: If admin has no visible sections, give them everything
if ($totalVisibleSections == 0 && (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('manage_roles', $permissions) ||
    hasPermission('manage_users', $permissions) ||
    hasPermission('view_reports', $permissions)
)) {
    error_log("Emergency Admin Fallback: Admin user has no visible sections, enabling all sections");
    
    $stmt = $conn->prepare("
        SELECT section_key, section_name, section_icon, section_description
        FROM menu_sections 
        WHERE is_active = 1
        ORDER BY sort_order, section_name
    ");
    $stmt->execute();
    $menuAccess = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($menuAccess as $access) {
        $sectionKey = $access['section_key'];
        $showSections[$sectionKey] = true; // Admin sees everything
        $prioritySections[] = $sectionKey; // All sections are priority for admin
    }
    
    // Ensure critical sections are always visible for admin (emergency fallback)
    $showSections['dashboard'] = true;
    $showSections['pos'] = true;
    $showSections['quotations'] = true;
    $showSections['reports'] = true;

    if (!in_array('dashboard', $prioritySections)) {
        $prioritySections[] = 'dashboard';
    }
    if (!in_array('pos', $prioritySections)) {
        $prioritySections[] = 'pos';
    }
    if (!in_array('quotations', $prioritySections)) {
        $prioritySections[] = 'quotations';
    }
    if (!in_array('reports', $prioritySections)) {
        $prioritySections[] = 'reports';
    }

    // Recalculate visible sections
    $visibleSections = array_filter($showSections);
    $totalVisibleSections = count($visibleSections);
}
?>

<!-- Sidebar Navigation -->
<nav class="sidebar <?php echo $totalVisibleSections <= 3 ? 'simplified' : ''; ?>" style="background-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;">
    <div class="sidebar-header">
        <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
        <small>Point of Sale System</small>
        <div class="role-badge">
            <i class="bi bi-person-badge"></i>
            <?php echo htmlspecialchars($role_name); ?>
        </div>
    </div>
    <div class="sidebar-nav">
        <!-- Dashboard - Always visible for admin -->
        <?php if (isset($showSections['dashboard']) && $showSections['dashboard']): ?>
        <div class="nav-item">
            <a href="<?php echo url('dashboard/dashboard.php'); ?>" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/dashboard.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/dashboard.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>
        </div>
        <?php endif; ?>

        <!-- POS - Always visible for admin -->
        <?php if (isset($showSections['pos']) && $showSections['pos']): ?>
        <div class="nav-item">
            <a href="<?php echo url('pos/sale.php'); ?>" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/pos/') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/pos/') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-cart-plus"></i>
                POS
            </a>
        </div>
        <?php endif; ?>


        <!-- Dynamic Navigation Sections -->
        <?php include 'dynamic_navigation.php'; ?>

        <!-- My Profile - Always visible -->
        <div class="nav-item">
            <a href="<?php echo url('dashboard/users/profile.php'); ?>" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/profile.php') !== false ? 'active' : ''; ?>" style="background-color: <?php echo strpos($_SERVER['REQUEST_URI'], '/dashboard/users/profile.php') !== false ? ($settings['theme_color'] ?? '#6366f1') : 'transparent'; ?>">
                <i class="bi bi-person-gear"></i>
                My Profile
            </a>
        </div>

        <!-- Logout - Disabled if POS is active -->
        <div class="nav-item mt-auto">
            <?php
            // Check if user is POS authenticated or has an active till
            $pos_authenticated = isset($_SESSION['pos_authenticated']) && $_SESSION['pos_authenticated'] === true;
            $selected_till_id = $_SESSION['selected_till_id'] ?? null;
            $till_closed = false;
            $has_active_till = false;
            
            // Check if till is closed
            if ($selected_till_id && isset($conn)) {
                try {
                    $stmt = $conn->prepare("SELECT is_closed FROM register_tills WHERE id = ?");
                    $stmt->execute([$selected_till_id]);
                    $till = $stmt->fetch(PDO::FETCH_ASSOC);
                    $till_closed = $till ? $till['is_closed'] == 1 : false;
                    $has_active_till = true; // User has a till assigned
                } catch (PDOException $e) {
                    error_log("Error checking till status: " . $e->getMessage());
                }
            }
            
            // Logout is disabled if:
            // 1. User is POS authenticated, OR
            // 2. User has a till assigned but it's not closed
            $logout_disabled = $pos_authenticated || ($has_active_till && !$till_closed);
            $logout_class = $logout_disabled ? 'nav-link text-muted disabled' : 'nav-link text-danger';
            
            if ($pos_authenticated) {
                $logout_title = 'Cannot logout while signed in to POS. Sign out from POS first.';
            } elseif ($has_active_till && !$till_closed) {
                $logout_title = 'Cannot logout while till is open. You must close your till first.';
            } else {
                $logout_title = 'Logout from system';
            }
            ?>
            <a href="<?php echo $logout_disabled ? '#' : '../auth/logout.php'; ?>" 
               class="<?php echo $logout_class; ?>" 
               title="<?php echo $logout_title; ?>"
               <?php echo $logout_disabled ? 'onclick="return false;"' : ''; ?>>
                <i class="bi bi-box-arrow-right"></i>
                Logout
                <?php if ($logout_disabled): ?>
                    <small class="d-block text-muted" style="font-size: 0.7rem;">
                        <?php if ($pos_authenticated): ?>
                            <i class="bi bi-lock"></i> POS Signed In
                        <?php else: ?>
                            <i class="bi bi-lock"></i> Till Open
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
            </a>
        </div>
    </div>
</nav>

<style>
:root {
    --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
    --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
}

/* Sidebar Layout */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sidebar-width, 280px);
    height: 100vh;
    background-color: var(--sidebar-color);
    color: white;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

/* Main Content Area */
.main-content {
    margin-left: var(--sidebar-width, 280px);
    min-height: 100vh;
    background-color: #f8f9fa;
    transition: margin-left 0.3s ease;
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

/* Collapsible Sections */
.nav-section {
    margin-bottom: 0.5rem;
}

.nav-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    border-radius: 8px;
    margin: 0 0.5rem;
}

.nav-section-header:hover {
    background-color: rgba(255,255,255,0.1);
}

.nav-section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: rgba(255,255,255,0.9);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
}

.nav-toggle {
    font-size: 0.875rem;
    transition: transform 0.3s ease;
    color: rgba(255,255,255,0.7);
}

.nav-toggle.rotated {
    transform: rotate(180deg);
}

.nav-section-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out;
    background-color: rgba(0,0,0,0.1);
    border-radius: 8px;
    margin: 0 0.5rem;
}

.nav-section-content.expanded {
    max-height: 1000px;
    transition: max-height 0.3s ease-in;
}

.nav-section-content .nav-item {
    margin: 0.125rem 0;
}

.nav-section-content .nav-link {
    padding: 0.625rem 1rem 0.625rem 2rem;
    font-size: 0.875rem;
    margin: 0;
}

.nav-item.mt-auto {
    margin-top: auto;
}

/* Role-based styling */
.role-badge {
    background: rgba(255,255,255,0.1);
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    margin-top: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.sidebar.simplified .nav-section {
    margin-bottom: 0.25rem;
}

.sidebar.simplified .nav-section-header {
    padding: 0.5rem 1rem;
}

.priority-section {
    border-left: 3px solid var(--primary-color);
    background: rgba(99, 102, 241, 0.05);
}

.priority-badge {
    background: var(--primary-color);
    color: white;
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 8px;
    margin-left: 0.5rem;
    font-weight: 500;
}

.secondary-section {
    opacity: 0.9;
}

.secondary-section .nav-section-header {
    color: rgba(255,255,255,0.7);
}

.secondary-section .nav-section-title {
    color: rgba(255,255,255,0.7);
}

/* Auto-expand priority sections */
.priority-section .nav-section-content {
    max-height: 1000px;
}

.priority-section .nav-toggle {
    transform: rotate(180deg);
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
    
    .main-content {
        margin-left: 0;
    }
}
</style>

<script>
// Collapsible Navigation Functionality
function toggleSection(sectionId) {
    const content = document.getElementById(sectionId + '-content');
    const toggle = document.getElementById(sectionId + '-toggle');
    
    if (content.classList.contains('expanded')) {
        content.classList.remove('expanded');
        toggle.classList.remove('rotated');
    } else {
        content.classList.add('expanded');
        toggle.classList.add('rotated');
    }
}

// Auto-expand sections that contain active links and priority sections
document.addEventListener('DOMContentLoaded', function() {
    // Auto-expand priority sections
    const prioritySections = document.querySelectorAll('.priority-section');
    prioritySections.forEach(section => {
        const content = section.querySelector('.nav-section-content');
        const toggle = section.querySelector('.nav-toggle');
        
        if (content && toggle) {
            content.classList.add('expanded');
            toggle.classList.add('rotated');
        }
    });
    
    // Auto-expand sections that contain active links
    const activeLinks = document.querySelectorAll('.nav-link.active');
    activeLinks.forEach(link => {
        const section = link.closest('.nav-section-content');
        if (section) {
            const sectionId = section.id.replace('-content', '');
            const toggle = document.getElementById(sectionId + '-toggle');
            
            section.classList.add('expanded');
            if (toggle) {
                toggle.classList.add('rotated');
            }
        }
    });
    
    // Add role-based navigation hints
    const roleBadge = document.querySelector('.role-badge');
    if (roleBadge) {
        roleBadge.title = 'Your current role determines which sections are visible and prioritized';
    }
});
</script>