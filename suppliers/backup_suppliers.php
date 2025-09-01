<?php
require_once __DIR__ . '/../include/db.php';

echo "<h2>Suppliers Table Backup</h2>\n";

try {
    $backup_timestamp = date('Y-m-d_H-i-s');
    $backup_table_name = "suppliers_backup_" . $backup_timestamp;
    
    // Create backup table
    $conn->exec("CREATE TABLE $backup_table_name AS SELECT * FROM suppliers");
    
    // Get count of backed up records
    $stmt = $conn->query("SELECT COUNT(*) as count FROM $backup_table_name");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "<div style='color: green; font-weight: bold;'>✅ Backup successful!</div>";
    echo "<p>Backup table created: <strong>$backup_table_name</strong></p>";
    echo "<p>Records backed up: <strong>$count</strong></p>";
    echo "<p>Timestamp: <strong>" . date('Y-m-d H:i:s') . "</strong></p>";
    
    // Show backup table structure
    echo "<h3>Backup Table Structure:</h3>";
    $stmt = $conn->query("DESCRIBE $backup_table_name");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Sample of Backed Up Data:</h3>";
    $stmt = $conn->query("SELECT * FROM $backup_table_name LIMIT 5");
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($samples)) {
        echo "<table border='1' style='border-collapse: collapse; font-size: 12px;'>";
        echo "<tr>";
        foreach (array_keys($samples[0]) as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
        
        foreach ($samples as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h3>📋 Next Steps:</h3>";
    echo "<ol>";
    echo "<li>Run the duplicate analysis: <a href='analyze_duplicates.php' target='_blank'>analyze_duplicates.php</a></li>";
    echo "<li>After reviewing duplicates, run the deduplication script</li>";
    echo "<li>Verify the results in the main suppliers page</li>";
    echo "</ol>";
    
    echo "<p><strong>Note:</strong> To restore from backup if needed, use:</p>";
    echo "<code>DROP TABLE suppliers; ALTER TABLE $backup_table_name RENAME TO suppliers;</code>";
    
} catch (Exception $e) {
    echo "<div style='color: red; font-weight: bold;'>❌ Backup failed!</div>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
