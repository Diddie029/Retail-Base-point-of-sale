<?php
require_once __DIR__ . '/../include/db.php';

echo "<h2>Supplier Duplicate Analysis</h2>\n";

try {
    // Find duplicates by name
    echo "<h3>Duplicates by Name:</h3>\n";
    $stmt = $conn->query("
        SELECT name, COUNT(*) as count, GROUP_CONCAT(id) as ids, 
               GROUP_CONCAT(email) as emails, 
               GROUP_CONCAT(created_at) as created_dates
        FROM suppliers 
        GROUP BY name 
        HAVING count > 1 
        ORDER BY count DESC
    ");
    
    $name_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($name_duplicates)) {
        echo "No name duplicates found.\n";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr><th>Name</th><th>Count</th><th>IDs</th><th>Emails</th><th>Created Dates</th></tr>\n";
        foreach ($name_duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['name']) . "</td>";
            echo "<td>" . $dup['count'] . "</td>";
            echo "<td>" . $dup['ids'] . "</td>";
            echo "<td>" . htmlspecialchars($dup['emails']) . "</td>";
            echo "<td>" . $dup['created_dates'] . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Find duplicates by email
    echo "<h3>Duplicates by Email:</h3>\n";
    $stmt = $conn->query("
        SELECT email, COUNT(*) as count, GROUP_CONCAT(id) as ids, 
               GROUP_CONCAT(name) as names,
               GROUP_CONCAT(created_at) as created_dates
        FROM suppliers 
        WHERE email IS NOT NULL AND email != ''
        GROUP BY email 
        HAVING count > 1 
        ORDER BY count DESC
    ");
    
    $email_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($email_duplicates)) {
        echo "No email duplicates found.\n";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr><th>Email</th><th>Count</th><th>IDs</th><th>Names</th><th>Created Dates</th></tr>\n";
        foreach ($email_duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['email']) . "</td>";
            echo "<td>" . $dup['count'] . "</td>";
            echo "<td>" . $dup['ids'] . "</td>";
            echo "<td>" . htmlspecialchars($dup['names']) . "</td>";
            echo "<td>" . $dup['created_dates'] . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Find duplicates by phone
    echo "<h3>Duplicates by Phone:</h3>\n";
    $stmt = $conn->query("
        SELECT phone, COUNT(*) as count, GROUP_CONCAT(id) as ids, 
               GROUP_CONCAT(name) as names,
               GROUP_CONCAT(created_at) as created_dates
        FROM suppliers 
        WHERE phone IS NOT NULL AND phone != ''
        GROUP BY phone 
        HAVING count > 1 
        ORDER BY count DESC
    ");
    
    $phone_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($phone_duplicates)) {
        echo "No phone duplicates found.\n";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>\n";
        echo "<tr><th>Phone</th><th>Count</th><th>IDs</th><th>Names</th><th>Created Dates</th></tr>\n";
        foreach ($phone_duplicates as $dup) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dup['phone']) . "</td>";
            echo "<td>" . $dup['count'] . "</td>";
            echo "<td>" . $dup['ids'] . "</td>";
            echo "<td>" . htmlspecialchars($dup['names']) . "</td>";
            echo "<td>" . $dup['created_dates'] . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Show all suppliers for detailed analysis
    echo "<h3>All Suppliers (for detailed analysis):</h3>\n";
    $stmt = $conn->query("
        SELECT id, name, contact_person, email, phone, address, is_active, created_at,
               (SELECT COUNT(*) FROM products WHERE supplier_id = s.id) as product_count
        FROM suppliers s
        ORDER BY name, created_at
    ");
    
    $all_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; font-size: 12px;'>\n";
    echo "<tr><th>ID</th><th>Name</th><th>Contact</th><th>Email</th><th>Phone</th><th>Products</th><th>Status</th><th>Created</th></tr>\n";
    foreach ($all_suppliers as $supplier) {
        $rowClass = '';
        // Highlight potential duplicates
        foreach ($name_duplicates as $dup) {
            if (in_array($supplier['id'], explode(',', $dup['ids']))) {
                $rowClass = 'style="background-color: #ffeb3b;"';
                break;
            }
        }
        
        echo "<tr $rowClass>";
        echo "<td>" . $supplier['id'] . "</td>";
        echo "<td>" . htmlspecialchars($supplier['name']) . "</td>";
        echo "<td>" . htmlspecialchars($supplier['contact_person'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($supplier['email'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($supplier['phone'] ?? '') . "</td>";
        echo "<td>" . $supplier['product_count'] . "</td>";
        echo "<td>" . ($supplier['is_active'] ? 'Active' : 'Inactive') . "</td>";
        echo "<td>" . $supplier['created_at'] . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
