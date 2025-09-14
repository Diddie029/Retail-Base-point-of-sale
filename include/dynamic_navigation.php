<?php
// Dynamic navigation section generator
// This file generates navigation sections based on database configuration

// Define section content mappings for each section key
$sectionContentMappings = [
    'dashboard' => [
        ['/dashboard/dashboard.php', 'bi-speedometer2', 'Dashboard']
    ],
    'pos' => [
        ['/pos/sale.php', 'bi-cart-plus', 'Point of Sale'],
        ['/pos/void_reports.php', 'bi-x-circle', 'Void Reports']
    ],
    'quotations' => [
        ['/quotations/quotations.php', 'bi-file-earmark-text', 'All Quotations'],
        ['/quotations/quotation.php?action=create', 'bi-plus-circle', 'Create Quotation'],
        ['/quotations/invoices.php', 'bi-receipt', 'All Invoices']
    ],
    'customer_crm' => [
        ['/customers/crm_dashboard.php', 'bi-speedometer2', 'CRM Dashboard']
    ],
    'inventory' => [
        ['/products/products.php', 'bi-list', 'All Products'],
        ['/categories/categories.php', 'bi-tags', 'Categories'],
        ['/product_families/families.php', 'bi-diagram-3', 'Product Families'],
        ['/brands/brands.php', 'bi-star', 'Brands'],
        ['/suppliers/suppliers.php', 'bi-truck', 'Suppliers'],
        ['/inventory/inventory.php', 'bi-boxes', 'Inventory'],
        ['/products/bulk_operations.php', 'bi-lightning-charge', 'Bulk Operations'],
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
        ['/finance/index.php', 'bi-speedometer2', 'Finance Dashboard']
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
    ],
    'analytics' => [
        ['/analytics/index.php', 'bi-graph-up', 'Analytics Dashboard']
    ],
    'pos_management' => [
        ['/sales/salesdashboard.php', 'bi-cash-register', 'POS Management Dashboard'],
    ],
    'sales' => [
        ['/sales/index.php', 'bi-graph-up', 'Sales Dashboard'],
        ['/sales/salesdashboard.php', 'bi-speedometer2', 'Sales Overview'],
        ['/sales/export_sales.php', 'bi-download', 'Export Sales']
    ],
    'shelf_labels' => [
        ['/shelf_label/index.php', 'bi-tags', 'Shelf Labels'],
        ['/shelf_label/shelf_labels.php', 'bi-tags', 'Manage Labels'],
        ['/shelf_label/generate_labels.php', 'bi-plus-circle', 'Generate Labels'],
        ['/shelf_label/print_labels.php', 'bi-printer', 'Print Labels'],
        ['/shelf_label/export_labels.php', 'bi-download', 'Export Labels']
    ],
    'reports' => [
        ['/reports/index.php', 'bi-file-earmark-bar-graph', 'Reports Dashboard']
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
        $html .= '<a href="' . $url . '" class="nav-link ' . $isActive . '" style="background-color: ' . $bgColor . '">';
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
        'dashboard' => ['Dashboard', 'bi-speedometer2', $sectionContentMappings['dashboard'] ?? []],
        'pos' => ['Point of Sale', 'bi-cart-plus', $sectionContentMappings['pos'] ?? []],
        'quotations' => ['Quotations', 'bi-file-earmark-text', $sectionContentMappings['quotations'] ?? []],
        'pos_management' => ['POS Management', 'bi-cash-register', $sectionContentMappings['pos_management'] ?? []],
        'customer_crm' => ['Customer CRM', 'bi-people', $sectionContentMappings['customer_crm'] ?? []],
        'inventory' => ['Inventory', 'bi-boxes', $sectionContentMappings['inventory'] ?? []],
        'expiry' => ['Expiry Management', 'bi-clock-history', $sectionContentMappings['expiry'] ?? []],
        'bom' => ['Bill of Materials', 'bi-file-earmark-text', $sectionContentMappings['bom'] ?? []],
        'finance' => ['Finance', 'bi-calculator', $sectionContentMappings['finance'] ?? []],
        'expenses' => ['Expense Management', 'bi-cash-stack', $sectionContentMappings['expenses'] ?? []],
        'analytics' => ['Analytics', 'bi-graph-up', $sectionContentMappings['analytics'] ?? []],
        'sales' => ['Sales Management', 'bi-graph-up', $sectionContentMappings['sales'] ?? []],
        'shelf_labels' => ['Shelf Labels', 'bi-tags', $sectionContentMappings['shelf_labels'] ?? []],
        'reports' => ['Reports', 'bi-file-earmark-bar-graph', $sectionContentMappings['reports'] ?? []],
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
