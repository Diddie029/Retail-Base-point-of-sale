<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

require_once '../include/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$quotation_number = $input['quotation_number'] ?? '';

if (empty($quotation_number)) {
    echo json_encode(['success' => false, 'error' => 'Quotation number is required']);
    exit();
}

try {
    // Search for quotation with customer and items
    $quotation_query = "
        SELECT 
            q.*,
            q.quotation_status as status,
            CONCAT(c.first_name, ' ', c.last_name) as customer_name,
            c.phone as customer_phone
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        WHERE q.quotation_number = :quotation_number
    ";
    
    $stmt = $conn->prepare($quotation_query);
    $stmt->execute([':quotation_number' => $quotation_number]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        echo json_encode(['success' => false, 'error' => 'Quotation not found']);
        exit();
    }
    
    // Get quotation items
    $items_query = "
        SELECT 
            qi.*,
            p.name as product_name,
            p.sku,
            p.price as current_price
        FROM quotation_items qi
        LEFT JOIN products p ON qi.product_id = p.id
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id
    ";
    
    $stmt = $conn->prepare($items_query);
    $stmt->execute([':quotation_id' => $quotation['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add items to quotation data
    $quotation['items'] = $items;
    
    echo json_encode([
        'success' => true,
        'quotation' => $quotation
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
