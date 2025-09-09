<?php
// Admin script to update navigation sections
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Admin') {
    die('Access denied. Admin privileges required.');
}

try {
    // Add missing menu sections and update sort order
    $required_sections = [
        ['customer_crm', 'Customer CRM', 'bi-people', 'Customer relationship management and loyalty programs', 3],
        ['analytics', 'Analytics', 'bi-graph-up', 'Comprehensive analytics and reporting dashboard', 4],
        ['sales', 'Sales Management', 'bi-graph-up', 'Sales dashboard, analytics, and management tools', 5],
        ['inventory', 'Inventory', 'bi-boxes', 'Manage products, categories, brands, suppliers, and inventory', 6],
        ['shelf_labels', 'Shelf Labels', 'bi-tags', 'Generate and manage shelf labels for products', 7],
        ['expiry', 'Expiry Management', 'bi-clock-history', 'Track and manage product expiry dates', 8],
        ['bom', 'Bill of Materials', 'bi-file-earmark-text', 'Create and manage bills of materials and production', 9],
        ['finance', 'Finance', 'bi-calculator', 'Financial reports, budgets, and analysis', 10],
        ['expenses', 'Expense Management', 'bi-cash-stack', 'Track and manage business expenses', 11],
        ['admin', 'Administration', 'bi-shield', 'User management, settings, and system administration', 12]
    ];
    
    $stmt = $conn->prepare("
        INSERT IGNORE INTO menu_sections (section_key, section_name, section_icon, section_description, sort_order) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $added_sections = [];
    foreach ($required_sections as $section) {
        $stmt->execute($section);
        $added_sections[] = $section[0];
    }
    
    // Update existing sections with new sort order
    $stmt = $conn->prepare("
        UPDATE menu_sections 
        SET section_name = ?, section_icon = ?, section_description = ?, sort_order = ? 
        WHERE section_key = ?
    ");
    
    foreach ($required_sections as $section) {
        $stmt->execute([$section[1], $section[2], $section[3], $section[4], $section[0]]);
    }
    
    // Show current menu sections
    $stmt = $conn->query("SELECT section_key, section_name, sort_order FROM menu_sections ORDER BY sort_order");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Navigation Update Complete!</h2>";
    echo "<p>Added/Updated sections: " . implode(', ', $added_sections) . "</p>";
    
    echo "<h3>Navigation Order:</h3>";
    echo "<ol>";
    foreach ($sections as $section) {
        echo "<li><strong>" . htmlspecialchars($section['section_name']) . "</strong> (" . htmlspecialchars($section['section_key']) . ")</li>";
    }
    echo "</ol>";
    
    echo "<p><strong>Note:</strong> Dashboard and POS are always shown first, followed by the sections above.</p>";
    echo "<p><a href='../dashboard/dashboard.php'>Return to Dashboard</a></p>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
