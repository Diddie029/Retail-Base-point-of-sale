<?php
// Script to fix the product_id constraint issue
session_start();
require_once __DIR__ . '/../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

echo "<h2>Fixing product_id constraint in sale_items table...</h2>";

try {
    // Check current table structure
    $stmt = $conn->query("DESCRIBE sale_items");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Current sale_items table structure:</h3>";
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        foreach ($column as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if product_id allows NULL
    $product_id_column = null;
    foreach ($columns as $column) {
        if ($column['Field'] === 'product_id') {
            $product_id_column = $column;
            break;
        }
    }
    
    if ($product_id_column) {
        echo "<h3>Current product_id column:</h3>";
        echo "<p>Type: " . htmlspecialchars($product_id_column['Type']) . "</p>";
        echo "<p>Null: " . htmlspecialchars($product_id_column['Null']) . "</p>";
        echo "<p>Key: " . htmlspecialchars($product_id_column['Key']) . "</p>";
        
        if ($product_id_column['Null'] === 'NO') {
            echo "<h3>Fixing product_id constraint...</h3>";
            
            // First, drop the existing foreign key constraint
            try {
                $conn->exec("ALTER TABLE sale_items DROP FOREIGN KEY IF EXISTS sale_items_ibfk_2");
                echo "<p>✓ Dropped existing foreign key constraint on sale_items.product_id</p>";
            } catch (Exception $e) {
                echo "<p>⚠ Could not drop foreign key constraint: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // Modify the column to allow NULL values
            try {
                $conn->exec("ALTER TABLE sale_items MODIFY COLUMN product_id INT NULL");
                echo "<p>✓ Updated sale_items.product_id to allow NULL values</p>";
            } catch (Exception $e) {
                echo "<p>✗ Failed to modify product_id column: " . htmlspecialchars($e->getMessage()) . "</p>";
                throw $e;
            }
            
            // Recreate the foreign key constraint with ON DELETE SET NULL
            try {
                $conn->exec("ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");
                echo "<p>✓ Recreated foreign key constraint on sale_items.product_id with ON DELETE SET NULL</p>";
            } catch (Exception $e) {
                echo "<p>⚠ Could not recreate foreign key constraint: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // Verify the change
            $stmt = $conn->query("DESCRIBE sale_items");
            $new_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $new_product_id_column = null;
            foreach ($new_columns as $column) {
                if ($column['Field'] === 'product_id') {
                    $new_product_id_column = $column;
                    break;
                }
            }
            
            if ($new_product_id_column && $new_product_id_column['Null'] === 'YES') {
                echo "<h3>✓ SUCCESS! product_id column now allows NULL values</h3>";
                echo "<p>Type: " . htmlspecialchars($new_product_id_column['Type']) . "</p>";
                echo "<p>Null: " . htmlspecialchars($new_product_id_column['Null']) . "</p>";
            } else {
                echo "<h3>✗ FAILED! product_id column still does not allow NULL values</h3>";
            }
            
        } else {
            echo "<h3>✓ product_id column already allows NULL values</h3>";
        }
    } else {
        echo "<h3>✗ product_id column not found in sale_items table</h3>";
    }
    
    // Test inserting a record with NULL product_id
    echo "<h3>Testing NULL product_id insertion...</h3>";
    try {
        $test_stmt = $conn->prepare("
            INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, price, total_price) 
            VALUES (999999, NULL, 'Test Item', 1, 10.00, 10.00, 10.00)
        ");
        $test_stmt->execute();
        echo "<p>✓ Successfully inserted test record with NULL product_id</p>";
        
        // Clean up test record
        $conn->exec("DELETE FROM sale_items WHERE sale_id = 999999");
        echo "<p>✓ Cleaned up test record</p>";
        
    } catch (Exception $e) {
        echo "<p>✗ Failed to insert test record with NULL product_id: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
} catch (Exception $e) {
    echo "<h3>✗ Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
}

echo "<h3>Migration completed!</h3>";
echo "<p><a href='sale.php'>Return to POS</a></p>";
?>
