<?php
/**
 * Auto BOM Price Calculation API
 * Calculates prices for Auto BOM selling units based on strategy
 */

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

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
    // Try to get user_id from POST data as fallback
    $user_id = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
        $user_id = (int) $_POST['user_id'];
    } elseif (isset($_GET['user_id'])) {
        $user_id = (int) $_GET['user_id'];
    }
    
    if (!$user_id) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required. Please log in again.',
            'redirect' => '../auth/login.php',
            'debug' => [
                'session_id' => session_id(),
                'session_data' => $_SESSION,
                'cookies' => $_COOKIE
            ]
        ]);
        exit();
    }
    
    // Set session for this request
    $_SESSION['user_id'] = $user_id;
}

$user_id = $_SESSION['user_id'];

try {
    $auto_bom_manager = new AutoBOMManager($conn, $user_id);

    // Get request parameters - handle both JSON and form data
    $request_data = json_decode(file_get_contents('php://input'), true);

    if (!$request_data) {
        // Try POST form data
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
            $request_data = [
                'selling_unit_id' => isset($_POST['selling_unit_id']) ? (int) $_POST['selling_unit_id'] : null,
                'quantity' => isset($_POST['quantity']) ? (float) $_POST['quantity'] : 1,
                'additional_data' => []
            ];
        } else {
            // Try GET parameters for backward compatibility
            $request_data = [
                'selling_unit_id' => isset($_GET['selling_unit_id']) ? (int) $_GET['selling_unit_id'] : null,
                'quantity' => isset($_GET['quantity']) ? (float) $_GET['quantity'] : 1,
                'additional_data' => []
            ];
        }
    }

    $selling_unit_id = $request_data['selling_unit_id'] ?? null;
    $quantity = $request_data['quantity'] ?? 1;
    $additional_data = $request_data['additional_data'] ?? [];

    if (!$selling_unit_id) {
        throw new Exception('Selling unit ID is required');
    }

    if ($quantity <= 0) {
        throw new Exception('Quantity must be greater than 0');
    }

    // Get selling unit details
    $stmt = $conn->prepare("
        SELECT
            su.*,
            abc.product_id,
            abc.base_product_id,
            abc.base_unit,
            abc.base_quantity,
            p.name as product_name,
            p.sku as product_sku,
            bp.name as base_product_name,
            bp.cost_price as base_cost_price
        FROM auto_bom_selling_units su
        INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
        INNER JOIN products p ON abc.product_id = p.id
        INNER JOIN products bp ON abc.base_product_id = bp.id
        WHERE su.id = :selling_unit_id
    ");
    $stmt->execute([':selling_unit_id' => $selling_unit_id]);
    $unit_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$unit_details) {
        throw new Exception('Selling unit not found');
    }

    // Calculate unit price
    $unit_price = $auto_bom_manager->calculateSellingUnitPrice($selling_unit_id, $additional_data);

    // Calculate total price
    $total_price = $unit_price * $quantity;

    // Get base cost for margin calculation
    $base_cost_per_unit = $unit_details['base_cost_price'] / $unit_details['base_quantity'];
    $base_cost_for_quantity = $base_cost_per_unit * $unit_details['unit_quantity'] * $quantity;

    // Calculate margin
    $margin_amount = $total_price - $base_cost_for_quantity;
    $margin_percentage = $base_cost_for_quantity > 0 ?
        (($margin_amount / $base_cost_for_quantity) * 100) : 0;

    // Get system settings for currency formatting
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $response = [
        'success' => true,
        'data' => [
            'selling_unit_id' => $selling_unit_id,
            'product_name' => $unit_details['product_name'],
            'unit_name' => $unit_details['unit_name'],
            'unit_quantity' => $unit_details['unit_quantity'],
            'pricing_strategy' => $unit_details['pricing_strategy'],
            'quantity_requested' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => $total_price,
            'formatted_unit_price' => formatCurrency($unit_price, $settings),
            'formatted_total_price' => formatCurrency($total_price, $settings),
            'base_cost_per_unit' => $base_cost_per_unit,
            'total_base_cost' => $base_cost_for_quantity,
            'margin_amount' => $margin_amount,
            'margin_percentage' => round($margin_percentage, 2),
            'currency' => $settings['currency_symbol'] ?? 'KES'
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>
