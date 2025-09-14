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
    // Get search parameters
    $search = trim($_GET['q'] ?? $_GET['search'] ?? '');
    $search_type = $_GET['type'] ?? 'all'; // all, name, sku, barcode, category
    $category_id = $_GET['category_id'] ?? '';
    $supplier_id = $_GET['supplier_id'] ?? '';
    $stock_status = $_GET['stock_status'] ?? ''; // all, in_stock, low_stock, out_of_stock
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $include_inactive = $_GET['include_inactive'] ?? 'false';

    // Validate inputs
    if (strlen($search) < 1 && $search_type !== 'all') {
        echo json_encode([
            'success' => true,
            'products' => [],
            'total' => 0,
            'message' => 'Search term too short'
        ]);
        exit();
    }

    $search = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
    
    // Validate numeric parameters
    if ($category_id && !is_numeric($category_id)) $category_id = '';
    if ($supplier_id && !is_numeric($supplier_id)) $supplier_id = '';

    // Build base query
    $query = "
        SELECT p.id, p.name, p.sku, p.barcode, p.price, p.sale_price, p.cost_price,
               p.quantity, p.minimum_stock, p.status, p.image_url, p.description,
               c.id as category_id, c.name as category_name,
               s.id as supplier_id, s.name as supplier_name,
               p.created_at, p.updated_at,
               CASE 
                   WHEN p.quantity <= 0 THEN 'out_of_stock'
                   WHEN p.quantity <= p.minimum_stock THEN 'low_stock'
                   ELSE 'in_stock'
               END as stock_status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE 1=1
    ";

    $params = [];

    // Add status filter
    if ($include_inactive !== 'true') {
        $query .= " AND p.status = 'active'";
    }

    // Add category filter
    if (!empty($category_id)) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }

    // Add supplier filter
    if (!empty($supplier_id)) {
        $query .= " AND p.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }

    // Add stock status filter
    if (!empty($stock_status) && $stock_status !== 'all') {
        switch ($stock_status) {
            case 'in_stock':
                $query .= " AND p.quantity > p.minimum_stock";
                break;
            case 'low_stock':
                $query .= " AND p.quantity <= p.minimum_stock AND p.quantity > 0";
                break;
            case 'out_of_stock':
                $query .= " AND p.quantity <= 0";
                break;
        }
    }

    // Add search conditions
    if (!empty($search)) {
        $searchTerm = '%' . $search . '%';
        
        switch ($search_type) {
            case 'name':
                $query .= " AND p.name LIKE :search";
                $params[':search'] = $searchTerm;
                break;
            case 'sku':
                $query .= " AND p.sku LIKE :search";
                $params[':search'] = $searchTerm;
                break;
            case 'barcode':
                $query .= " AND p.barcode LIKE :search";
                $params[':search'] = $searchTerm;
                break;
            case 'category':
                $query .= " AND c.name LIKE :search";
                $params[':search'] = $searchTerm;
                break;
            case 'all':
            default:
                $query .= " AND (
                    p.name LIKE :search_name
                    OR p.sku LIKE :search_sku
                    OR p.barcode LIKE :search_barcode
                    OR c.name LIKE :search_category
                    OR s.name LIKE :search_supplier
                    OR p.description LIKE :search_description
                )";
                $params[':search_name'] = $searchTerm;
                $params[':search_sku'] = $searchTerm;
                $params[':search_barcode'] = $searchTerm;
                $params[':search_category'] = $searchTerm;
                $params[':search_supplier'] = $searchTerm;
                $params[':search_description'] = $searchTerm;
                break;
        }
    }

    // Add ordering
    $query .= " ORDER BY
        CASE
            WHEN p.name LIKE :exact_name THEN 1
            WHEN p.sku LIKE :exact_sku THEN 2
            WHEN p.barcode LIKE :exact_barcode THEN 3
            WHEN p.name LIKE :starts_name THEN 4
            WHEN p.sku LIKE :starts_sku THEN 5
            WHEN p.barcode LIKE :starts_barcode THEN 6
            ELSE 7
        END,
        p.name ASC
        LIMIT :limit";

    // Add exact match parameters for better ordering
    if (!empty($search)) {
        $params[':exact_name'] = $search;
        $params[':exact_sku'] = $search;
        $params[':exact_barcode'] = $search;
        $params[':starts_name'] = $search . '%';
        $params[':starts_sku'] = $search . '%';
        $params[':starts_barcode'] = $search . '%';
    } else {
        $params[':exact_name'] = '';
        $params[':exact_sku'] = '';
        $params[':exact_barcode'] = '';
        $params[':starts_name'] = '';
        $params[':starts_sku'] = '';
        $params[':starts_barcode'] = '';
    }

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
    $formatted_products = array_map(function($product) {
        $current_price = getCurrentProductPrice($product);
        $is_on_sale = isProductOnSale($product);
        
        return [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'] ?? '',
            'barcode' => $product['barcode'] ?? '',
            'price' => floatval($current_price),
            'regular_price' => floatval($product['price']),
            'sale_price' => floatval($product['sale_price'] ?? 0),
            'cost_price' => floatval($product['cost_price'] ?? 0),
            'quantity' => (int)$product['quantity'],
            'minimum_stock' => (int)($product['minimum_stock'] ?? 0),
            'stock_status' => $product['stock_status'],
            'category_id' => (int)($product['category_id'] ?? 0),
            'category_name' => $product['category_name'] ?? '',
            'supplier_id' => (int)($product['supplier_id'] ?? 0),
            'supplier_name' => $product['supplier_name'] ?? '',
            'image_url' => $product['image_url'] ?? '',
            'description' => $product['description'] ?? '',
            'status' => $product['status'],
            'is_on_sale' => $is_on_sale,
            'is_out_of_stock' => $product['quantity'] <= 0,
            'display_text' => $product['name'] . 
                ($product['sku'] ? ' (SKU: ' . $product['sku'] . ')' : '') . 
                ($product['barcode'] ? ' [' . $product['barcode'] . ']' : ''),
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ];
    }, $products);

    // Get total count for pagination
    $count_query = str_replace("SELECT p.id, p.name, p.sku, p.barcode, p.price, p.sale_price, p.cost_price,
               p.quantity, p.minimum_stock, p.status, p.image_url, p.description,
               c.id as category_id, c.name as category_name,
               s.id as supplier_id, s.name as supplier_name,
               p.created_at, p.updated_at,
               CASE 
                   WHEN p.quantity <= 0 THEN 'out_of_stock'
                   WHEN p.quantity <= p.minimum_stock THEN 'low_stock'
                   ELSE 'in_stock'
               END as stock_status", "SELECT COUNT(*)", $query);
    $count_query = preg_replace('/ORDER BY.*$/', '', $count_query);
    $count_query = preg_replace('/LIMIT.*$/', '', $count_query);
    
    $count_stmt = $conn->prepare($count_query);
    foreach ($params as $key => $value) {
        if ($key !== ':limit') {
            $count_stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'products' => $formatted_products,
        'total' => (int)$total_count,
        'returned' => count($formatted_products),
        'search_type' => $search_type,
        'filters' => [
            'category_id' => $category_id,
            'supplier_id' => $supplier_id,
            'stock_status' => $stock_status,
            'include_inactive' => $include_inactive === 'true'
        ]
    ]);

} catch (PDOException $e) {
    error_log("Enhanced product search error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred. Please try again.',
        'products' => []
    ]);

} catch (Exception $e) {
    error_log("Enhanced product search general error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.',
        'products' => []
    ]);
}
?>
