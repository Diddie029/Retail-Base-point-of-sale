<?php
require_once __DIR__ . '/../include/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['ajax'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    if (!isset($_GET['supplier_id'])) {
        throw new Exception('Supplier ID is required');
    }

    $supplier_id = filter_var($_GET['supplier_id'], FILTER_SANITIZE_NUMBER_INT);

    // Check supplier validation
    $validation = validateOrderCreation($conn, $supplier_id);

    echo json_encode([
        'success' => true,
        'valid' => $validation['valid'],
        'errors' => $validation['errors'],
        'warnings' => $validation['warnings']
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
