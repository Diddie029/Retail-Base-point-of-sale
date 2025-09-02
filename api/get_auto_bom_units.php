<?php
/**
 * Get Auto BOM Selling Units API
 * Returns available selling units for a base product
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

// Check if user is logged in (optional for API access)
$user_id = $_SESSION['user_id'] ?? null;

// Get request parameters
$base_product_id = isset($_GET['base_product_id']) ? (int) $_GET['base_product_id'] : null;
$include_inactive = isset($_GET['include_inactive']) ? (bool) $_GET['include_inactive'] : false;
$calculate_prices = isset($_GET['calculate_prices']) ? (bool) $_GET['calculate_prices'] : true;

try {
    if (!$base_product_id) {
        throw new Exception('Base product ID is required');
    }

    $auto_bom_manager = new AutoBOMManager($conn, $user_id);

    // Get available selling units
    $selling_units = $auto_bom_manager->getAvailableSellingUnits($base_product_id);

    // Filter out inactive units unless requested
    if (!$include_inactive) {
        $selling_units = array_filter($selling_units, function($unit) {
            return $unit['status'] === 'active' && $unit['is_active'] == 1;
        });
    }

    // Calculate prices if requested
    if ($calculate_prices) {
        foreach ($selling_units as &$unit) {
            try {
                $unit['calculated_price'] = $auto_bom_manager->calculateSellingUnitPrice($unit['id']);
                $unit['formatted_price'] = formatCurrency($unit['calculated_price']);
            } catch (Exception $e) {
                $unit['calculated_price'] = null;
                $unit['formatted_price'] = 'Price unavailable';
                $unit['price_error'] = $e->getMessage();
            }
        }
    }

    // Format response
    $response = [
        'success' => true,
        'data' => [
            'base_product_id' => $base_product_id,
            'selling_units' => array_values($selling_units),
            'total_units' => count($selling_units)
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
