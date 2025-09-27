<?php
session_start();
require_once '../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    $conn = $GLOBALS['conn'];
    $query = $_GET['q'] ?? '';
    $productId = $_GET['product_id'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 20), 50); // Max 50 results
    
    // Handle single product lookup
    if ($productId) {
        $stmt = $conn->prepare("
            SELECT 
                p.id,
                p.name,
                p.sku,
                p.barcode,
                p.price,
                p.quantity,
                c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.id = :product_id
        ");
        $stmt->bindParam(':product_id', $productId);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $formattedProduct = [
                'id' => $product['id'],
                'name' => $product['name'],
                'sku' => $product['sku'] ?: 'N/A',
                'barcode' => $product['barcode'] ?: 'N/A',
                'price' => number_format($product['price'], 2),
                'quantity' => $product['quantity'],
                'category' => $product['category_name'] ?: 'Uncategorized',
                'display_text' => $product['name'] . ' (' . ($product['sku'] ?: 'N/A') . ')'
            ];
            echo json_encode(['products' => [$formattedProduct]]);
        } else {
            echo json_encode(['products' => []]);
        }
        exit();
    }
    
    if (strlen($query) < 2) {
        echo json_encode(['products' => []]);
        exit();
    }
    
    // Search products by name, SKU, or barcode
    $searchTerm = '%' . $query . '%';
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.barcode,
            p.price,
            p.quantity,
            c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE (
            p.name LIKE :search 
            OR p.sku LIKE :search 
            OR p.barcode LIKE :search
        )
        AND p.status = 'active'
        ORDER BY 
            CASE 
                WHEN p.name LIKE :exact THEN 1
                WHEN p.sku LIKE :exact THEN 2
                WHEN p.barcode LIKE :exact THEN 3
                ELSE 4
            END,
            p.name
        LIMIT :limit
    ");
    
    $exactTerm = $query . '%';
    $stmt->bindParam(':search', $searchTerm);
    $stmt->bindParam(':exact', $exactTerm);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format products for display
    $formattedProducts = [];
    foreach ($products as $product) {
        $formattedProducts[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'] ?: 'N/A',
            'barcode' => $product['barcode'] ?: 'N/A',
            'price' => number_format($product['price'], 2),
            'quantity' => $product['quantity'],
            'category' => $product['category_name'] ?: 'Uncategorized',
            'display_text' => $product['name'] . ' (' . ($product['sku'] ?: 'N/A') . ')'
        ];
    }
    
    echo json_encode(['products' => $formattedProducts]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>