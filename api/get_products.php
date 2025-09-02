<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';

try {
    // Handle both GET and POST requests
    $requestData = $_GET;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestData = array_merge($requestData, $_POST);
    }

    $search = $requestData['search'] ?? '';
    $searchType = $requestData['search_type'] ?? 'all';
    $supplierId = $requestData['supplier_id'] ?? '';
    $statusFilter = $requestData['status_filter'] ?? '';
    $excludeBlocked = $requestData['exclude_blocked'] ?? 'false';
    $categoryId = $requestData['category_id'] ?? '';
    $productFamilyId = $requestData['product_family_id'] ?? '';
    $enableVarieties = $requestData['enable_varieties'] ?? 'false';
    $selectedProducts = $requestData['selected_products'] ?? '';

    // Validate and sanitize inputs
    $search = trim($search);
    $search = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');

    $validSearchTypes = ['all', 'name', 'sku', 'barcode', 'supplier'];
    if (!in_array($searchType, $validSearchTypes)) {
        $searchType = 'all';
    }

    // Validate supplier_id if provided
    if ($supplierId && !is_numeric($supplierId)) {
        $supplierId = '';
    }

    // Validate category_id if provided
    if ($categoryId && !is_numeric($categoryId)) {
        $categoryId = '';
    }

    // Validate product_family_id if provided
    if ($productFamilyId && !is_numeric($productFamilyId)) {
        $productFamilyId = '';
    }

    // Build query
    $query = "
        SELECT p.id, p.name, p.sku, p.barcode, p.quantity, p.minimum_stock, p.cost_price, p.price as selling_price,
               c.name as category_name, s.name as supplier_name, pf.name as product_family_name,
               p.product_family_id, p.category_id, p.supplier_id
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN product_families pf ON p.product_family_id = pf.id
        WHERE 1=1
    ";

    $params = [];

    // Add supplier filter if provided (required for order creation)
    if (!empty($supplierId)) {
        $query .= " AND p.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplierId;
    } elseif (isset($requestData['require_supplier']) && $requestData['require_supplier'] === 'true') {
        // If supplier is required but not provided, return empty result
        $query .= " AND 1=0"; // This will return no results
    }

    // Add status filter
    if (!empty($statusFilter)) {
        if ($statusFilter === 'active') {
            $query .= " AND p.status = 'active'";
        } elseif ($statusFilter === 'inactive') {
            $query .= " AND p.status = 'inactive'";
        }
    }

    // Exclude blocked products if requested
    if ($excludeBlocked === 'true') {
        $query .= " AND p.status != 'blocked'";
    }

    // Add category filter if provided
    if (!empty($categoryId)) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $categoryId;
    }

    // Add product family filter if provided
    if (!empty($productFamilyId)) {
        $query .= " AND p.product_family_id = :product_family_id";
        $params[':product_family_id'] = $productFamilyId;
    }

    // Add selected products filter if provided (for multiple selection)
    if (!empty($selectedProducts)) {
        $productIds = explode(',', $selectedProducts);
        $productIds = array_filter(array_map('intval', $productIds));
        if (!empty($productIds)) {
            $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
            $query .= " AND p.id IN ($placeholders)";
            $params = array_merge($params, $productIds);
        }
    }

    // Ensure products have valid cost_price for order creation
    $query .= " AND p.cost_price IS NOT NULL AND p.cost_price > 0 AND p.cost_price REGEXP '^[0-9]+(\\.[0-9]{1,2})?$'";

    if (!empty($search)) {
        $searchTerm = '%' . $search . '%';
        $params[':search'] = $searchTerm;

        if ($searchType === 'name') {
            $query .= " AND p.name LIKE :search";
        } elseif ($searchType === 'sku') {
            $query .= " AND p.sku LIKE :search";
        } elseif ($searchType === 'barcode') {
            $query .= " AND p.barcode LIKE :search";
        } elseif ($searchType === 'supplier') {
            $query .= " AND (s.name LIKE :search OR p.supplier_id IN (SELECT id FROM suppliers WHERE name LIKE :search))";
        } else {
            // Default 'all' search
            $query .= " AND (
                p.name LIKE :search
                OR p.sku LIKE :search
                OR p.barcode LIKE :search
                OR p.description LIKE :search
                OR c.name LIKE :search
                OR s.name LIKE :search
            )";
        }
    }

    $query .= " ORDER BY p.name LIMIT 50";

    $stmt = $conn->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $formatted_products = array_map(function($product) {
        return [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'] ?? '',
            'barcode' => $product['barcode'] ?? '',
            'quantity' => (int)$product['quantity'],
            'minimum_stock' => (int)($product['minimum_stock'] ?? 0),
            'cost_price' => (float)$product['cost_price'],
            'selling_price' => (float)$product['selling_price'],
            'category_name' => $product['category_name'] ?? '',
            'supplier_name' => $product['supplier_name'] ?? '',
            'product_family_name' => $product['product_family_name'] ?? '',
            'category_id' => (int)($product['category_id'] ?? 0),
            'product_family_id' => (int)($product['product_family_id'] ?? 0),
            'supplier_id' => (int)($product['supplier_id'] ?? 0)
        ];
    }, $products);

    echo json_encode([
        'success' => true,
        'products' => $formatted_products
    ]);

} catch (PDOException $e) {
    error_log("API get_products error: " . $e->getMessage());

    // Provide user-friendly error messages
    $user_message = "Database error occurred. Please try again.";
    if (strpos($e->getMessage(), 'connection') !== false) {
        $user_message = "Database connection error. Please check your connection.";
    } elseif (strpos($e->getMessage(), 'syntax') !== false) {
        $user_message = "Query error. Please contact support.";
    }

    echo json_encode([
        'success' => false,
        'error' => $user_message
    ]);

} catch (Exception $e) {
    error_log("API get_products general error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
