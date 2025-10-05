<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$invoice_id = $_GET['invoice_id'] ?? '';

if (empty($invoice_id)) {
    echo json_encode(['error' => 'Invoice ID required']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(invoice_number, order_number) as invoice_number,
            received_date as invoice_date,
            DATE_ADD(received_date, INTERVAL 30 DAY) as due_date,
            total_amount,
            COALESCE(paid_amount, 0) as paid_amount,
            (total_amount - COALESCE(paid_amount, 0)) as balance_due,
            CASE 
                WHEN COALESCE(paid_amount, 0) >= total_amount THEN 'paid'
                WHEN COALESCE(paid_amount, 0) > 0 THEN 'partial'
                WHEN DATEDIFF(CURDATE(), received_date) > 30 THEN 'overdue'
                ELSE 'pending'
            END as status
        FROM inventory_orders 
        WHERE id = ? AND status = 'received'
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['error' => 'Invoice not found']);
        exit();
    }
    
    echo json_encode($invoice);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
