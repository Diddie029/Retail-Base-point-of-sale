<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=pos_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check products table columns
    $stmt = $pdo->query('SHOW COLUMNS FROM products LIKE "is_auto_bom_enabled"');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $result ? '✓ Column exists: ' . $result['Field'] . "\n" : '✗ Column not found: is_auto_bom_enabled' . "\n";

    $stmt = $pdo->query('SHOW COLUMNS FROM products LIKE "auto_bom_type"');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $result ? '✓ Column exists: ' . $result['Field'] . "\n" : '✗ Column not found: auto_bom_type' . "\n";

    // Check tables
    $tables = ['product_families', 'auto_bom_configs', 'auto_bom_selling_units', 'auto_bom_price_history'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        echo $stmt->rowCount() > 0 ? "✓ Table exists: $table\n" : "✗ Table not found: $table\n";
    }

    echo "\nDatabase schema check completed!\n";

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>
