<?php
require_once __DIR__ . '/../include/db.php';

// Security check - only allow execution with confirmation parameter
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo "<h2>⚠️ Supplier Deduplication Tool</h2>";
    echo "<p><strong>Warning:</strong> This script will remove duplicate suppliers from your database.</p>";
    echo "<p>Before proceeding:</p>";
    echo "<ol>";
    echo "<li>Make sure you have created a backup by running: <a href='backup_suppliers.php' target='_blank'>backup_suppliers.php</a></li>";
    echo "<li>Review the duplicates by running: <a href='analyze_duplicates.php' target='_blank'>analyze_duplicates.php</a></li>";
    echo "</ol>";
    echo "<p><a href='?confirm=yes' class='btn btn-danger' onclick='return confirm(\"Are you sure you want to proceed with deduplication? Make sure you have a backup!\")'>⚠️ Proceed with Deduplication</a></p>";
    echo "<p><a href='suppliers.php' class='btn btn-secondary'>← Back to Suppliers</a></p>";
    exit;
}

echo "<h2>🔧 Removing Duplicate Suppliers</h2>\n";

try {
    $conn->beginTransaction();
    
    $total_removed = 0;
    $total_updated_products = 0;
    $errors = [];
    
    echo "<h3>Step 1: Finding and Processing Name Duplicates</h3>\n";
    
    // Find duplicates by name
    $stmt = $conn->query("
        SELECT name, COUNT(*) as count, GROUP_CONCAT(id ORDER BY created_at ASC) as ids,
               GROUP_CONCAT(created_at ORDER BY created_at ASC) as dates
        FROM suppliers 
        GROUP BY name 
        HAVING count > 1 
        ORDER BY name
    ");
    
    $name_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($name_duplicates)) {
        echo "<p>✅ No name duplicates found.</p>\n";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>\n";
        echo "<tr><th>Supplier Name</th><th>Duplicate Count</th><th>Action Taken</th><th>Details</th></tr>\n";
        
        foreach ($name_duplicates as $duplicate) {
            $supplier_ids = explode(',', $duplicate['ids']);
            $supplier_name = htmlspecialchars($duplicate['name']);
            $duplicate_count = $duplicate['count'];
            
            // Keep the first (oldest) record, remove the rest
            $keep_id = $supplier_ids[0];
            $remove_ids = array_slice($supplier_ids, 1);
            
            echo "<tr>";
            echo "<td>$supplier_name</td>";
            echo "<td>$duplicate_count</td>";
            
            if (!empty($remove_ids)) {
                // Get detailed info about suppliers to be removed and kept
                $placeholders = str_repeat('?,', count($supplier_ids) - 1) . '?';
                $stmt = $conn->prepare("
                    SELECT id, name, email, phone, contact_person, is_active, created_at,
                           (SELECT COUNT(*) FROM products WHERE supplier_id = s.id) as product_count
                    FROM suppliers s 
                    WHERE id IN ($placeholders)
                    ORDER BY created_at ASC
                ");
                $stmt->execute($supplier_ids);
                $supplier_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $kept_supplier = $supplier_details[0];
                $removed_suppliers = array_slice($supplier_details, 1);
                
                // Update products to point to the kept supplier
                $products_updated = 0;
                foreach ($remove_ids as $remove_id) {
                    $update_stmt = $conn->prepare("UPDATE products SET supplier_id = ? WHERE supplier_id = ?");
                    $update_stmt->execute([$keep_id, $remove_id]);
                    $products_updated += $update_stmt->rowCount();
                }
                $total_updated_products += $products_updated;
                
                // Remove duplicate suppliers
                $remove_placeholders = str_repeat('?,', count($remove_ids) - 1) . '?';
                $delete_stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($remove_placeholders)");
                $delete_stmt->execute($remove_ids);
                $removed_count = $delete_stmt->rowCount();
                $total_removed += $removed_count;
                
                echo "<td style='color: green;'>✅ Removed $removed_count duplicate(s)</td>";
                echo "<td>";
                echo "<strong>Kept:</strong> ID $keep_id (created: " . $kept_supplier['created_at'] . ")<br>";
                echo "<strong>Removed:</strong> IDs " . implode(', ', $remove_ids) . "<br>";
                echo "<strong>Products updated:</strong> $products_updated";
                echo "</td>";
            } else {
                echo "<td>No action needed</td>";
                echo "<td>Only one supplier found</td>";
            }
            
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    echo "<h3>Step 2: Finding and Processing Email Duplicates</h3>\n";
    
    // Find duplicates by email (excluding name duplicates already processed)
    $stmt = $conn->query("
        SELECT email, COUNT(*) as count, GROUP_CONCAT(id ORDER BY created_at ASC) as ids,
               GROUP_CONCAT(name ORDER BY created_at ASC) as names
        FROM suppliers 
        WHERE email IS NOT NULL AND email != ''
        GROUP BY email 
        HAVING count > 1 
        ORDER BY email
    ");
    
    $email_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($email_duplicates)) {
        echo "<p>✅ No email duplicates found.</p>\n";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>\n";
        echo "<tr><th>Email</th><th>Duplicate Count</th><th>Action Taken</th><th>Details</th></tr>\n";
        
        foreach ($email_duplicates as $duplicate) {
            $supplier_ids = explode(',', $duplicate['ids']);
            $supplier_names = explode(',', $duplicate['names']);
            $email = htmlspecialchars($duplicate['email']);
            $duplicate_count = $duplicate['count'];
            
            // Check if these are different companies with same email (keep all)
            $unique_names = array_unique($supplier_names);
            if (count($unique_names) > 1) {
                echo "<tr>";
                echo "<td>$email</td>";
                echo "<td>$duplicate_count</td>";
                echo "<td style='color: orange;'>⚠️ Kept all (different companies)</td>";
                echo "<td>Different supplier names: " . implode(', ', $unique_names) . "</td>";
                echo "</tr>\n";
                continue;
            }
            
            // Same name - treat as duplicate
            $keep_id = $supplier_ids[0];
            $remove_ids = array_slice($supplier_ids, 1);
            
            if (!empty($remove_ids)) {
                // Update products to point to the kept supplier
                $products_updated = 0;
                foreach ($remove_ids as $remove_id) {
                    $update_stmt = $conn->prepare("UPDATE products SET supplier_id = ? WHERE supplier_id = ?");
                    $update_stmt->execute([$keep_id, $remove_id]);
                    $products_updated += $update_stmt->rowCount();
                }
                $total_updated_products += $products_updated;
                
                // Remove duplicate suppliers
                $remove_placeholders = str_repeat('?,', count($remove_ids) - 1) . '?';
                $delete_stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($remove_placeholders)");
                $delete_stmt->execute($remove_ids);
                $removed_count = $delete_stmt->rowCount();
                $total_removed += $removed_count;
                
                echo "<tr>";
                echo "<td>$email</td>";
                echo "<td>$duplicate_count</td>";
                echo "<td style='color: green;'>✅ Removed $removed_count duplicate(s)</td>";
                echo "<td>";
                echo "<strong>Kept:</strong> ID $keep_id<br>";
                echo "<strong>Removed:</strong> IDs " . implode(', ', $remove_ids) . "<br>";
                echo "<strong>Products updated:</strong> $products_updated";
                echo "</td>";
                echo "</tr>\n";
            }
        }
        echo "</table>\n";
    }
    
    echo "<h3>Step 3: Final Cleanup and Verification</h3>\n";
    
    // Check for any remaining obvious duplicates (same name and email)
    $stmt = $conn->query("
        SELECT name, email, COUNT(*) as count, GROUP_CONCAT(id) as ids
        FROM suppliers 
        WHERE email IS NOT NULL AND email != ''
        GROUP BY name, email 
        HAVING count > 1
    ");
    
    $remaining_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($remaining_duplicates)) {
        echo "<p>⚠️ Found additional duplicates with same name AND email:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Name</th><th>Email</th><th>Count</th><th>IDs</th></tr>";
        
        foreach ($remaining_duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['name']) . "</td>";
            echo "<td>" . htmlspecialchars($dup['email']) . "</td>";
            echo "<td>" . $dup['count'] . "</td>";
            echo "<td>" . $dup['ids'] . "</td>";
            echo "</tr>";
            
            // Auto-remove these as well
            $supplier_ids = explode(',', $dup['ids']);
            $keep_id = $supplier_ids[0];
            $remove_ids = array_slice($supplier_ids, 1);
            
            if (!empty($remove_ids)) {
                // Update products
                foreach ($remove_ids as $remove_id) {
                    $update_stmt = $conn->prepare("UPDATE products SET supplier_id = ? WHERE supplier_id = ?");
                    $update_stmt->execute([$keep_id, $remove_id]);
                    $total_updated_products += $update_stmt->rowCount();
                }
                
                // Remove duplicates
                $remove_placeholders = str_repeat('?,', count($remove_ids) - 1) . '?';
                $delete_stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($remove_placeholders)");
                $delete_stmt->execute($remove_ids);
                $total_removed += $delete_stmt->rowCount();
            }
        }
        echo "</table>";
    } else {
        echo "<p>✅ No additional duplicates found.</p>";
    }
    
    $conn->commit();
    
    echo "<hr>";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; padding: 15px; margin: 20px 0;'>";
    echo "<h3 style='color: #155724; margin-top: 0;'>🎉 Deduplication Complete!</h3>";
    echo "<p><strong>Summary:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Total duplicate suppliers removed:</strong> $total_removed</li>";
    echo "<li><strong>Total product relationships updated:</strong> $total_updated_products</li>";
    echo "<li><strong>Process status:</strong> ✅ Successful</li>";
    echo "</ul>";
    echo "</div>";
    
    // Show final supplier count
    $stmt = $conn->query("SELECT COUNT(*) as count FROM suppliers");
    $final_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "<h3>Post-Deduplication Stats:</h3>";
    echo "<p><strong>Remaining suppliers:</strong> $final_count</p>";
    
    echo "<h3>📋 Next Steps:</h3>";
    echo "<ol>";
    echo "<li><a href='suppliers.php'>Return to Suppliers Management</a> to verify the results</li>";
    echo "<li>Check that all products still have valid supplier associations</li>";
    echo "<li>If you notice any issues, you can restore from the backup table created earlier</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "<div style='color: red; font-weight: bold;'>❌ Deduplication failed!</div>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Transaction has been rolled back. No changes were made to the database.</p>";
    echo "<p><a href='suppliers.php'>← Return to Suppliers</a></p>";
}
?>
