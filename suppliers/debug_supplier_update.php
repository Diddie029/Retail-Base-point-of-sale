<?php
// Debug script for supplier updates
// Add this code to the beginning of edit.php after line 67 (before form processing)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log all POST data and supplier ID for debugging
    error_log("=== SUPPLIER UPDATE DEBUG ===");
    error_log("Supplier ID from URL: " . $supplier_id);
    error_log("POST data: " . print_r($_POST, true));
    error_log("Current supplier data: " . print_r($supplier, true));
    
    // Check if the supplier ID is being modified somehow
    if (isset($_POST['supplier_id']) && $_POST['supplier_id'] != $supplier_id) {
        error_log("WARNING: Supplier ID mismatch! URL: $supplier_id, POST: " . $_POST['supplier_id']);
    }
    
    // Add a unique marker to identify this specific update
    $debug_marker = "UPDATE_" . date('Y-m-d_H:i:s') . "_" . $supplier_id;
    error_log("Debug marker: " . $debug_marker);
}
?>
