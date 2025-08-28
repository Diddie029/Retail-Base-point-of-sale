<?php
// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Get current page for navigation highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Get user permissions if available
$role_id = $_SESSION['role_id'] ?? 0;
$permissions = [];
if ($role_id && isset($conn)) {
    $permissions = getUserPermissions($conn, $role_id);
}

// Helper function to check if menu item is active
function isActiveMenu($page, $directory = '') {
    global $current_page, $current_dir;
    if ($directory) {
        return $current_dir === $directory;
    }
    return $current_page === $page;
}
?>

<!-- Sidebar Navigation -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h5><i class="bi bi-shop me-2"></i>POS System</h5>
        <button class="btn btn-sm btn-outline-light" id="closeSidebarBtn">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    
    <div class="sidebar-content">
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('index.php') ? 'active' : ''; ?>" href="../index.php">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>
            
            <!-- Point of Sale -->
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('', 'pos') ? 'active' : ''; ?>" href="../pos/pos.php">
                    <i class="bi bi-calculator me-2"></i>
                    Point of Sale
                </a>
            </li>
            
            <!-- Products -->
            <?php if (empty($permissions) || hasPermission('manage_products', $permissions)): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('', 'products') ? 'active' : ''; ?>" href="../products/products.php">
                    <i class="bi bi-box me-2"></i>
                    Products
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Categories -->
            <?php if (empty($permissions) || hasPermission('manage_products', $permissions)): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('', 'categories') ? 'active' : ''; ?>" href="../categories/categories.php">
                    <i class="bi bi-tags me-2"></i>
                    Categories
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Sales -->
            <?php if (empty($permissions) || hasPermission('manage_sales', $permissions)): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('', 'reports') ? 'active' : ''; ?>" href="../reports/index.php">
                    <i class="bi bi-graph-up me-2"></i>
                    Reports
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Divider -->
            <li class="nav-divider"></li>
            
            <!-- Administration -->
            <?php if (empty($permissions) || hasPermission('manage_users', $permissions) || hasPermission('manage_settings', $permissions)): ?>
            <li class="nav-header">Administration</li>
            
            <?php if (empty($permissions) || hasPermission('manage_users', $permissions)): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo isActiveMenu('', 'admin') ? 'active' : ''; ?>" href="../admin/users.php">
                    <i class="bi bi-people me-2"></i>
                    Users
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (empty($permissions) || hasPermission('manage_settings', $permissions)): ?>
            <li class="nav-item">
                <a class="nav-link" href="../admin/settings/adminsetting.php">
                    <i class="bi bi-gear me-2"></i>
                    Settings
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- Sidebar Backdrop for Mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<style>
.sidebar {
    position: fixed;
    top: 60px; /* Below topbar */
    left: -280px;
    width: 280px;
    height: calc(100vh - 60px);
    background: #1e293b;
    color: white;
    transition: left 0.3s ease;
    z-index: 1025;
    overflow-y: auto;
}

.sidebar.show {
    left: 0;
}

.sidebar-header {
    padding: 1.5rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: between;
    align-items: center;
}

.sidebar-header h5 {
    margin: 0;
    color: white;
    font-weight: 600;
}

.sidebar-header #closeSidebarBtn {
    margin-left: auto;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.sidebar-content {
    padding: 1rem 0;
}

.sidebar .nav-link {
    color: rgba(255, 255, 255, 0.8);
    padding: 0.75rem 1.5rem;
    border: none;
    transition: all 0.3s ease;
    border-radius: 0;
}

.sidebar .nav-link:hover {
    color: white;
    background: rgba(255, 255, 255, 0.1);
}

.sidebar .nav-link.active {
    color: white;
    background: #3b82f6;
    border-left: 4px solid #60a5fa;
}

.sidebar .nav-link i {
    width: 20px;
}

.nav-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.1);
    margin: 1rem 0;
}

.nav-header {
    padding: 0.5rem 1.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.6);
    letter-spacing: 0.5px;
}

.sidebar-backdrop {
    position: fixed;
    top: 60px;
    left: 0;
    width: 100%;
    height: calc(100vh - 60px);
    background: rgba(0, 0, 0, 0.5);
    z-index: 1020;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.sidebar-backdrop.show {
    opacity: 1;
    visibility: visible;
}

/* Main content adjustment */
@media (min-width: 992px) {
    .main-content {
        margin-left: 0;
        transition: margin-left 0.3s ease;
    }
}

@media (max-width: 991px) {
    .sidebar {
        width: 100%;
        left: -100%;
    }
    
    .sidebar.show {
        left: 0;
    }
}

/* Scrollbar styling for sidebar */
.sidebar::-webkit-scrollbar {
    width: 4px;
}

.sidebar::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
}

.sidebar::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.5);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close sidebar functionality
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const sidebar = document.getElementById('sidebar');
    
    function closeSidebar() {
        if (sidebar) {
            sidebar.classList.remove('show');
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.classList.remove('show');
        }
    }
    
    if (closeSidebarBtn) {
        closeSidebarBtn.addEventListener('click', closeSidebar);
    }
    
    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeSidebar);
    }
    
    // Close sidebar on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });
});
</script>