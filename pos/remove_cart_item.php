<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

try {
    $index = intval($_POST['index'] ?? -1);

    if ($index < 0 || !isset($_SESSION['cart'][$index])) {
        throw new Exception('Invalid cart item index');
    }

    $cart = $_SESSION['cart'];
    
    // Remove item from cart
    unset($cart[$index]);
    $cart = array_values($cart); // Reindex array

    // Update session cart
    $_SESSION['cart'] = $cart;

    // Return success response with updated cart
    echo json_encode([
        'success' => true,
        'cart' => $cart,
        'message' => 'Item removed from cart successfully'
    ]);

} catch (Exception $e) {
    error_log("Remove cart item error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
