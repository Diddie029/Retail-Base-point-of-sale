<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';

try {
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

    $query = "SELECT id, name, description, base_unit, default_pricing_strategy, status FROM product_families";
    $params = [];

    if ($status_filter === 'active' || $status_filter === 'inactive') {
        $query .= " WHERE status = :status";
        $params[':status'] = $status_filter;
    }

    $query .= " ORDER BY name ASC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $families = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'families' => $families,
        'count' => count($families)
    ]);

} catch (PDOException $e) {
    error_log("API get_product_families error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred. Please try again.'
    ]);

} catch (Exception $e) {
    error_log("API get_product_families general error: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>
