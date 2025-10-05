<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$search_term = $_GET['q'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';

if (empty($search_term)) {
    echo json_encode([]);
    exit();
}

try {
    $sql = "
        SELECT 
            io.id,
            COALESCE(io.invoice_number, io.order_number) as invoice_number,
            s.name as supplier_name,
            io.received_date as invoice_date,
            io.total_amount,
            COALESCE(io.paid_amount, 0) as paid_amount,
            (io.total_amount - COALESCE(io.paid_amount, 0)) as balance_due,
            CASE 
                WHEN COALESCE(io.paid_amount, 0) >= io.total_amount THEN 'paid'
                WHEN COALESCE(io.paid_amount, 0) > 0 THEN 'partial'
                WHEN DATEDIFF(CURDATE(), io.received_date) > 30 THEN 'overdue'
                ELSE 'pending'
            END as status
        FROM inventory_orders io
        LEFT JOIN suppliers s ON io.supplier_id = s.id
        WHERE io.status = 'received'
        AND (
            COALESCE(io.invoice_number, io.order_number) LIKE :search_term
            OR s.name LIKE :search_term
            OR io.order_number LIKE :search_term
        )
        AND (io.total_amount - COALESCE(io.paid_amount, 0)) > 0
    ";
    
    $params = [':search_term' => '%' . $search_term . '%'];
    
    if (!empty($supplier_id)) {
        $sql .= " AND io.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    $sql .= " ORDER BY io.received_date DESC LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure we return an array
    if (!is_array($invoices)) {
        $invoices = [];
    }
    
    header('Content-Type: application/json');
    echo json_encode($invoices);
} catch (Exception $e) {
    error_log("Invoice search error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
