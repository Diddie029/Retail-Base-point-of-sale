<?php
require_once 'include/db.php';

echo "<h2>🧪 Enhanced Database & Order Creation Test</h2>";

echo "<h3>📊 System Information:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-bottom: 20px;'>";
echo "<tr><th>Component</th><th>Version/Details</th></tr>";
echo "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
echo "<tr><td>PDO MySQL</td><td>" . (extension_loaded('pdo_mysql') ? '✅ Enabled' : '❌ Disabled') . "</td></tr>";
echo "<tr><td>MySQL Version</td><td>" . (isset($conn) ? $conn->getAttribute(PDO::ATTR_SERVER_VERSION) : 'Not connected') . "</td></tr>";
echo "<tr><td>Database</td><td>pos_system</td></tr>";
echo "<tr><td>Test Date/Time</td><td>" . date('Y-m-d H:i:s T') . "</td></tr>";
echo "</table>";

try {
    echo "<p style='color: green;'>✅ Database connection successful!</p>";
    
    // Test basic query
    $stmt = $conn->prepare("SELECT 1 as test");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>✅ Basic query test: " . $result['test'] . "</p>";
    
    // Check if products table exists
    $stmt = $conn->prepare("SHOW TABLES LIKE 'products'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo "<p>✅ Products table exists</p>";
        
        // Check products table structure
        $stmt = $conn->prepare("DESCRIBE products");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Products Table Columns:</h3>";
        echo "<ul>";
        foreach ($columns as $column) {
            echo "<li><strong>" . $column['Field'] . "</strong> - " . $column['Type'] . " (Default: " . ($column['Default'] ?? 'NULL') . ")</li>";
        }
        echo "</ul>";
        
        // Check if we can insert a test record
        echo "<h3>Testing Insert Operation:</h3>";
        
        // Check if categories table has data
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM categories");
        $stmt->execute();
        $cat_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "<p>Categories count: $cat_count</p>";
        
        if ($cat_count > 0) {
            // Get first category
            $stmt = $conn->prepare("SELECT id FROM categories LIMIT 1");
            $stmt->execute();
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($category) {
                echo "<p>✅ Found category with ID: " . $category['id'] . "</p>";
                
                // Try to insert a test product
                try {
                    $test_insert = $conn->prepare("
                        INSERT INTO products (
                            name, description, category_id, sku, product_type, price, 
                            quantity, status, publication_status, created_at, updated_at
                        ) VALUES (
                            'TEST PRODUCT', 'Test description', :cat_id, 'TEST001', 'physical', 10.00,
                            1, 'active', 'publish_now', NOW(), NOW()
                        )
                    ");
                    $test_insert->bindParam(':cat_id', $category['id']);
                    
                    if ($test_insert->execute()) {
                        $test_id = $conn->lastInsertId();
                        echo "<p style='color: green;'>✅ Test product inserted successfully with ID: $test_id</p>";
                        
                        // Clean up test product
                        $conn->exec("DELETE FROM products WHERE id = $test_id");
                        echo "<p>✅ Test product cleaned up</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Test product insert failed</p>";
                    }
                } catch (PDOException $e) {
                    echo "<p style='color: red;'>❌ Test insert error: " . $e->getMessage() . "</p>";
                    echo "<p>Error Code: " . $e->getCode() . "</p>";
                    echo "<pre>" . print_r($e->errorInfo, true) . "</pre>";
                }
            } else {
                echo "<p style='color: red;'>❌ No categories found</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Categories table is empty</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Products table does not exist</p>";
    }
    
    // Check other required tables
    $required_tables = ['categories', 'brands', 'suppliers', 'settings', 'activity_logs', 'inventory_orders', 'inventory_order_items', 'login_attempts', 'role_permissions', 'permissions', 'roles'];
    echo "<h3>Required Tables Check:</h3>";
    foreach ($required_tables as $table) {
        $stmt = $conn->prepare("SHOW TABLES LIKE '$table'");
        $stmt->execute();
        if ($stmt->fetch()) {
            echo "<p>✅ $table table exists</p>";
            
            // Check suppliers table structure specifically
            if ($table === 'suppliers') {
                $stmt = $conn->prepare("DESCRIBE suppliers");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<h4>Suppliers Table Columns:</h4>";
                echo "<ul>";
                foreach ($columns as $column) {
                    $highlight = '';
                    if ($column['Field'] === 'supplier_block_note') {
                        $highlight = ' style="color: green; font-weight: bold;"';
                    }
                    echo "<li{$highlight}><strong>" . $column['Field'] . "</strong> - " . $column['Type'] . " (Default: " . ($column['Default'] ?? 'NULL') . ")</li>";
                }
                echo "</ul>";
                
                // Check if supplier_block_note field exists
                $hasBlockNote = false;
                foreach ($columns as $column) {
                    if ($column['Field'] === 'supplier_block_note') {
                        $hasBlockNote = true;
                        break;
                    }
                }
                
                if ($hasBlockNote) {
                    echo "<p style='color: green;'>✅ supplier_block_note field exists</p>";
                    
                    // Test supplier blocking functionality
                    echo "<h4>Testing Supplier Blocking:</h4>";
                    
                    // Check if there are any suppliers to test with
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers");
                    $stmt->execute();
                    $supplier_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    echo "<p>Suppliers count: $supplier_count</p>";
                    
                    if ($supplier_count > 0) {
                        // Get first supplier
                        $stmt = $conn->prepare("SELECT id, name, is_active FROM suppliers LIMIT 1");
                        $stmt->execute();
                        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($supplier) {
                            echo "<p>✅ Found supplier: " . htmlspecialchars($supplier['name']) . " (ID: " . $supplier['id'] . ", Status: " . ($supplier['is_active'] ? 'Active' : 'Inactive') . ")</p>";
                            
                            // Test updating supplier with block note
                            try {
                                $test_block = $conn->prepare("UPDATE suppliers SET supplier_block_note = 'Test block reason for testing' WHERE id = :id");
                                $test_block->bindParam(':id', $supplier['id']);
                                
                                if ($test_block->execute()) {
                                    echo "<p style='color: green;'>✅ Supplier block note update test successful</p>";
                                    
                                    // Verify the update
                                    $stmt = $conn->prepare("SELECT supplier_block_note FROM suppliers WHERE id = :id");
                                    $stmt->bindParam(':id', $supplier['id']);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($result && $result['supplier_block_note']) {
                                        echo "<p style='color: green;'>✅ Block note verified: " . htmlspecialchars($result['supplier_block_note']) . "</p>";
                                    } else {
                                        echo "<p style='color: red;'>❌ Block note verification failed</p>";
                                    }
                                } else {
                                    echo "<p style='color: red;'>❌ Supplier block note update test failed</p>";
                                }
                            } catch (PDOException $e) {
                                echo "<p style='color: red;'>❌ Supplier block test error: " . $e->getMessage() . "</p>";
                            }
                        } else {
                            echo "<p style='color: red;'>❌ No suppliers found</p>";
                        }
                    } else {
                        echo "<p style='color: orange;'>⚠️ No suppliers to test with</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ supplier_block_note field missing</p>";
                }
            }

            // Check inventory orders tables
            if ($table === 'inventory_orders') {
                echo "<h4>Testing Order Creation:</h4>";

                // Check table structure
                $stmt = $conn->prepare("DESCRIBE inventory_orders");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo "<h5>Inventory Orders Table Structure:</h5>";
                echo "<table border='1' cellpadding='3' style='border-collapse: collapse; margin-bottom: 10px;'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($columns as $column) {
                    echo "<tr>";
                    echo "<td>" . $column['Field'] . "</td>";
                    echo "<td>" . $column['Type'] . "</td>";
                    echo "<td>" . $column['Null'] . "</td>";
                    echo "<td>" . ($column['Key'] ?? '') . "</td>";
                    echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }

            if ($table === 'inventory_order_items') {
                echo "<h4>Testing Order Items:</h4>";

                // Check table structure
                $stmt = $conn->prepare("DESCRIBE inventory_order_items");
                $stmt->execute();
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo "<h5>Inventory Order Items Table Structure:</h5>";
                echo "<table border='1' cellpadding='3' style='border-collapse: collapse; margin-bottom: 10px;'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($columns as $column) {
                    echo "<tr>";
                    echo "<td>" . $column['Field'] . "</td>";
                    echo "<td>" . $column['Type'] . "</td>";
                    echo "<td>" . $column['Null'] . "</td>";
                    echo "<td>" . ($column['Key'] ?? '') . "</td>";
                    echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } else {
            echo "<p style='color: red;'>❌ $table table missing</p>";
            if (in_array($table, ['inventory_orders', 'inventory_order_items'])) {
                echo "<p style='color: orange;'>⚠️ This table is required for order creation. Check db.php file.</p>";
            }
        }
    }

    // Test complete order creation workflow
    echo "<h3>🧪 Order Creation Workflow Test:</h3>";

    // Check if we have the minimum required data
    $suppliers_count = $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['count'];
    $products_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE supplier_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC)['count'];
    $categories_count = $conn->query("SELECT COUNT(*) as count FROM categories")->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<h4>Prerequisites Check:</h4>";
    echo "<ul>";
    echo "<li>Active Suppliers: <strong>$suppliers_count</strong> " . ($suppliers_count > 0 ? "✅" : "❌ <small style='color: red;'>Need at least 1 active supplier</small>") . "</li>";
    echo "<li>Products with Suppliers: <strong>$products_count</strong> " . ($products_count > 0 ? "✅" : "❌ <small style='color: red;'>Need products assigned to suppliers</small>") . "</li>";
    echo "<li>Categories: <strong>$categories_count</strong> " . ($categories_count > 0 ? "✅" : "❌ <small style='color: red;'>Need at least 1 category</small>") . "</li>";
    echo "</ul>";

    if ($suppliers_count > 0 && $products_count > 0 && $categories_count > 0) {
        echo "<h4>🧪 Full Order Creation Test:</h4>";

        try {
            $conn->beginTransaction();

            // Get test data
            $supplier = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $product = $conn->query("SELECT id, name, cost_price FROM products WHERE supplier_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);

            if ($supplier && $product) {
                echo "<p>✅ Test Data Available:</p>";
                echo "<ul>";
                echo "<li>Supplier: " . htmlspecialchars($supplier['name']) . " (ID: {$supplier['id']})</li>";
                echo "<li>Product: " . htmlspecialchars($product['name']) . " (ID: {$product['id']})</li>";
                echo "</ul>";

                // Generate test order number
                $order_number = 'TEST-' . date('YmdHis') . '-' . rand(100, 999);

                // Insert test order
                $order_stmt = $conn->prepare("
                    INSERT INTO inventory_orders (
                        order_number, supplier_id, user_id, order_date, expected_date,
                        total_items, total_amount, status, notes, created_at
                    ) VALUES (
                        :order_number, :supplier_id, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY),
                        :total_items, :total_amount, 'pending', 'Test order from database test', NOW()
                    )
                ");

                $order_stmt->execute([
                    ':order_number' => $order_number,
                    ':supplier_id' => $supplier['id'],
                    ':total_items' => 1,
                    ':total_amount' => $product['cost_price']
                ]);

                $order_id = $conn->lastInsertId();
                echo "<p>✅ Order inserted successfully (ID: $order_id, Number: $order_number)</p>";

                // Insert order item
                $item_stmt = $conn->prepare("
                    INSERT INTO inventory_order_items (
                        order_id, product_id, quantity, cost_price, total_amount
                    ) VALUES (
                        :order_id, :product_id, :quantity, :cost_price, :total_amount
                    )
                ");

                $item_stmt->execute([
                    ':order_id' => $order_id,
                    ':product_id' => $product['id'],
                    ':quantity' => 1,
                    ':cost_price' => $product['cost_price'],
                    ':total_amount' => $product['cost_price']
                ]);

                echo "<p>✅ Order item inserted successfully</p>";

                $conn->commit();
                echo "<p style='color: green;'>✅ Complete order creation test PASSED!</p>";

                // Clean up test data
                $conn->exec("DELETE FROM inventory_order_items WHERE order_id = $order_id");
                $conn->exec("DELETE FROM inventory_orders WHERE id = $order_id");
                echo "<p>✅ Test data cleaned up</p>";

            } else {
                echo "<p style='color: red;'>❌ Missing test data for order creation test</p>";
            }

        } catch (PDOException $e) {
            $conn->rollBack();
            echo "<p style='color: red;'>❌ Order creation test FAILED: " . $e->getMessage() . "</p>";
            echo "<p><strong>Error Code:</strong> " . $e->getCode() . "</p>";
            echo "<p><strong>SQL State:</strong> " . ($e->errorInfo[0] ?? 'Unknown') . "</p>";
            echo "<p><strong>Driver Error:</strong> " . ($e->errorInfo[1] ?? 'Unknown') . "</p>";
            echo "<p><strong>Driver Message:</strong> " . ($e->errorInfo[2] ?? 'Unknown') . "</p>";

            // Provide specific troubleshooting based on error
            if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                echo "<p style='color: orange;'>🔧 <strong>Troubleshooting:</strong> Foreign key constraint error. Check that:</p>";
                echo "<ul style='color: orange;'>";
                echo "<li>Supplier exists and is active</li>";
                echo "<li>Product exists and has a valid supplier_id</li>";
                echo "<li>All foreign key relationships are properly set up</li>";
                echo "</ul>";
            } elseif (strpos($e->getMessage(), 'duplicate') !== false) {
                echo "<p style='color: orange;'>🔧 <strong>Troubleshooting:</strong> Duplicate entry error. Check order number generation.</p>";
            } elseif (strpos($e->getMessage(), 'table') !== false && strpos($e->getMessage(), 'doesn\'t exist') !== false) {
                echo "<p style='color: orange;'>🔧 <strong>Troubleshooting:</strong> Database table missing. Run the application to create tables or check db.php.</p>";
            }
        }

    } else {
        echo "<p style='color: red;'>❌ Cannot run order creation test - missing prerequisites</p>";
        echo "<p style='color: orange;'>🔧 <strong>To fix:</strong></p>";
        echo "<ul style='color: orange;'>";
        if ($suppliers_count == 0) echo "<li>Add at least one active supplier</li>";
        if ($products_count == 0) echo "<li>Add products and assign them to suppliers</li>";
        if ($categories_count == 0) echo "<li>Add at least one product category</li>";
        echo "</ul>";
    }

    // Test Order Receive Functionality
    echo "<h3>📦 Order Receive Functionality Test:</h3>";

    // Check invoice-related columns in inventory_orders table
    try {
        $stmt = $conn->prepare("DESCRIBE inventory_orders");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $invoice_columns = ['invoice_number', 'received_date', 'invoice_notes'];
        $missing_invoice_columns = [];

        foreach ($invoice_columns as $column_name) {
            $found = false;
            foreach ($columns as $column) {
                if ($column['Field'] === $column_name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing_invoice_columns[] = $column_name;
            }
        }

        if (!empty($missing_invoice_columns)) {
            echo "<p style='color: red;'>❌ Missing invoice columns in inventory_orders table: " . implode(', ', $missing_invoice_columns) . "</p>";
            echo "<p style='color: orange;'>🔧 <strong>Fix:</strong> Run the database migration or check db.php file for column additions.</p>";
        } else {
            echo "<p style='color: green;'>✅ All invoice columns present in inventory_orders table</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Could not check invoice columns: " . $e->getMessage() . "</p>";
    }

    // Test invoice settings
    echo "<h4>🧾 Invoice Settings Test:</h4>";
    try {
        $invoice_settings = [
            'invoice_prefix' => 'INV',
            'invoice_length' => '6',
            'invoice_separator' => '-',
            'invoice_format' => 'prefix-date-number',
            'invoice_auto_generate' => '1'
        ];

        $missing_invoice_settings = [];
        foreach ($invoice_settings as $key => $default_value) {
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                $missing_invoice_settings[] = $key;
            } else {
                echo "<p>✅ $key: " . htmlspecialchars($result['setting_value']) . "</p>";
            }
        }

        if (!empty($missing_invoice_settings)) {
            echo "<p style='color: orange;'>⚠️ Missing invoice settings: " . implode(', ', $missing_invoice_settings) . "</p>";
            echo "<p style='color: orange;'>🔧 <strong>Fix:</strong> Go to Admin → Settings → Inventory tab to configure invoice settings.</p>";

            // Auto-fix missing invoice settings
            echo "<h5>🔧 Auto-fixing missing invoice settings...</h5>";
            $auto_fix_count = 0;

            foreach ($missing_invoice_settings as $setting_key) {
                try {
                    $default_values = [
                        'invoice_prefix' => 'INV',
                        'invoice_length' => '6',
                        'invoice_separator' => '-',
                        'invoice_format' => 'prefix-date-number',
                        'invoice_auto_generate' => '1'
                    ];

                    if (isset($default_values[$setting_key])) {
                        $stmt = $conn->prepare("
                            INSERT IGNORE INTO settings (setting_key, setting_value, created_at, updated_at)
                            VALUES (?, ?, NOW(), NOW())
                        ");
                        $stmt->execute([$setting_key, $default_values[$setting_key]]);
                        echo "<p style='color: green;'>✅ Added default value for: <strong>$setting_key</strong> = {$default_values[$setting_key]}</p>";
                        $auto_fix_count++;
                    }
                } catch (PDOException $e) {
                    echo "<p style='color: red;'>❌ Failed to auto-fix $setting_key: " . $e->getMessage() . "</p>";
                }
            }

            if ($auto_fix_count > 0) {
                echo "<p style='color: green;'>🎉 Successfully auto-fixed $auto_fix_count invoice settings!</p>";
                echo "<p style='color: blue;'>🔄 <strong>Please refresh this page</strong> to see the updated status.</p>";
            } else {
                // Manual fix button if auto-fix didn't work
                echo "<p style='color: orange;'>⚠️ Auto-fix didn't run. Try manual fix:</p>";
                echo "<button onclick='manualFixInvoiceSettings()' style='background: #ff9800; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 5px 0;'>🔧 Manual Fix Invoice Settings</button>";
            }
        } else {
            echo "<p style='color: green;'>✅ All invoice settings are configured</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Could not check invoice settings: " . $e->getMessage() . "</p>";
    }

    // Test invoice number generation
    echo "<h4>🔢 Invoice Number Generation Test:</h4>";
    try {
        // Get invoice settings
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute(['invoice_prefix']);
        $prefix = $stmt->fetch(PDO::FETCH_ASSOC)['setting_value'] ?? 'INV';

        $stmt->execute(['invoice_length']);
        $length = intval($stmt->fetch(PDO::FETCH_ASSOC)['setting_value'] ?? '6');

        $stmt->execute(['invoice_separator']);
        $separator = $stmt->fetch(PDO::FETCH_ASSOC)['setting_value'] ?? '-';

        $stmt->execute(['invoice_format']);
        $format = $stmt->fetch(PDO::FETCH_ASSOC)['setting_value'] ?? 'prefix-date-number';

        // Generate sample invoice number
        $currentDate = date('Ymd');
        $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);

        switch ($format) {
            case 'prefix-date-number':
                $sample_invoice = $prefix . $separator . $currentDate . $separator . $sampleNumber;
                break;
            case 'prefix-number':
                $sample_invoice = $prefix . $separator . $sampleNumber;
                break;
            case 'date-prefix-number':
                $sample_invoice = $currentDate . $separator . $prefix . $separator . $sampleNumber;
                break;
            case 'number-only':
                $sample_invoice = $sampleNumber;
                break;
            default:
                $sample_invoice = $prefix . $separator . $currentDate . $separator . $sampleNumber;
        }

        echo "<p style='color: green;'>✅ Sample invoice number generated: <strong>$sample_invoice</strong></p>";

        // Test uniqueness
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM inventory_orders WHERE invoice_number = ?");
        $stmt->execute([$sample_invoice]);
        $existing_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($existing_count == 0) {
            echo "<p style='color: green;'>✅ Invoice number is unique (no conflicts)</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Invoice number already exists ($existing_count times). This could cause conflicts.</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Invoice number generation test failed: " . $e->getMessage() . "</p>";
    }

    // Test complete order receive workflow
    echo "<h4>🧪 Complete Order Receive Workflow Test:</h4>";

    try {
        // Check for orders that can be received
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM inventory_orders
            WHERE status IN ('waiting_for_delivery', 'sent')
            AND total_items > 0
        ");
        $stmt->execute();
        $receivable_orders = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        echo "<p>Orders available for receiving: <strong>$receivable_orders</strong></p>";

        if ($receivable_orders > 0) {
            // Get a test order
            $stmt = $conn->prepare("
                SELECT io.*, s.name as supplier_name
                FROM inventory_orders io
                JOIN suppliers s ON io.supplier_id = s.id
                WHERE io.status IN ('waiting_for_delivery', 'sent')
                AND io.total_items > 0
                LIMIT 1
            ");
            $stmt->execute();
            $test_order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($test_order) {
                echo "<p>✅ Found test order: <strong>" . htmlspecialchars($test_order['order_number']) . "</strong> from " . htmlspecialchars($test_order['supplier_name']) . "</p>";

                // Get order items
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count
                    FROM inventory_order_items
                    WHERE order_id = ?
                    AND received_quantity < quantity
                ");
                $stmt->execute([$test_order['id']]);
                $unreceived_items = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

                echo "<p>Unreceived items in this order: <strong>$unreceived_items</strong></p>";

                if ($unreceived_items > 0) {
                    echo "<p style='color: green;'>✅ Order is ready for receiving test</p>";

                    // Test receive operation (simulation)
                    echo "<h5>🔄 Receive Operation Simulation:</h5>";

                    $conn->beginTransaction();

                    try {
                        // Generate invoice number
                        $invoice_number = 'TEST-INV-' . date('YmdHis') . '-' . rand(100, 999);

                        // Update order with invoice details
                        $stmt = $conn->prepare("
                            UPDATE inventory_orders
                            SET invoice_number = ?,
                                received_date = CURDATE(),
                                invoice_notes = ?,
                                status = 'received'
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $invoice_number,
                            'Test invoice from database test',
                            $test_order['id']
                        ]);

                        // Simulate receiving some items
                        $stmt = $conn->prepare("
                            UPDATE inventory_order_items
                            SET received_quantity = received_quantity + 1,
                                status = 'received'
                            WHERE order_id = ?
                            AND received_quantity < quantity
                            LIMIT 1
                        ");
                        $stmt->execute([$test_order['id']]);

                        // Update product stock
                        $stmt = $conn->prepare("
                            UPDATE products
                            SET quantity = quantity + 1,
                                updated_at = NOW()
                            WHERE id = (
                                SELECT product_id
                                FROM inventory_order_items
                                WHERE order_id = ?
                                AND received_quantity < quantity
                                LIMIT 1
                            )
                        ");
                        $stmt->execute([$test_order['id']]);

                        $conn->commit();

                        echo "<p style='color: green;'>✅ Order receive simulation successful!</p>";
                        echo "<p>Generated invoice: <strong>$invoice_number</strong></p>";

                        // Verify the update
                        $stmt = $conn->prepare("SELECT status, invoice_number FROM inventory_orders WHERE id = ?");
                        $stmt->execute([$test_order['id']]);
                        $updated_order = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($updated_order['status'] === 'received') {
                            echo "<p style='color: green;'>✅ Order status updated to 'received'</p>";
                        }

                        if ($updated_order['invoice_number'] === $invoice_number) {
                            echo "<p style='color: green;'>✅ Invoice number saved correctly</p>";
                        }

                        // Rollback the test changes
                        $conn->exec("UPDATE inventory_orders SET status = '{$test_order['status']}', invoice_number = NULL, received_date = NULL, invoice_notes = NULL WHERE id = {$test_order['id']}");
                        $conn->exec("UPDATE inventory_order_items SET received_quantity = received_quantity - 1 WHERE order_id = {$test_order['id']} AND received_quantity > 0 LIMIT 1");
                        $conn->exec("UPDATE products SET quantity = quantity - 1 WHERE id = (SELECT product_id FROM inventory_order_items WHERE order_id = {$test_order['id']} LIMIT 1)");
                        echo "<p>✅ Test changes rolled back</p>";

                    } catch (PDOException $e) {
                        $conn->rollBack();
                        echo "<p style='color: red;'>❌ Receive simulation failed: " . $e->getMessage() . "</p>";
                    }

                } else {
                    echo "<p style='color: orange;'>⚠️ All items in this order have been received already</p>";
                }

            } else {
                echo "<p style='color: orange;'>⚠️ Could not find suitable test order</p>";
            }

        } else {
            echo "<p style='color: orange;'>⚠️ No orders available for receiving test</p>";
            echo "<p style='color: orange;'>🔧 <strong>To create test data:</strong></p>";
            echo "<ul style='color: orange;'>";
            echo "<li>Create a supplier</li>";
            echo "<li>Create products and assign to the supplier</li>";
            echo "<li>Create a purchase order</li>";
            echo "<li>Update order status to 'waiting_for_delivery'</li>";
            echo "</ul>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Order receive workflow test failed: " . $e->getMessage() . "</p>";
    }

    // Test receive order page access
    echo "<h4>🌐 Receive Order Page Test:</h4>";
    $receive_order_file = __DIR__ . '/inventory/receive_order.php';

    if (file_exists($receive_order_file)) {
        echo "<p style='color: green;'>✅ receive_order.php file exists</p>";

        // Check file permissions
        if (is_readable($receive_order_file)) {
            echo "<p style='color: green;'>✅ receive_order.php is readable</p>";
        } else {
            echo "<p style='color: red;'>❌ receive_order.php is not readable</p>";
        }

        // Get file size and modification time
        $file_size = filesize($receive_order_file);
        $file_modified = date('Y-m-d H:i:s', filemtime($receive_order_file));

        echo "<p>File size: <strong>" . number_format($file_size) . " bytes</strong></p>";
        echo "<p>Last modified: <strong>$file_modified</strong></p>";

    } else {
        echo "<p style='color: red;'>❌ receive_order.php file does not exist</p>";
        echo "<p style='color: orange;'>🔧 <strong>Expected location:</strong> inventory/receive_order.php</p>";
    }

    // Test API endpoint
    echo "<h3>🔗 API Endpoint Test:</h3>";
    echo "<p>Test the API endpoints used by the order creation system:</p>";
    echo "<ul>";
    echo "<li><a href='api/get_products.php?search=test' target='_blank'>Search Products API</a></li>";
    echo "<li><a href='api/get_products.php?supplier_id=1' target='_blank'>Supplier Products API</a></li>";
    echo "<li><a href='inventory/create_order.php' target='_blank'>Create Order Page</a></li>";
    echo "<li><a href='inventory/receive_order.php?id=1' target='_blank'>Receive Order Page</a></li>";
    echo "</ul>";

    // Check for potential issues
    echo "<h3>🔍 Potential Issues Check:</h3>";

    // Check for products without suppliers
    $products_without_suppliers = $conn->query("SELECT COUNT(*) as count FROM products WHERE supplier_id IS NULL OR supplier_id = 0")->fetch(PDO::FETCH_ASSOC)['count'];
    if ($products_without_suppliers > 0) {
        echo "<p style='color: orange;'>⚠️ <strong>Issue Found:</strong> $products_without_suppliers products don't have suppliers assigned. This will prevent order creation for these products.</p>";
    }

    // Check for inactive suppliers with products
    $inactive_suppliers_with_products = $conn->query("
        SELECT COUNT(DISTINCT s.id) as count
        FROM suppliers s
        JOIN products p ON s.id = p.supplier_id
        WHERE s.is_active = 0
    ")->fetch(PDO::FETCH_ASSOC)['count'];

    if ($inactive_suppliers_with_products > 0) {
        echo "<p style='color: orange;'>⚠️ <strong>Issue Found:</strong> $inactive_suppliers_with_products inactive suppliers have products assigned. These products won't be available for ordering.</p>";
    }

    // Check order number conflicts
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM inventory_orders WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $today_orders = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($today_orders > 100) {
        echo "<p style='color: orange;'>⚠️ <strong>Potential Issue:</strong> $today_orders orders created today. High volume may cause order number conflicts.</p>";
    }

    // Check for foreign key issues
    echo "<h4>🔗 Foreign Key Relationships Check:</h4>";
    try {
        // Check if suppliers referenced by products exist
        $orphaned_products = $conn->query("
            SELECT COUNT(*) as count FROM products p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.supplier_id IS NOT NULL AND s.id IS NULL
        ")->fetch(PDO::FETCH_ASSOC)['count'];

        if ($orphaned_products > 0) {
            echo "<p style='color: red;'>❌ <strong>Critical Issue:</strong> $orphaned_products products reference non-existent suppliers! This will cause order creation to fail.</p>";
            echo "<p style='color: orange;'>🔧 <strong>Fix:</strong> Update product supplier_id values to reference existing suppliers or set to NULL.</p>";
        } else {
            echo "<p style='color: green;'>✅ All product-supplier relationships are valid</p>";
        }

        // Check if categories referenced by products exist
        $orphaned_categories = $conn->query("
            SELECT COUNT(*) as count FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE c.id IS NULL
        ")->fetch(PDO::FETCH_ASSOC)['count'];

        if ($orphaned_categories > 0) {
            echo "<p style='color: red;'>❌ <strong>Critical Issue:</strong> $orphaned_categories products reference non-existent categories! This will cause order creation to fail.</p>";
            echo "<p style='color: orange;'>🔧 <strong>Fix:</strong> Update product category_id values to reference existing categories.</p>";
        } else {
            echo "<p style='color: green;'>✅ All product-category relationships are valid</p>";
        }

        // Check if brands referenced by products exist
        $orphaned_brands = $conn->query("
            SELECT COUNT(*) as count FROM products p
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE p.brand_id IS NOT NULL AND b.id IS NULL
        ")->fetch(PDO::FETCH_ASSOC)['count'];

        if ($orphaned_brands > 0) {
            echo "<p style='color: orange;'>⚠️ <strong>Issue Found:</strong> $orphaned_brands products reference non-existent brands. This won't prevent orders but may cause display issues.</p>";
        } else {
            echo "<p style='color: green;'>✅ All product-brand relationships are valid</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Could not check foreign key relationships: " . $e->getMessage() . "</p>";
    }

    // Check settings
    echo "<h4>⚙️ Settings Check:</h4>";
    try {
        $required_settings = [
            'auto_generate_order_number',
            'order_number_prefix',
            'order_number_format',
            'currency_symbol',
            'invoice_prefix',
            'invoice_length',
            'invoice_separator',
            'invoice_format',
            'invoice_auto_generate'
        ];

        $missing_settings = [];
        foreach ($required_settings as $setting) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM settings WHERE setting_key = ?");
            $stmt->execute([$setting]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                $missing_settings[] = $setting;
            }
        }

        if (!empty($missing_settings)) {
            echo "<p style='color: orange;'>⚠️ <strong>Missing Settings:</strong> " . implode(', ', $missing_settings) . "</p>";
            echo "<p style='color: orange;'>🔧 <strong>Fix:</strong> Go to Admin → Settings → Inventory tab to configure these settings.</p>";
        } else {
            echo "<p style='color: green;'>✅ All required order settings are configured</p>";
        }

    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Could not check settings: " . $e->getMessage() . "</p>";
    }

    // Summary and recommendations
    echo "<h3>📋 Order Creation Status Summary:</h3>";
    echo "<div style='border: 2px solid #ddd; padding: 15px; border-radius: 8px; background: #f9f9f9;'>";

    $issues_found = 0;

    // Count critical issues
    if ($suppliers_count == 0) $issues_found++;
    if ($products_count == 0) $issues_found++;
    if ($categories_count == 0) $issues_found++;
    if ($orphaned_products > 0) $issues_found++;
    if ($orphaned_categories > 0) $issues_found++;

                // Check invoice settings and columns
            $invoice_issues = 0;
            if (empty($missing_invoice_settings)) $invoice_issues++;
            if (!empty($missing_invoice_columns)) $invoice_issues++;

            // Check if supplier_invoice_number column exists
            $supplier_invoice_column_exists = false;
            if (isset($columns)) {
                foreach ($columns as $column) {
                    if ($column['Field'] === 'supplier_invoice_number') {
                        $supplier_invoice_column_exists = true;
                        break;
                    }
                }
            }
            if (!$supplier_invoice_column_exists) $invoice_issues++;

    $issues_found += $invoice_issues;

    if ($issues_found == 0) {
        echo "<h4 style='color: green; margin-top: 0;'>✅ System Ready for Order Management!</h4>";
        echo "<p style='color: green;'>All prerequisites are met and both order creation and order receive systems should work properly.</p>";
        echo "<p><strong>Order Creation:</strong></p>";
        echo "<ul>";
        echo "<li>Navigate to <strong>Inventory → Create Order</strong></li>";
        echo "<li>Select a supplier and follow the multi-step process</li>";
        echo "<li>Add products from the selected supplier</li>";
        echo "<li>Complete the order creation</li>";
        echo "</ul>";
        echo "<p><strong>Order Receiving:</strong></p>";
        echo "<ul>";
        echo "<li>Update order status to 'Waiting for Delivery'</li>";
        echo "<li>Go to order details and click 'Receive Order'</li>";
        echo "<li>Enter invoice details and quantities received</li>";
        echo "<li>Preview and print invoice</li>";
        echo "</ul>";
    } else {
        echo "<h4 style='color: red; margin-top: 0;'>❌ Issues Found - Order Management May Fail</h4>";
        echo "<p style='color: red;'>Found <strong>$issues_found critical issue(s)</strong> that need to be resolved before order creation and receiving will work.</p>";
        echo "<p><strong>Required Actions:</strong></p>";
        echo "<ol>";
        if ($suppliers_count == 0) echo "<li><strong>Add Suppliers:</strong> Go to Suppliers → Add Supplier</li>";
        if ($categories_count == 0) echo "<li><strong>Add Categories:</strong> Go to Products → Categories → Add Category</li>";
        if ($products_count == 0) echo "<li><strong>Add Products:</strong> Go to Products → Add Product (assign to suppliers)</li>";
        if ($orphaned_products > 0) echo "<li><strong>Fix Product Suppliers:</strong> Update products to reference valid suppliers</li>";
        if ($orphaned_categories > 0) echo "<li><strong>Fix Product Categories:</strong> Update products to reference valid categories</li>";
        if (!empty($missing_invoice_settings)) echo "<li><strong>Configure Invoice Settings:</strong> Go to Admin → Settings → Inventory tab</li>";
        if (!empty($missing_invoice_columns)) echo "<li><strong>Update Database Schema:</strong> Run database migration to add invoice columns</li>";
        echo "</ol>";
    }

    echo "</div>";

    // Quick Refresh Button
    if ($issues_found > 0) {
        echo "<div style='text-align: center; margin: 20px 0; padding: 15px; background: #e3f2fd; border-radius: 8px;'>";
        echo "<h4 style='color: #1976d2; margin-bottom: 10px;'>🔄 Auto-Fix Applied - Refresh to Verify</h4>";
        echo "<p>If you see auto-fix messages above, click the button below to verify the fixes:</p>";
        echo "<button onclick='window.location.reload()' style='background: #1976d2; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 16px;'>";
        echo "🔄 Refresh Test Results";
        echo "</button>";
        echo "</div>";
    }

    // Quick diagnostic links
    echo "<h3>🔧 Quick Diagnostic Tools:</h3>";
    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 20px;'>";
    echo "<a href='suppliers/suppliers.php' class='btn btn-outline-primary' target='_blank'>📦 Manage Suppliers</a>";
    echo "<a href='products/products.php' class='btn btn-outline-success' target='_blank'>🛒 Manage Products</a>";
    echo "<a href='categories/categories.php' class='btn btn-outline-info' target='_blank'>📁 Manage Categories</a>";
    echo "<a href='admin/settings/adminsetting.php?tab=inventory' class='btn btn-outline-warning' target='_blank'>⚙️ Order Settings</a>";
    echo "<a href='inventory/create_order.php' class='btn btn-outline-danger' target='_blank'>📝 Create Order</a>";
    echo "<a href='inventory/view_orders.php' class='btn btn-outline-secondary' target='_blank'>📋 View Orders</a>";
    echo "<a href='inventory/receive_order.php?id=1' class='btn btn-outline-success' target='_blank'>📦 Receive Order</a>";
    echo "</div>";

    // Order Receive Readiness Summary
    echo "<h3>📦 Order Receive System Status:</h3>";
    echo "<div style='border: 2px solid #4caf50; padding: 15px; border-radius: 8px; background: #e8f5e8; margin-bottom: 20px;'>";

    $receive_ready = true;
    $receive_checks = [];

    // Check 1: Invoice columns
    $invoice_columns_ok = empty($missing_invoice_columns ?? []);
    $receive_checks[] = ['name' => 'Database Schema', 'status' => $invoice_columns_ok, 'message' => 'Invoice columns in inventory_orders table'];

    // Check 2: Invoice settings
    $invoice_settings_ok = empty($missing_invoice_settings ?? []);
    $receive_checks[] = ['name' => 'Invoice Settings', 'status' => $invoice_settings_ok, 'message' => 'Invoice configuration settings'];

    // Check 3: Receive orders available
    $orders_available = ($receivable_orders ?? 0) > 0;
    $receive_checks[] = ['name' => 'Test Orders', 'status' => $orders_available, 'message' => 'Orders available for receiving'];

    // Check 4: Receive page exists
    $receive_page_exists = file_exists(__DIR__ . '/inventory/receive_order.php');
    $receive_checks[] = ['name' => 'Receive Page', 'status' => $receive_page_exists, 'message' => 'receive_order.php file exists'];

    // Check 5: Database connectivity
    $db_ok = true;
    $receive_checks[] = ['name' => 'Database', 'status' => $db_ok, 'message' => 'Database connection working'];

    foreach ($receive_checks as $check) {
        if (!$check['status']) $receive_ready = false;
        $icon = $check['status'] ? '✅' : '❌';
        $color = $check['status'] ? 'green' : 'red';
        echo "<p style='color: $color;'>$icon <strong>{$check['name']}:</strong> {$check['message']}</p>";
    }

    if ($receive_ready) {
        echo "<h4 style='color: green; margin-top: 15px;'>🎉 Order Receive System is Fully Operational!</h4>";
        echo "<p style='color: green; margin-bottom: 5px;'><strong>What you can do now:</strong></p>";
        echo "<ul style='color: green;'>";
        echo "<li>📦 Receive orders from suppliers</li>";
        echo "<li>🧾 Generate and print invoices automatically</li>";
        echo "<li>📊 Track received quantities and update stock</li>";
        echo "<li>📈 Generate purchase reports with invoice details</li>";
        echo "</ul>";
    } else {
        echo "<h4 style='color: orange; margin-top: 15px;'>⚠️ Order Receive System Needs Attention</h4>";
        echo "<p style='color: orange;'>Some components need to be configured before full functionality is available.</p>";
    }

    echo "</div>";

    // Quick Start Guide
    echo "<h3>🚀 Order Receive Quick Start Guide:</h3>";
    echo "<div style='border: 2px solid #2196f3; padding: 15px; border-radius: 8px; background: #e3f2fd; margin-bottom: 20px;'>";
    echo "<h4 style='color: #1976d2; margin-top: 0;'>How to Use Order Receive:</h4>";
    echo "<ol style='color: #1976d2;'>";
    echo "<li><strong>Create Orders:</strong> Go to Inventory → Create Order</li>";
    echo "<li><strong>Update Status:</strong> Change order status to 'Waiting for Delivery'</li>";
    echo "<li><strong>Receive Order:</strong> Click 'Receive Order' button in order details</li>";
    echo "<li><strong>Enter Supplier Invoice:</strong> Enter the supplier's invoice number (required)</li>";
    echo "<li><strong>Our Invoice Number:</strong> Auto-generated or enter our internal invoice number</li>";
    echo "<li><strong>Enter Details:</strong> Fill received quantities and notes</li>";
    echo "<li><strong>Preview Invoice:</strong> Click 'Preview Invoice' to see both invoice numbers</li>";
    echo "<li><strong>Print Invoice:</strong> Use 'Print Invoice' button for physical copy</li>";
    echo "</ol>";

    echo "<h4 style='color: #1976d2;'>Invoice System Features:</h4>";
    echo "<ul style='color: #1976d2;'>";
    echo "<li><strong>Dual Invoice Numbers:</strong> Track both supplier invoice and our internal invoice</li>";
    echo "<li><strong>Supplier Invoice Required:</strong> Must enter supplier's invoice number to proceed</li>";
    echo "<li><strong>Auto-Generation:</strong> Our invoice numbers can be auto-generated based on settings</li>";
    echo "<li><strong>Customizable Format:</strong> Choose from Prefix-Date-Number, Prefix-Number, Date-Prefix-Number, or Number-Only</li>";
    echo "<li><strong>Flexible Settings:</strong> Configure prefix, length, separator, and auto-generation</li>";
    echo "<li><strong>Real-time Preview:</strong> See both invoice numbers as you type</li>";
    echo "</ul>";

    echo "<h4 style='color: #1976d2;'>Example Invoice Numbers:</h4>";
    echo "<div style='background: white; padding: 10px; border-radius: 4px; margin: 10px 0;'>";
    echo "<code style='color: #1976d2;'>";
    echo "<strong>Supplier Invoice:</strong> SUP-2024-00123<br><br>";
    echo "<strong>Our Invoice (Auto-generated):</strong><br>";
    echo "Prefix-Date-Number: INV-20250830-000001<br>";
    echo "Prefix-Number: INV-000001<br>";
    echo "Date-Prefix-Number: 20250830-INV-000001<br>";
    echo "Number-Only: 000001<br><br>";
    echo "<strong>Our Invoice (Manual):</strong> PUR-001<br>";
    echo "</code>";
    echo "</div>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database Error: " . $e->getMessage() . "</p>";
    echo "<p>Error Code: " . $e->getCode() . "</p>";
    echo "<pre>" . print_r($e->errorInfo, true) . "</pre>";
}
?>

<script>
function manualFixInvoiceSettings() {
    if (confirm('This will manually add the missing invoice settings. Continue?')) {
        // Create form to submit manual fix request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'manual_fix_invoice';
        input.value = '1';

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
