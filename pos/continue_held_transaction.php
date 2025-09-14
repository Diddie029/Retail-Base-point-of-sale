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
    // Get held transaction ID from request
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);

    if (!$requestData || !isset($requestData['held_transaction_id'])) {
        throw new Exception('Held transaction ID is required');
    }

    $held_transaction_id = intval($requestData['held_transaction_id']);

    // Check if current cart is empty
    $current_cart = $_SESSION['cart'] ?? [];
    if (!empty($current_cart)) {
        throw new Exception('Cart must be empty to continue with held transaction. Please clear the cart first.');
    }

    // Get held transaction
    $stmt = $conn->prepare("
        SELECT ht.*, u.username as cashier_name, rt.till_name
        FROM held_transactions ht
        LEFT JOIN users u ON ht.user_id = u.id
        LEFT JOIN register_tills rt ON ht.till_id = rt.id
        WHERE ht.id = ? AND ht.status = 'held'
    ");
    $stmt->execute([$held_transaction_id]);
    $held_transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$held_transaction) {
        throw new Exception('Held transaction not found or already processed');
    }

    // Decode cart data
    $cartData = json_decode($held_transaction['cart_data'], true);
    if (!$cartData || !isset($cartData['items'])) {
        throw new Exception('Invalid held transaction data');
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Update held transaction status to resumed
        $stmt = $conn->prepare("
            UPDATE held_transactions 
            SET status = 'resumed', updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$held_transaction_id]);

        // Normalize item prices to current product prices and load into session
        $normalizedCart = [];
        $subtotal = 0;
        foreach ($cartData['items'] as $item) {
            $product_id = $item['product_id'] ?? $item['id'] ?? null;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : (isset($item['qty']) ? floatval($item['qty']) : 1);

            $unit_price = null;
            if ($product_id) {
                $pstmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
                $pstmt->execute([$product_id]);
                $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($prod) {
                    $unit_price = (float)getCurrentProductPrice($prod);
                }
            }

            if ($unit_price === null) {
                // Fallback to stored price fields
                if (isset($item['unit_price'])) $unit_price = (float)$item['unit_price'];
                elseif (isset($item['price'])) $unit_price = (float)$item['price'];
                else $unit_price = 0;
            }

            $line_total = $unit_price * $quantity;
            $subtotal += $line_total;

            $normalizedCart[] = [
                'product_id' => $product_id,
                'id' => $product_id,
                'name' => $item['name'] ?? $item['product_name'] ?? '',
                'quantity' => $quantity,
                'price' => $unit_price,
                'unit_price' => $unit_price,
                'line_total' => $line_total,
                'sku' => $item['sku'] ?? ''
            ];
        }

        $tax_rate = 16; // Default tax rate - keep consistent with hold flow
        $tax_amount = $subtotal * ($tax_rate / 100);
        $total_amount = $subtotal + $tax_amount;

        // Load normalized cart into session
        $_SESSION['cart'] = $normalizedCart;

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Held transaction loaded successfully',
            'cart' => $normalizedCart,
            'totals' => [
                'subtotal' => $subtotal,
                'tax' => $tax_amount,
                'total' => $total_amount
            ],
            'held_transaction_info' => [
                'id' => $held_transaction['id'],
                'cashier_name' => $held_transaction['cashier_name'],
                'till_name' => $held_transaction['till_name'],
                'reason' => $held_transaction['reason'],
                'customer_reference' => $held_transaction['customer_reference'],
                'created_at' => $held_transaction['created_at']
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
