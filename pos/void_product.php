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
    $requiredFields = ['cart_index', 'void_reason'];
    foreach ($requiredFields as $field) {
        if (!isset($voidData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Get cart data
    $cart = $_SESSION['cart'] ?? [];
    $cart_index = intval($voidData['cart_index']);
    
    if (!isset($cart[$cart_index])) {
        throw new Exception('Invalid cart item index');
    }

    $cart_item = $cart[$cart_index];
    $void_reason = trim($voidData['void_reason']);

    if (empty($void_reason)) {
        throw new Exception('Void reason is required');
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Record void transaction
        $stmt = $conn->prepare("
            INSERT INTO void_transactions (
                user_id, till_id, void_type, product_id, product_name, 
                quantity, unit_price, total_amount, void_reason
            ) VALUES (?, ?, 'product', ?, ?, ?, ?, ?, ?)
        ");

        // Re-evaluate unit price using current product price if available
        $product_id = $cart_item['product_id'] ?? $cart_item['id'] ?? null;
        $unit_price = isset($cart_item['price']) ? (float)$cart_item['price'] : null;
        if ($product_id) {
            $pstmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
            $pstmt->execute([$product_id]);
            $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $unit_price = (float)getCurrentProductPrice($prod);
            }
        }

        $quantity = isset($cart_item['quantity']) ? (float)$cart_item['quantity'] : (isset($cart_item['qty']) ? floatval($cart_item['qty']) : 1);
        $total_amount = $unit_price * $quantity;

        $stmt->execute([
            $user_id,
            $till_id,
            $product_id,
            $cart_item['name'],
            $quantity,
            $unit_price,
            $total_amount,
            $void_reason
        ]);

        // Remove item from cart
        unset($cart[$cart_index]);
        $cart = array_values($cart); // Re-index array
        $_SESSION['cart'] = $cart;

        $conn->commit();

        // Calculate new totals
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $tax_rate = 16; // Default tax rate
        $tax_amount = $subtotal * ($tax_rate / 100);
        $total_amount = $subtotal + $tax_amount;

        echo json_encode([
            'success' => true,
            'message' => 'Product voided successfully',
            'cart' => $cart,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax_amount,
                'total' => $total_amount
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
