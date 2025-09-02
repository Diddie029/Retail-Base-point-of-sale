<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';

try {
    // Get search parameters
    $search = $_GET['search'] ?? '';
    $supplier_id = $_GET['supplier_id'] ?? '';
    $limit = min(10, max(1, intval($_GET['limit'] ?? 10))); // Default 10, max 10 suggestions

    // Validate inputs
    $search = trim($search);
    if (strlen($search) < 2) {
        echo json_encode([
            'success' => true,
            'suggestions' => []
        ]);
        exit();
    }

    $search = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
    
    // Validate supplier_id if provided
    if ($supplier_id && !is_numeric($supplier_id)) {
        $supplier_id = '';
    }

    // Build query for product suggestions
    $query = "
        SELECT p.id, p.name, p.sku, p.barcode, p.quantity, p.minimum_stock, p.cost_price,
               c.name as category_name, s.name as supplier_name,
               p.is_auto_bom_enabled, p.auto_bom_type,
               COUNT(CASE WHEN su.status = 'active' THEN 1 END) as selling_units_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
        LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
        WHERE p.status = 'active'
        AND p.cost_price IS NOT NULL
        AND p.cost_price > 0
    ";

    $params = [];

    // Add supplier filter if provided (required for order creation)
    if (!empty($supplier_id)) {
        $query .= " AND p.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }

    // Search in name, SKU, and barcode
    $searchTerm = '%' . $search . '%';
    $query .= " AND (
        p.name LIKE :search_name
        OR p.sku LIKE :search_sku
        OR p.barcode LIKE :search_barcode
    )";
    
    $params[':search_name'] = $searchTerm;
    $params[':search_sku'] = $searchTerm;
    $params[':search_barcode'] = $searchTerm;

    $query .= " GROUP BY p.id ORDER BY
        CASE
            WHEN p.name LIKE :exact_name THEN 1
            WHEN p.sku LIKE :exact_sku THEN 2
            WHEN p.barcode LIKE :exact_barcode THEN 3
            WHEN p.name LIKE :starts_name THEN 4
            WHEN p.sku LIKE :starts_sku THEN 5
            ELSE 6
        END,
        p.name ASC
        LIMIT :limit";

    $params[':exact_name'] = $search;
    $params[':exact_sku'] = $search;
    $params[':exact_barcode'] = $search;
    $params[':starts_name'] = $search . '%';
    $params[':starts_sku'] = $search . '%';
    $params[':limit'] = $limit;

    $stmt = $conn->prepare($query);
    
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $suggestions = array_map(function($product) {
        $stockStatus = $product['quantity'] <= $product['minimum_stock'] ? 'low' : 'ok';

        return [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'] ?? '',
            'barcode' => $product['barcode'] ?? '',
            'quantity' => (int)$product['quantity'],
            'minimum_stock' => (int)($product['minimum_stock'] ?? 0),
            'cost_price' => (float)$product['cost_price'],
            'category_name' => $product['category_name'] ?? '',
            'supplier_name' => $product['supplier_name'] ?? '',
            'stock_status' => $stockStatus,
            'is_auto_bom_enabled' => (bool)$product['is_auto_bom_enabled'],
            'auto_bom_type' => $product['auto_bom_type'] ?? null,
            'selling_units_count' => (int)$product['selling_units_count'],
            'display_text' => $product['name'] .
                            ($product['sku'] ? ' (SKU: ' . $product['sku'] . ')' : '') .
                            ($product['barcode'] ? ' [' . $product['barcode'] . ']' : '') .
                            ($product['is_auto_bom_enabled'] ? ' [Auto BOM - ' . $product['selling_units_count'] . ' units]' : '')
        ];
    }, $products);

    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions,
        'total_found' => count($suggestions)
    ]);

} catch (PDOException $e) {
    error_log("API search_products error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred. Please try again.',
        'suggestions' => []
    ]);

} catch (Exception $e) {
    error_log("API search_products general error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.',
        'suggestions' => []
    ]);
}
?>
