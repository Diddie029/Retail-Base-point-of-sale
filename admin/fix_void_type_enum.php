<?php
/**
 * Database repair script for void_transactions.void_type enum
 * This script fixes the SQLSTATE[01000]: Warning: 1265 Data truncated for column 'void_type' error
 */

session_start();
require_once __DIR__ . '/../include/db.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin') {
    header("Location: ../auth/login.php");
    exit();
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_enum'])) {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Step 1: Check current enum definition
        $stmt = $conn->query("SHOW COLUMNS FROM void_transactions LIKE 'void_type'");
        $column_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($column_info) {
            $current_type = $column_info['Type'];
            $messages[] = "Current void_type definition: " . $current_type;
            
            // Step 2: Check if 'held_transaction' is missing from enum
            if (strpos($current_type, 'held_transaction') === false) {
                $messages[] = "The 'held_transaction' value is missing from the enum. Attempting to fix...";
                
                // Step 3: Check for any invalid data that might cause issues
                $stmt = $conn->query("SELECT DISTINCT void_type FROM void_transactions");
                $existing_values = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $messages[] = "Existing void_type values in database: " . implode(', ', $existing_values);
                
                // Step 4: Clean up any invalid data first
                $invalid_values = array_diff($existing_values, ['product', 'cart', 'sale', 'held_transaction']);
                if (!empty($invalid_values)) {
                    $messages[] = "Found invalid void_type values: " . implode(', ', $invalid_values);
                    $messages[] = "Converting invalid values to 'sale'...";
                    
                    $conn->exec("UPDATE void_transactions SET void_type = 'sale' WHERE void_type NOT IN ('product', 'cart', 'sale', 'held_transaction')");
                }
                
                // Step 5: Update the enum definition
                $conn->exec("ALTER TABLE void_transactions MODIFY COLUMN void_type ENUM('product','cart','sale','held_transaction') NOT NULL");
                $messages[] = "Successfully updated void_type enum to include 'held_transaction'";
                
                // Step 6: Verify the change
                $stmt = $conn->query("SHOW COLUMNS FROM void_transactions LIKE 'void_type'");
                $updated_column_info = $stmt->fetch(PDO::FETCH_ASSOC);
                $messages[] = "Updated void_type definition: " . $updated_column_info['Type'];
                
            } else {
                $messages[] = "The void_type enum already includes 'held_transaction'. No fix needed.";
            }
        } else {
            $errors[] = "Could not find void_type column in void_transactions table.";
        }
        
        $conn->commit();
        $messages[] = "Database repair completed successfully!";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        $conn->rollBack();
        $errors[] = "Error: " . $e->getMessage();
    }
}

// Test the fix by attempting a sample insert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_insert'])) {
    try {
        $conn->beginTransaction();
        
        // Try to insert a test record with 'held_transaction' void_type
        $stmt = $conn->prepare("
            INSERT INTO void_transactions (
                user_id, till_id, void_type, product_id, product_name, 
                quantity, unit_price, total_amount, void_reason
            ) VALUES (?, NULL, 'held_transaction', NULL, ?, 0, 0, ?, ?)
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            'TEST - Held Transaction',
            0.00,
            'Test insert for enum fix verification'
        ]);
        
        $test_id = $conn->lastInsertId();
        
        // Immediately delete the test record
        $conn->exec("DELETE FROM void_transactions WHERE id = $test_id");
        
        $conn->commit();
        $messages[] = "Test insert successful! The void_type enum is working correctly.";
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $errors[] = "Test insert failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Void Type Enum - Database Repair</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0"><i class="bi bi-tools"></i> Database Repair Tool</h4>
                        <small>Fix void_transactions.void_type enum issue</small>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6><i class="bi bi-info-circle"></i> About this tool</h6>
                            <p class="mb-0">This tool fixes the "Data truncated for column 'void_type'" error that occurs when trying to void held transactions. It updates the database enum to include the 'held_transaction' value.</p>
                        </div>

                        <?php if (!empty($messages)): ?>
                            <div class="alert alert-success">
                                <h6><i class="bi bi-check-circle"></i> Messages</h6>
                                <?php foreach ($messages as $message): ?>
                                    <div><?php echo htmlspecialchars($message); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="bi bi-exclamation-triangle"></i> Errors</h6>
                                <?php foreach ($errors as $error): ?>
                                    <div><?php echo htmlspecialchars($error); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="mb-3">
                            <button type="submit" name="fix_enum" class="btn btn-warning">
                                <i class="bi bi-wrench"></i> Fix void_type Enum
                            </button>
                            <small class="form-text text-muted">This will update the database schema to support 'held_transaction' void type.</small>
                        </form>

                        <form method="POST" class="mb-3">
                            <button type="submit" name="test_insert" class="btn btn-info">
                                <i class="bi bi-check-circle"></i> Test Fix
                            </button>
                            <small class="form-text text-muted">This will test if the fix worked by attempting a sample insert.</small>
                        </form>

                        <div class="mt-4">
                            <a href="../pos/sale.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left"></i> Back to POS
                            </a>
                            <a href="../admin/dashboard.php" class="btn btn-secondary">
                                <i class="bi bi-speedometer2"></i> Admin Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
