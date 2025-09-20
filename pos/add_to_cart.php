<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

try {
    // Sanitize and validate input
    $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    $quantity_raw = $_POST['quantity'] ?? 1;

    // Handle decimal quantities by converting to integer (floor value)
    if (is_numeric($quantity_raw)) {
        $quantity = (int)floor((float)$quantity_raw);
    } else {
        $quantity = filter_var($quantity_raw, FILTER_VALIDATE_INT);
    }

    // Debug logging
    error_log("add_to_cart.php called with product_id: $product_id, quantity_raw: $quantity_raw, quantity: $quantity");

    if ($product_id === false || $quantity <= 0) {
        error_log("Invalid input parameters: product_id=$product_id, quantity=$quantity, quantity_raw=$quantity_raw");
        throw new Exception('Invalid input parameters');
    }
    
    if (!$product_id) {
        throw new Exception('Product ID is required');
    }
    
    // Validate quantity range
    if ($quantity < 1 || $quantity > 999) {
        throw new Exception('Quantity must be between 1 and 999');
    }

    // Get product details
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ? AND p.status = 'active'
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Product query result for ID $product_id: " . ($product ? 'found' : 'not found'));
    if ($product) {
        error_log("Product details: " . json_encode($product));
    }

    if (!$product) {
        throw new Exception('Product not found or inactive');
    }

    // Check stock availability
    if ($product['track_inventory'] && $product['quantity'] < $quantity) {
        throw new Exception('Insufficient stock. Available: ' . $product['quantity']);
    }

    // Initialize cart if not exists
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $cart = $_SESSION['cart'];

    // Check if product already in cart
    $existing_index = null;
    foreach ($cart as $index => $item) {
        if ($item['product_id'] == $product_id) {
            $existing_index = $index;
            break;
        }
    }

    if ($existing_index !== null) {
        // Update existing item quantity
        $new_quantity = $cart[$existing_index]['quantity'] + $quantity;
        
        // Check stock again
        if ($product['track_inventory'] && $product['quantity'] < $new_quantity) {
            throw new Exception('Insufficient stock. Available: ' . $product['quantity']);
        }
        
        $cart[$existing_index]['quantity'] = $new_quantity;
    } else {
        // Add new item to cart
        $cart_item = [
            'product_id' => $product['id'],
            'name' => $product['name'],
            // Use helper to determine current product price (sale price if applicable)
            'price' => floatval(getCurrentProductPrice($product)),
            'quantity' => $quantity,
            'category_name' => $product['category_name'],
            'image_url' => $product['image_url'],
            'sku' => $product['sku']
        ];
        
        $cart[] = $cart_item;
    }

    // Update session cart
    $_SESSION['cart'] = $cart;

    // Return success response with updated cart
    echo json_encode([
        'success' => true,
        'cart' => $cart,
        'message' => 'Product added to cart successfully'
    ]);

} catch (Exception $e) {
    error_log("Add to cart error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
