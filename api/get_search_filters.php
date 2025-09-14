<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';

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
    // Get categories
    $stmt = $conn->query("
        SELECT id, name, description, parent_id, status
        FROM categories 
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get suppliers
    $stmt = $conn->query("
        SELECT id, name, contact_person, email, phone, address, status
        FROM suppliers 
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get stock status options
    $stock_status_options = [
        ['value' => 'all', 'label' => 'All Products'],
        ['value' => 'in_stock', 'label' => 'In Stock'],
        ['value' => 'low_stock', 'label' => 'Low Stock'],
        ['value' => 'out_of_stock', 'label' => 'Out of Stock']
    ];

    // Get search type options
    $search_type_options = [
        ['value' => 'all', 'label' => 'All Fields'],
        ['value' => 'name', 'label' => 'Product Name'],
        ['value' => 'sku', 'label' => 'SKU'],
        ['value' => 'barcode', 'label' => 'Barcode'],
        ['value' => 'category', 'label' => 'Category']
    ];

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'suppliers' => $suppliers,
        'stock_status_options' => $stock_status_options,
        'search_type_options' => $search_type_options
    ]);

} catch (Exception $e) {
    error_log("Get search filters error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load search filters'
    ]);
}
?>
