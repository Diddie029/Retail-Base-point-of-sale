<?php
// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Check POS authentication
if (!isPOSAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'POS authentication required']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get user information
$user_id = $_SESSION['user_id'];
$till_id = $_SESSION['selected_till_id'] ?? null;

try {
    // Get void data from request
    $input = file_get_contents('php://input');
    $voidData = json_decode($input, true);

    if (!$voidData) {
        throw new Exception('Invalid void data');
    }

    // Validate required fields
    $requiredFields = ['void_reason'];
    foreach ($requiredFields as $field) {
        if (!isset($voidData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Get cart data
    $cart = $_SESSION['cart'] ?? [];
    
    if (empty($cart)) {
        throw new Exception('Cart is empty. Nothing to void.');
    }

    $void_reason = trim($voidData['void_reason']);

    if (empty($void_reason)) {
        throw new Exception('Void reason is required');
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Record void transaction for each item in cart
        $stmt = $conn->prepare("
            INSERT INTO void_transactions (
                user_id, till_id, void_type, product_id, product_name, 
                quantity, unit_price, total_amount, void_reason
            ) VALUES (?, ?, 'cart', ?, ?, ?, ?, ?, ?)
        ");

        $total_voided_amount = 0;
        
        foreach ($cart as $item) {
            $product_id = $item['product_id'] ?? $item['id'] ?? null;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 1;
            $unit_price = isset($item['price']) ? (float)$item['price'] : null;

            if ($product_id) {
                $pstmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
                $pstmt->execute([$product_id]);
                $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($prod) {
                    $unit_price = (float)getCurrentProductPrice($prod);
                }
            }

            $unit_price = $unit_price ?? 0;
            $item_total = $unit_price * $quantity;
            $total_voided_amount += $item_total;

            $stmt->execute([
                $user_id,
                $till_id,
                $product_id,
                $item['name'] ?? $item['product_name'] ?? '',
                $quantity,
                $unit_price,
                $item_total,
                $void_reason
            ]);
        }

        // Clear cart
        $_SESSION['cart'] = [];

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Cart voided successfully',
            'voided_items' => count($cart),
            'voided_amount' => $total_voided_amount,
            'cart' => [],
            'totals' => [
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>
