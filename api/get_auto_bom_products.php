<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    // Get search parameters
    $search = $_GET['search'] ?? '';
    $category_id = $_GET['category_id'] ?? '';
    $limit = min(50, (int)($_GET['limit'] ?? 20));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    // Build query for Auto BOM products
    $where_conditions = ['p.auto_bom_type IS NOT NULL', 'p.status = "active"'];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.description LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if (!empty($category_id)) {
        $where_conditions[] = "p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

    $query = "
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.description,
            p.selling_price,
            p.cost_price,
            p.quantity,
            p.auto_bom_type,
            c.name as category_name,
            b.name as brand_name,
            abc.config_name,
            abc.base_product_id,
            bp.name as base_product_name,
            COUNT(su.id) as selling_units_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
        LEFT JOIN products bp ON abc.base_product_id = bp.id
        LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id AND su.status = 'active'
        {$where_clause}
        GROUP BY p.id
        ORDER BY p.name ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $conn->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $count_query = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
        {$where_clause}
    ";
    
    $count_stmt = $conn->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Format response
    $response = [
        'success' => true,
        'data' => $products,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
