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
    // Get hold data from request
    $input = file_get_contents('php://input');
    $holdData = json_decode($input, true);

    if (!$holdData) {
        throw new Exception('Invalid hold data');
    }

    // Validate required fields
    $requiredFields = ['reason'];
    foreach ($requiredFields as $field) {
        if (!isset($holdData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Get cart data
    $cart = $_SESSION['cart'] ?? [];
    
    if (empty($cart)) {
        throw new Exception('Cart is empty. Nothing to hold.');
    }

    $reason = trim($holdData['reason']);
    $customer_reference = trim($holdData['customer_reference'] ?? '');

    if (empty($reason)) {
        throw new Exception('Hold reason is required');
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($cart as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    $tax_rate = 16; // Default tax rate - could be made configurable
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_amount = $subtotal + $tax_amount;

    // Prepare cart data with totals
    $cartData = [
        'items' => $cart,
        'totals' => [
            'subtotal' => $subtotal,
            'tax' => $tax_amount,
            'total' => $total_amount
        ],
        'customer_reference' => $customer_reference,
        'hold_reason' => $reason,
        'held_at' => date('Y-m-d H:i:s')
    ];

    // Start transaction
    $conn->beginTransaction();

    try {
        // Insert held transaction
        $stmt = $conn->prepare("
            INSERT INTO held_transactions (
                user_id, till_id, cart_data, reason, customer_reference, status
            ) VALUES (?, ?, ?, ?, ?, 'held')
        ");

        $stmt->execute([
            $user_id,
            $till_id,
            json_encode($cartData),
            $reason,
            $customer_reference
        ]);

        $held_transaction_id = $conn->lastInsertId();

        // Clear cart
        $_SESSION['cart'] = [];

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Transaction held successfully',
            'held_transaction_id' => $held_transaction_id,
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
