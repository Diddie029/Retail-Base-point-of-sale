<?php
require_once 'include/db.php';

echo "<h2>🔧 Fix Status Enum Issue</h2>";

try {
    echo "<p>Checking current status column definition...</p>";

    // Check current status column definition
    $stmt = $conn->query("DESCRIBE inventory_orders status");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_enum = $result['Type'];

    echo "<p><strong>Current status enum:</strong> {$current_enum}</p>";

    // Check if 'received' is in the enum
    if (strpos($current_enum, 'received') === false) {
        echo "<p style='color: orange;'>⚠️ 'received' status not found in enum. Updating...</p>";

        // First, set any invalid status values to 'pending'
        $conn->exec("UPDATE inventory_orders SET status = 'pending' WHERE status NOT IN ('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled')");

        // Then update the enum
        $conn->exec("ALTER TABLE inventory_orders MODIFY COLUMN status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending'");

        echo "<p style='color: green;'>✅ Status enum updated successfully!</p>";

        // Verify the fix
        $stmt = $conn->query("DESCRIBE inventory_orders status");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_enum = $result['Type'];
        echo "<p><strong>Updated status enum:</strong> {$new_enum}</p>";

    } else {
        echo "<p style='color: green;'>✅ Status enum already includes 'received' value.</p>";
    }

    // Test a status update
    echo "<p>Testing status update...</p>";
    $stmt = $conn->prepare("SELECT id FROM inventory_orders LIMIT 1");
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        $stmt = $conn->prepare("UPDATE inventory_orders SET status = 'received', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$order['id']]);
        echo "<p style='color: green;'>✅ Status update test successful!</p>";
    } else {
        echo "<p>No orders found to test with.</p>";
    }

    echo "<hr>";
    echo "<p><strong>Fix completed!</strong> You can now try receiving orders again.</p>";
    echo "<p><a href='inventory/view_orders.php?filter=receivable'>Go to Receive Orders</a></p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database Error: " . $e->getMessage() . "</p>";
    echo "<p style='color: red;'>Error Code: " . $e->getCode() . "</p>";

    // Provide manual fix instructions
    echo "<hr>";
    echo "<h3>Manual Fix Instructions:</h3>";
    echo "<p>If the automatic fix failed, please run these SQL commands manually in your MySQL database:</p>";
    echo "<pre>";
    echo "UPDATE inventory_orders SET status = 'pending' WHERE status NOT IN ('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled');\n";
    echo "ALTER TABLE inventory_orders MODIFY COLUMN status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending';";
    echo "</pre>";
}
?>
