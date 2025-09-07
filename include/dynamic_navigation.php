<?php
// Dynamic navigation section generator
// This file generates navigation sections based on database configuration

// Define section content mappings for each section key
$sectionContentMappings = [
    'inventory' => [
        ['/products/products.php', 'bi-list', 'All Products'],
        ['/categories/categories.php', 'bi-tags', 'Categories'],
        ['/product_families/families.php', 'bi-diagram-3', 'Product Families'],
        ['/brands/brands.php', 'bi-star', 'Brands'],
        ['/suppliers/suppliers.php', 'bi-truck', 'Suppliers'],
        ['/inventory/inventory.php', 'bi-boxes', 'Inventory'],
        ['/products/bulk_operations.php', 'bi-lightning-charge', 'Bulk Operations'],
        ['/shelf_label/shelf_labels.php', 'bi-tags', 'Shelf Labels']
    ],
    'expiry' => [
        ['/expiry_tracker/expiry_tracker.php', 'bi-clock-history', 'Expiry Tracker'],
        ['/expiry_tracker/add_expiry_date.php', 'bi-plus-circle', 'Add Expiry Date']
    ],
    'bom' => [
        ['/bom/index.php', 'bi-list-ul', 'BOM Management'],
        ['/bom/auto_bom_index.php', 'bi-gear-fill', 'Auto BOM'],
        ['/bom/auto_bom_products.php', 'bi-list-ul', 'Auto BOM Products'],
        ['/bom/add.php', 'bi-plus-circle', 'Create BOM'],
        ['/bom/production.php', 'bi-gear', 'Production Orders'],
        ['/bom/reports.php', 'bi-graph-up', 'BOM Reports'],
        ['/bom/demo_multilevel.php', 'bi-diagram-3', 'Multi-Level Demo']
    ],
    'finance' => [
        ['/finance/index.php', 'bi-speedometer2', 'Finance Dashboard'],
        ['/finance/budget.php', 'bi-wallet2', 'Budget Management'],
        ['/finance/budget-reports.php', 'bi-graph-up', 'Budget Reports'],
        ['/finance/profit-loss.php', 'bi-graph-down', 'Profit & Loss'],
        ['/finance/balance-sheet.php', 'bi-file-earmark-text', 'Balance Sheet'],
        ['/finance/cash-flow.php', 'bi-arrow-left-right', 'Cash Flow'],
        ['/finance/sales-analytics.php', 'bi-bar-chart', 'Sales Analytics'],
        ['/finance/expense-analysis.php', 'bi-pie-chart', 'Expense Analysis'],
        ['/finance/forecasting.php', 'bi-graph-up-arrow', 'Forecasting'],
        ['/finance/tax-management.php', 'bi-percent', 'Tax Management'],
        ['/finance/tax-reports.php', 'bi-graph-up', 'Tax Reports'],
        ['/finance/reports.php', 'bi-file-earmark-bar-graph', 'All Reports']
    ],
    'expenses' => [
        ['/expenses/index.php', 'bi-list-ul', 'All Expenses'],
        ['/expenses/add.php', 'bi-plus-circle', 'Add Expense'],
        ['/expenses/categories.php', 'bi-tags', 'Categories'],
        ['/expenses/departments.php', 'bi-building', 'Departments'],
        ['/expenses/vendors.php', 'bi-shop', 'Vendors'],
        ['/expenses/reports.php', 'bi-graph-up', 'Reports']
    ],
    'admin' => [
        ['/dashboard/users/index.php', 'bi-person-gear', 'User Management'],
        ['/dashboard/roles/index.php', 'bi-shield-check', 'Role Management'],
        ['/admin/security_logs.php', 'bi-shield-exclamation', 'Security Logs'],
        ['/admin/backup/manage_backups.php', 'bi-server', 'Backup & Security'],
        ['/admin/settings/adminsetting.php', 'bi-gear', 'Settings']
    ]
];

// Function to generate a navigation section
function generateNavSection($sectionKey, $sectionName, $sectionIcon, $isVisible, $isPriority, $sectionItems = []) {
    global $settings;
    
    if (!$isVisible) return '';
    
    $priorityClass = $isPriority ? 'priority-section' : 'secondary-section';
    $priorityBadge = $isPriority ? '<span class="priority-badge">Primary</span>' : '';
    
    $html = '<div class="nav-section collapsible ' . $priorityClass . '">';
    $html .= '<div class="nav-section-header" onclick="toggleSection(\'' . $sectionKey . '\')">';
    $html .= '<div class="nav-section-title">';
    $html .= '<i class="bi ' . htmlspecialchars($sectionIcon) . ' me-2"></i>';
    $html .= htmlspecialchars($sectionName);
    $html .= $priorityBadge;
    $html .= '</div>';
    $html .= '<i class="bi bi-chevron-down nav-toggle" id="' . $sectionKey . '-toggle"></i>';
    $html .= '</div>';
    $html .= '<div class="nav-section-content" id="' . $sectionKey . '-content">';
    
    // Add section content
    foreach ($sectionItems as $item) {
        $url = $item[0];
        $icon = $item[1];
        $label = $item[2];
        
        $isActive = strpos($_SERVER['REQUEST_URI'], $url) !== false ? 'active' : '';
        $bgColor = $isActive ? ($settings['theme_color'] ?? '#6366f1') : 'transparent';
        
        $html .= '<div class="nav-item">';
        $html .= '<a href="/pointofsale' . $url . '" class="nav-link ' . $isActive . '" style="background-color: ' . $bgColor . '">';
        $html .= '<i class="bi ' . $icon . '"></i>';
        $html .= $label;
        $html .= '</a>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

// Generate dynamic sections based on database
if (isset($menuAccess) && !empty($menuAccess)) {
    foreach ($menuAccess as $access) {
        $sectionKey = $access['section_key'];
        $isVisible = $access['is_visible'] ?? 1; // Default to visible for admin
        $isPriority = $access['is_priority'] ?? 0;
        $sectionName = $access['section_name'];
        $sectionIcon = $access['section_icon'];
        
        $sectionItems = isset($sectionContentMappings[$sectionKey]) ? $sectionContentMappings[$sectionKey] : [];
        
        echo generateNavSection($sectionKey, $sectionName, $sectionIcon, $isVisible, $isPriority, $sectionItems);
    }
} else {
    // Fallback for users without roles - use hardcoded sections
    $sectionConfig = [
        'inventory' => ['Inventory', 'bi-boxes', $sectionContentMappings['inventory'] ?? []],
        'expiry' => ['Expiry Management', 'bi-clock-history', $sectionContentMappings['expiry'] ?? []],
        'bom' => ['Bill of Materials', 'bi-file-earmark-text', $sectionContentMappings['bom'] ?? []],
        'finance' => ['Finance', 'bi-calculator', $sectionContentMappings['finance'] ?? []],
        'expenses' => ['Expense Management', 'bi-cash-stack', $sectionContentMappings['expenses'] ?? []],
        'admin' => ['Administration', 'bi-shield', $sectionContentMappings['admin'] ?? []]
    ];
    
    foreach ($showSections as $sectionKey => $isVisible) {
        if ($isVisible && isset($sectionConfig[$sectionKey])) {
            $config = $sectionConfig[$sectionKey];
            $sectionName = $config[0];
            $sectionIcon = $config[1];
            $sectionItems = $config[2];
            
            $isPriority = in_array($sectionKey, $prioritySections);
            
            echo generateNavSection($sectionKey, $sectionName, $sectionIcon, $isVisible, $isPriority, $sectionItems);
        }
    }
}
?>
