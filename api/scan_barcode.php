<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get barcode from request
    $barcode = trim($_GET['barcode'] ?? $_POST['barcode'] ?? '');
    
    if (empty($barcode)) {
        throw new Exception('Barcode is required');
    }
    
    // Sanitize barcode input
    $barcode = htmlspecialchars($barcode, ENT_QUOTES, 'UTF-8');
    
    // Search for product by exact barcode match
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.barcode = ? AND p.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        // Try partial match if exact match fails
        $stmt = $conn->prepare("
            SELECT p.*, c.name as category_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.barcode LIKE ? AND p.status = 'active'
            ORDER BY 
                CASE 
                    WHEN p.barcode = ? THEN 1
                    WHEN p.barcode LIKE ? THEN 2
                    ELSE 3
                END,
                p.name ASC
            LIMIT 5
        ");
        $searchTerm = '%' . $barcode . '%';
        $stmt->execute([$searchTerm, $barcode, $barcode . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            throw new Exception('No product found with barcode: ' . $barcode);
        }
        
        // Return multiple matches for user selection
        $formatted_products = array_map(function($product) {
            return [
                'id' => (int)$product['id'],
                'name' => $product['name'],
                'sku' => $product['sku'] ?? '',
                'barcode' => $product['barcode'] ?? '',
                'price' => floatval(getCurrentProductPrice($product)),
                'regular_price' => floatval($product['price']),
                'sale_price' => floatval($product['sale_price'] ?? 0),
                'quantity' => (int)$product['quantity'],
                'category_name' => $product['category_name'] ?? '',
                'image_url' => $product['image_url'] ?? '',
                'is_on_sale' => isProductOnSale($product),
                'is_out_of_stock' => $product['quantity'] <= 0,
                'display_text' => $product['name'] . 
                    ($product['sku'] ? ' (SKU: ' . $product['sku'] . ')' : '') . 
                    ' [' . $product['barcode'] . ']'
            ];
        }, $products);
        
        echo json_encode([
            'success' => true,
            'exact_match' => false,
            'products' => $formatted_products,
            'message' => 'Multiple products found with similar barcode'
        ]);
        exit();
    }
    
    // Single exact match found
    $formatted_product = [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'sku' => $product['sku'] ?? '',
        'barcode' => $product['barcode'] ?? '',
        'price' => floatval(getCurrentProductPrice($product)),
        'regular_price' => floatval($product['price']),
        'sale_price' => floatval($product['sale_price'] ?? 0),
        'quantity' => (int)$product['quantity'],
        'category_name' => $product['category_name'] ?? '',
        'image_url' => $product['image_url'] ?? '',
        'is_on_sale' => isProductOnSale($product),
        'is_out_of_stock' => $product['quantity'] <= 0,
        'display_text' => $product['name'] . 
            ($product['sku'] ? ' (SKU: ' . $product['sku'] . ')' : '') . 
            ' [' . $product['barcode'] . ']'
    ];
    
    echo json_encode([
        'success' => true,
        'exact_match' => true,
        'product' => $formatted_product,
        'message' => 'Product found'
    ]);

} catch (Exception $e) {
    error_log("Barcode scan error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
