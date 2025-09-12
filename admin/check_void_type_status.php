<?php
/**
 * Quick diagnostic script to check void_type enum status
 * This helps identify if the enum includes 'held_transaction' value
 */

require_once __DIR__ . '/../include/db.php';

try {
    // Check current enum definition
    $stmt = $conn->query("SHOW COLUMNS FROM void_transactions LIKE 'void_type'");
    $column_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($column_info) {
        echo "Current void_type definition: " . $column_info['Type'] . "\n";
        
        // Check if 'held_transaction' is in the enum
        if (strpos($column_info['Type'], 'held_transaction') !== false) {
            echo "✓ 'held_transaction' is included in the enum\n";
        } else {
            echo "✗ 'held_transaction' is MISSING from the enum\n";
            echo "This is the cause of the 'Data truncated' error!\n";
        }
    } else {
        echo "✗ Could not find void_type column\n";
    }
    
    // Check existing void_type values in the database
    $stmt = $conn->query("SELECT DISTINCT void_type FROM void_transactions");
    $existing_values = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($existing_values)) {
        echo "\nExisting void_type values in database: " . implode(', ', $existing_values) . "\n";
    } else {
        echo "\nNo void transactions found in database yet.\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
