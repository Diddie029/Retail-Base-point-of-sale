<?php
// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Get current page for navigation highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Get user information
$username = $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'Guest';
$role_id = $_SESSION['role_id'] ?? 0;
?>

<!-- Top Navigation Bar -->
<div class="topbar">
    <div class="topbar-left">
        <!-- Mobile menu button -->
        <button class="mobile-menu-btn" id="toggleSidebar">
            <i class="bi bi-list"></i>
        </button>
        
        <!-- Brand/Logo -->
        <a class="navbar-brand" href="../index.php" style="text-decoration: none; color: #374151; font-weight: 600; font-size: 1.25rem;">
            <i class="bi bi-shop me-2"></i>
            POS System
        </a>
    </div>
    
    <div class="topbar-right">
        <!-- Quick Actions -->
        <div class="d-none d-md-flex align-items-center gap-3">
            <a href="../pos/pos.php" title="Point of Sale" style="text-decoration: none; color: #6b7280; padding: 0.5rem; border-radius: 0.375rem; transition: all 0.3s ease;">
                <i class="bi bi-calculator"></i>
                <span class="ms-1">POS</span>
            </a>
            <a href="../products/products.php" title="Products" style="text-decoration: none; color: #6b7280; padding: 0.5rem; border-radius: 0.375rem; transition: all 0.3s ease;">
                <i class="bi bi-box"></i>
                <span class="ms-1">Products</span>
            </a>
            <a href="../reports/index.php" title="Reports" style="text-decoration: none; color: #6b7280; padding: 0.5rem; border-radius: 0.375rem; transition: all 0.3s ease;">
                <i class="bi bi-graph-up"></i>
                <span class="ms-1">Reports</span>
            </a>
        </div>
        
        <!-- Notifications -->
        <div class="notifications">
            <i class="bi bi-bell"></i>
            <span class="notification-badge">0</span>
        </div>
        
        <!-- User Menu -->
        <div class="user-dropdown" id="userDropdown">
            <div class="user-avatar">
                <?php echo strtoupper(substr($username, 0, 1)); ?>
            </div>
            <div class="user-info d-none d-md-block">
                <div class="user-name"><?php echo htmlspecialchars($username); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
            <i class="bi bi-chevron-down"></i>
            
            <!-- Dropdown Menu -->
            <div class="dropdown-menu" id="userDropdownMenu">
                <a href="../admin/settings/adminsetting.php" class="dropdown-item">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
                <a href="../auth/logout.php" class="dropdown-item">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar functionality
    const toggleSidebarBtn = document.getElementById('toggleSidebar');
    if (toggleSidebarBtn) {
        toggleSidebarBtn.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('show');
            }
        });
    }
    
    // User dropdown functionality
    const userDropdown = document.getElementById('userDropdown');
    const userDropdownMenu = document.getElementById('userDropdownMenu');
    
    if (userDropdown && userDropdownMenu) {
        userDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdownMenu.classList.toggle('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userDropdown.contains(e.target)) {
                userDropdownMenu.classList.remove('show');
            }
        });
    }
});
</script>