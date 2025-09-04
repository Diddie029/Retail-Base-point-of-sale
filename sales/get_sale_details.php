<?php
session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

require_once '../include/db.php';

try {
    $sale_id = $_GET['id'] ?? null;
    
    if (!$sale_id) {
        echo json_encode(['success' => false, 'error' => 'Sale ID is required']);
        exit();
    }
    
    // Get sale details
    $sale_query = "
        SELECT 
            s.*,
            u.username as cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ";
    
    $stmt = $conn->prepare($sale_query);
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) {
        echo json_encode(['success' => false, 'error' => 'Sale not found']);
        exit();
    }
    
    // Get sale items
    $items_query = "
        SELECT 
            si.*,
            p.name as product_name,
            COALESCE(si.product_name, p.name) as display_name
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
    ";
    
    $stmt = $conn->prepare($items_query);
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment details if available
    $payments_query = "
        SELECT * FROM sale_payments 
        WHERE sale_id = ?
        ORDER BY received_at DESC
    ";
    
    $stmt = $conn->prepare($payments_query);
    $stmt->execute([$sale_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $response = [
        'success' => true,
        'sale' => $sale,
        'items' => array_map(function($item) {
            return [
                'id' => $item['id'],
                'product_name' => $item['display_name'] ?: $item['product_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'] ?: $item['price'],
                'price' => $item['price'],
                'total_price' => $item['total_price'] ?: ($item['price'] * $item['quantity']),
                'is_auto_bom' => $item['is_auto_bom'] ?? false
            ];
        }, $items),
        'payments' => $payments
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error fetching sale details: ' . $e->getMessage()
    ]);
}
?>
