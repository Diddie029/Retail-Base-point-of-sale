<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$supplier_id = $_GET['supplier_id'] ?? '';

if (empty($supplier_id)) {
    echo json_encode([]);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT 
            id,
            COALESCE(invoice_number, order_number) as invoice_number,
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
        WHERE supplier_id = ? 
        AND status = 'received'
        AND (total_amount - COALESCE(paid_amount, 0)) > 0
        ORDER BY received_date DESC
    ");
    $stmt->execute([$supplier_id]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($invoices);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
