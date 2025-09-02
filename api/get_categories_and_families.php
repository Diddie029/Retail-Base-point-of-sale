<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';

try {
    // Get categories
    $stmt = $conn->query("
        SELECT id, name, description, status
        FROM categories
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get product families
    $stmt = $conn->query("
        SELECT id, name, description, status
        FROM product_families
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $product_families = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'product_families' => $product_families
    ]);

} catch (PDOException $e) {
    error_log("API get_categories_and_families error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred. Please try again.'
    ]);

} catch (Exception $e) {
    error_log("API get_categories_and_families general error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
