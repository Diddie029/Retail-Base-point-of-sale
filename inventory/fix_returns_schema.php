<?php
/**
 * Fix Returns Schema Issues
 * 
 * This script fixes the existing returns table schema to support the new functionality.
 */

// Include database connection
require_once __DIR__ . '/../include/db.php';

$message = '';
$message_type = 'info';

try {
    // Fix 1: Update returns table status enum to include 'processed'
    try {
        $stmt = $conn->query("DESCRIBE returns status");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_enum = $result['Type'];

        if (strpos($current_enum, 'processed') === false) {
            $conn->exec("ALTER TABLE returns MODIFY COLUMN status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed') DEFAULT 'pending'");
            $message .= "✅ Added 'processed' status to returns table<br>";
        } else {
            $message .= "ℹ️ 'processed' status already exists in returns table<br>";
        }
    } catch (PDOException $e) {
        $message .= "⚠️ Could not update returns status: " . $e->getMessage() . "<br>";
    }

    // Fix 2: Update return_status_history table status enums
    try {
        $stmt = $conn->query("DESCRIBE return_status_history old_status");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_enum = $result['Type'];

        if (strpos($current_enum, 'processed') === false) {
            $conn->exec("ALTER TABLE return_status_history MODIFY COLUMN old_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed')");
            $conn->exec("ALTER TABLE return_status_history MODIFY COLUMN new_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed') NOT NULL");
            $message .= "✅ Added 'processed' status to return_status_history table<br>";
        } else {
            $message .= "ℹ️ 'processed' status already exists in return_status_history table<br>";
        }
    } catch (PDOException $e) {
        $message .= "⚠️ Could not update return_status_history: " . $e->getMessage() . "<br>";
    }

    // Fix 3: Add missing columns to return_items table
    try {
        $stmt = $conn->query("DESCRIBE return_items");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('accepted_quantity', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN accepted_quantity INT DEFAULT 0 COMMENT 'Quantity of items accepted for return'");
            $message .= "✅ Added accepted_quantity column<br>";
        } else {
            $message .= "ℹ️ accepted_quantity column already exists<br>";
        }

        if (!in_array('action_taken', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN action_taken ENUM('pending', 'accepted', 'partial_accept', 'rejected', 'exchange', 'refund') DEFAULT 'pending' COMMENT 'Action taken on this return item'");
            $message .= "✅ Added action_taken column<br>";
        } else {
            $message .= "ℹ️ action_taken column already exists<br>";
        }

        if (!in_array('action_notes', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN action_notes TEXT COMMENT 'Notes about the action taken on this item'");
            $message .= "✅ Added action_notes column<br>";
        } else {
            $message .= "ℹ️ action_notes column already exists<br>";
        }

        if (!in_array('updated_at', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this item was last updated'");
            $message .= "✅ Added updated_at column<br>";
        } else {
            $message .= "ℹ️ updated_at column already exists<br>";
        }

        // Add indexes
        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_action_taken (action_taken)");
            $message .= "✅ Added action_taken index<br>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $message .= "ℹ️ action_taken index already exists<br>";
            } else {
                throw $e;
            }
        }

        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_accepted_quantity (accepted_quantity)");
            $message .= "✅ Added accepted_quantity index<br>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $message .= "ℹ️ accepted_quantity index already exists<br>";
            } else {
                throw $e;
            }
        }

        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_updated_at (updated_at)");
            $message .= "✅ Added updated_at index<br>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $message .= "ℹ️ updated_at index already exists<br>";
            } else {
                throw $e;
            }
        }

        // Update existing records
        $stmt = $conn->prepare("UPDATE return_items SET action_taken = 'pending', accepted_quantity = 0 WHERE action_taken IS NULL");
        $stmt->execute();
        $affected_rows = $stmt->rowCount();
        
        if ($affected_rows > 0) {
            $message .= "✅ Updated $affected_rows existing records with default values<br>";
        } else {
            $message .= "ℹ️ No existing records needed updating<br>";
        }

    } catch (PDOException $e) {
        $message .= "⚠️ Could not update return_items table: " . $e->getMessage() . "<br>";
    }

    $message_type = 'success';
    $message .= "<br>🎉 Schema fixes completed! Your returns functionality should now work properly.";

} catch (Exception $e) {
    $message_type = 'danger';
    $message = "❌ Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Returns Schema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-wrench me-2"></i>
                            Fix Returns Schema Issues
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-<?php echo $message_type; ?>">
                            <?php echo $message; ?>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <a href="returns_list.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left me-2"></i>
                                Back to Returns List
                            </a>
                            <a href="view_return.php" class="btn btn-success">
                                <i class="bi bi-eye me-2"></i>
                                Test Returns Functionality
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
