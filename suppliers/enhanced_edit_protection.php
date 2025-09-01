<?php
/**
 * Enhanced Supplier Edit Protection
 * Add these modifications to your edit.php file to prevent accidental updates to other suppliers
 */

// Add this function after the existing sanitizeSupplierInput function in edit.php:

function validateSupplierUpdate($supplier_id, $expected_name) {
    global $conn;
    
    // Double-check that we're updating the right supplier
    $stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE id = :id");
    $stmt->bindParam(':id', $supplier_id);
    $stmt->execute();
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        throw new Exception("Supplier with ID $supplier_id not found");
    }
    
    // Log the validation
    error_log("Validating update for supplier ID: $supplier_id, Current name: '" . $current['name'] . "', Expected: '" . $expected_name . "'");
    
    return $current;
}

// Replace the existing UPDATE query section (lines 124-163) with this enhanced version:

if (empty($errors)) {
    try {
        // Start transaction for safety
        $conn->beginTransaction();
        
        // Validate we're updating the correct supplier
        $current_supplier = validateSupplierUpdate($supplier_id, $supplier['name']);
        
        // Check affected rows to ensure we only update one record
        $update_stmt = $conn->prepare("
            UPDATE suppliers
            SET name = :name,
                contact_person = :contact_person,
                email = :email,
                phone = :phone,
                address = :address,
                payment_terms = :payment_terms,
                notes = :notes,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id
        ");

        $update_stmt->bindParam(':name', $name);
        $update_stmt->bindParam(':contact_person', $contact_person);
        $update_stmt->bindParam(':email', $email);
        $update_stmt->bindParam(':phone', $phone);
        $update_stmt->bindParam(':address', $address);
        $update_stmt->bindParam(':payment_terms', $payment_terms);
        $update_stmt->bindParam(':notes', $notes);
        $update_stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
        $update_stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);

        if ($update_stmt->execute()) {
            $affected_rows = $update_stmt->rowCount();
            
            // Safety check: ensure only one row was affected
            if ($affected_rows !== 1) {
                throw new Exception("Unexpected number of rows affected: $affected_rows (expected 1)");
            }
            
            // Log the successful update
            error_log("Successfully updated supplier ID: $supplier_id, affected rows: $affected_rows");
            logActivity($conn, $user_id, 'supplier_updated', "Updated supplier: $name (ID: $supplier_id)");
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success'] = "Supplier '$name' has been updated successfully!";
            header("Location: view.php?id=$supplier_id");
            exit();
        } else {
            throw new Exception("Update statement failed to execute");
        }
    } catch (Exception $e) {
        // Rollback transaction on any error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        $errors['general'] = 'An error occurred while updating the supplier. Please try again.';
        error_log("Supplier update error for ID $supplier_id: " . $e->getMessage());
        
        // Additional logging for debugging
        error_log("POST data during error: " . print_r($_POST, true));
        error_log("Current supplier data: " . print_r($supplier, true));
    }
}
?>
