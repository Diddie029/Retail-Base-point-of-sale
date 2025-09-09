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
    // Sanitize and validate input
    $index = filter_var($_POST['index'] ?? -1, FILTER_VALIDATE_INT);
    $change = filter_var($_POST['change'] ?? 0, FILTER_VALIDATE_INT);
    
    // Additional validation
    if ($index === false || $change === false) {
        throw new Exception('Invalid input parameters');
    }
    
    if ($index < 0 || $index >= count($_SESSION['cart'] ?? [])) {
        throw new Exception('Invalid cart item index');
    }
    
    if (!isset($_SESSION['cart'][$index])) {
        throw new Exception('Cart item not found');
    }

    $cart = $_SESSION['cart'];
    $item = $cart[$index];
    $new_quantity = $item['quantity'] + $change;

    // Validate quantity range
    if ($new_quantity < 0) {
        throw new Exception('Quantity cannot be negative');
    }
    
    if ($new_quantity > 999) {
        throw new Exception('Maximum quantity allowed is 999');
    }

    if ($new_quantity <= 0) {
        // Remove item from cart
        unset($cart[$index]);
        $cart = array_values($cart); // Reindex array
    } else {
        // Check stock availability if tracking inventory
        if (isset($item['product_id']) && $item['product_id']) {
            $stmt = $conn->prepare("SELECT quantity, track_inventory FROM products WHERE id = ?");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product && $product['track_inventory'] && $product['quantity'] < $new_quantity) {
                throw new Exception('Insufficient stock. Available: ' . $product['quantity']);
            }
        }
        
        // Update quantity
        $cart[$index]['quantity'] = $new_quantity;
    }

    // Update session cart
    $_SESSION['cart'] = $cart;

    // Return success response with updated cart
    echo json_encode([
        'success' => true,
        'cart' => $cart,
        'message' => 'Cart updated successfully'
    ]);

} catch (Exception $e) {
    error_log("Update cart item error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
