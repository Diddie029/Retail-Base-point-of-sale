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
    $conn->beginTransaction();
    
    // Get quotation details
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
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Quotation not found']);
        exit();
    }
    
    // Note: We allow any quotation to be converted to sales
    // The quotation will be automatically approved when converted
    
    // Get quotation items
    $items_query = "
        SELECT 
            qi.*,
            p.name as product_name,
            p.sku,
            p.price as current_price,
            p.quantity as stock_quantity
        FROM quotation_items qi
        LEFT JOIN products p ON qi.product_id = p.id
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id
    ";
    
    $stmt = $conn->prepare($items_query);
    $stmt->execute([':quotation_id' => $quotation['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'No items found in quotation']);
        exit();
    }
    
    // Check stock availability for each item
    $stock_issues = [];
    foreach ($items as $item) {
        if ($item['stock_quantity'] < $item['quantity']) {
            $stock_issues[] = $item['product_name'] . ' (Required: ' . $item['quantity'] . ', Available: ' . $item['stock_quantity'] . ')';
        }
    }
    
    if (!empty($stock_issues)) {
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'error' => 'Insufficient stock for: ' . implode(', ', $stock_issues)
        ]);
        exit();
    }
    
    // Prepare items for cart
    $cart_items = [];
    foreach ($items as $item) {
        $cart_items[] = [
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'unit_price' => $item['unit_price'],
            'quantity' => $item['quantity'],
            'sku' => $item['sku']
        ];
    }
    
    // Don't update quotation status yet - wait until transaction is completed
    // The quotation will be marked as 'converted' when the sale is finalized
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Quotation converted successfully',
        'items' => $cart_items,
        'quotation_id' => $quotation['id'],
        'quotation_number' => $quotation['quotation_number']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
