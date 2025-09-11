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

        // Load cart data into session
        $_SESSION['cart'] = $cartData['items'];

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Held transaction loaded successfully',
            'cart' => $cartData['items'],
            'totals' => $cartData['totals'],
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
