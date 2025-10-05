<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Check if user has admin permissions
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

if ($role_id != 1) { // Assuming 1 is admin role
    die("Access denied. Admin permissions required.");
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

try {
    // First, check how many orders will be processed
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM inventory_orders 
        WHERE status = 'received' 
        AND received_date IS NOT NULL
        AND (invoice_number IS NULL OR invoice_number = '')
    ");
    $count_stmt->execute();
    $order_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($order_count == 0) {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>No Orders to Process</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container mt-5'>
                <div class='alert alert-info'>
                    <h4>No Orders to Process</h4>
                    <p>There are no received orders without invoice numbers to process.</p>
                    <a href='payables.php' class='btn btn-primary'>Back to Payables</a>
                </div>
            </div>
        </body>
        </html>";
        exit();
    }
    
    $conn->beginTransaction();
    
    // Get all received orders without invoice numbers
    $stmt = $conn->prepare("
        SELECT id, order_number, received_date, status
        FROM inventory_orders 
        WHERE status = 'received' 
        AND received_date IS NOT NULL
        AND (invoice_number IS NULL OR invoice_number = '')
        ORDER BY received_date ASC, id ASC
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Double-check that all orders have received status
    $valid_orders = array_filter($orders, function($order) {
        return $order['status'] === 'received' && !empty($order['received_date']);
    });
    
    if (count($valid_orders) !== count($orders)) {
        throw new Exception("Some orders do not have 'received' status or missing received_date. Cannot generate invoice numbers.");
    }
    
    $updated_count = 0;
    
    foreach ($orders as $order) {
        // Generate invoice number based on received date
        $received_date = $order['received_date'];
        $year = date('Y', strtotime($received_date));
        $month = date('m', strtotime($received_date));
        
        $prefix = $settings['inventory_invoice_prefix'] ?? 'INV';
        
        // Get the last invoice number for this month
        $stmt = $conn->prepare("
            SELECT invoice_number 
            FROM inventory_orders 
            WHERE invoice_number LIKE ? 
            ORDER BY invoice_number DESC 
            LIMIT 1
        ");
        $pattern = $prefix . $year . $month . '%';
        $stmt->execute([$pattern]);
        $last_invoice = $stmt->fetchColumn();
        
        if ($last_invoice) {
            // Extract the number part and increment
            $number_part = substr($last_invoice, strlen($prefix . $year . $month));
            $next_number = intval($number_part) + 1;
        } else {
            $next_number = 1;
        }
        
        // Format with leading zeros (4 digits)
        $formatted_number = str_pad($next_number, 4, '0', STR_PAD_LEFT);
        $invoice_number = $prefix . $year . $month . $formatted_number;
        
        // Update the order with the invoice number
        $stmt = $conn->prepare("
            UPDATE inventory_orders 
            SET invoice_number = ? 
            WHERE id = ?
        ");
        $stmt->execute([$invoice_number, $order['id']]);
        
        $updated_count++;
    }
    
    $conn->commit();
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Invoice Numbers Generated</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body>
        <div class='container mt-5'>
            <div class='alert alert-success'>
                <h4>Invoice Numbers Generated Successfully!</h4>
                <p>Generated invoice numbers for <strong>$updated_count</strong> received orders that didn't have invoice numbers.</p>
                <p><small class='text-muted'>Only orders with 'received' status and valid received_date were processed.</small></p>
                <a href='payables.php' class='btn btn-primary'>Back to Payables</a>
            </div>
        </div>
    </body>
    </html>";
    
} catch (Exception $e) {
    $conn->rollback();
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head>
    <body>
        <div class='container mt-5'>
            <div class='alert alert-danger'>
                <h4>Error Generating Invoice Numbers</h4>
                <p>Error: " . htmlspecialchars($e->getMessage()) . "</p>
                <a href='payables.php' class='btn btn-primary'>Back to Payables</a>
            </div>
        </div>
    </body>
    </html>";
}
?>
