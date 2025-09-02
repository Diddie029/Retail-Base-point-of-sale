<?php
/**
 * Auto BOM Inventory Check API
 * Checks if there's enough base stock for Auto BOM selling units
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required'
    ]);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    $auto_bom_manager = new AutoBOMManager($conn, $user_id);

    // Get request parameters
    $request_data = json_decode(file_get_contents('php://input'), true);

    if (!$request_data) {
        // Try GET parameters for backward compatibility
        $request_data = [
            'product_id' => isset($_GET['product_id']) ? (int) $_GET['product_id'] : null,
            'quantity' => isset($_GET['quantity']) ? (float) $_GET['quantity'] : null,
            'selling_unit_id' => isset($_GET['selling_unit_id']) ? (int) $_GET['selling_unit_id'] : null
        ];
    }

    $product_id = $request_data['product_id'] ?? null;
    $quantity = $request_data['quantity'] ?? null;
    $selling_unit_id = $request_data['selling_unit_id'] ?? null;

    if (!$product_id || !$quantity) {
        throw new Exception('Product ID and quantity are required');
    }

    if ($quantity <= 0) {
        throw new Exception('Quantity must be greater than 0');
    }

    // Check inventory availability
    $inventory_check = $auto_bom_manager->checkBaseStockAvailability(
        $product_id,
        $quantity,
        $selling_unit_id
    );

    // Get additional product information
    $stmt = $conn->prepare("
        SELECT p.name, p.sku, p.quantity as current_stock, p.minimum_stock
        FROM products p
        WHERE p.id = :product_id
    ");
    $stmt->execute([':product_id' => $product_id]);
    $product_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product_info) {
        throw new Exception('Product not found');
    }

    // Determine stock status
    $stock_status = 'available';
    $warnings = [];

    if (!$inventory_check['available']) {
        $stock_status = 'insufficient';
        $warnings[] = 'Insufficient stock for the requested quantity';
    }

    if ($inventory_check['available_stock'] <= $product_info['minimum_stock']) {
        $stock_status = 'low_stock';
        $warnings[] = 'Stock level is below minimum threshold';
    }

    // Calculate additional metrics
    $available_quantity = floor($inventory_check['available_stock'] / $inventory_check['required']) * $quantity;

    $response = [
        'success' => true,
        'data' => [
            'product_id' => $product_id,
            'product_name' => $product_info['name'],
            'product_sku' => $product_info['sku'],
            'requested_quantity' => $quantity,
            'selling_unit_id' => $selling_unit_id,
            'stock_check' => $inventory_check,
            'stock_status' => $stock_status,
            'warnings' => $warnings,
            'available_quantity' => $available_quantity,
            'current_stock' => $inventory_check['available_stock'],
            'minimum_stock' => $product_info['minimum_stock'],
            'can_fulfill_request' => $inventory_check['available']
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
