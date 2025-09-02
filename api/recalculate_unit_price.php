<?php
/**
 * Recalculate Unit Price API
 * Recalculates price for a single Auto BOM selling unit based on its strategy
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

    // Get request data
    $request_data = json_decode(file_get_contents('php://input'), true);
    
    if (!$request_data) {
        // Try POST parameters for backward compatibility
        $request_data = $_POST;
    }

    $selling_unit_id = isset($request_data['selling_unit_id']) ? (int) $request_data['selling_unit_id'] : null;
    $additional_data = isset($request_data['additional_data']) ? $request_data['additional_data'] : [];

    if (!$selling_unit_id) {
        throw new Exception('Selling unit ID is required');
    }

    // Get current selling unit details
    $stmt = $conn->prepare("
        SELECT
            su.*,
            abc.config_name,
            abc.base_product_id,
            abc.base_unit,
            abc.base_quantity,
            p.name as product_name,
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

    // Store the old price for comparison
    $old_price = (float) $unit_details['fixed_price'];

    // Calculate new price based on strategy
    try {
        $new_price = $auto_bom_manager->calculateSellingUnitPrice($selling_unit_id, $additional_data);
    } catch (Exception $e) {
        // If strategy-based calculation fails, fall back to cost-based calculation
        $unit_cost = ($unit_details['base_cost_price'] / $unit_details['base_quantity']) * $unit_details['unit_quantity'];
        $markup = $unit_details['markup_percentage'] > 0 ? $unit_details['markup_percentage'] : 20; // Default 20% markup
        $new_price = $unit_cost * (1 + $markup / 100);
        
        // Log the fallback
        error_log("Price calculation fallback for unit {$selling_unit_id}: " . $e->getMessage());
    }

    // Ensure minimum price (cost + minimum margin)
    $unit_cost = ($unit_details['base_cost_price'] / $unit_details['base_quantity']) * $unit_details['unit_quantity'];
    $min_margin = $unit_details['min_profit_margin'] > 0 ? $unit_details['min_profit_margin'] : 5; // Default 5% minimum
    $min_price = $unit_cost * (1 + $min_margin / 100);

    if ($new_price < $min_price) {
        $new_price = $min_price;
    }

    // Update the price in database
    $stmt = $conn->prepare("
        UPDATE auto_bom_selling_units 
        SET fixed_price = :new_price, updated_at = NOW() 
        WHERE id = :selling_unit_id
    ");
    $stmt->execute([
        ':new_price' => $new_price,
        ':selling_unit_id' => $selling_unit_id
    ]);

    // Log activity
    logActivity($conn, $user_id, 'recalculate_auto_bom_price', 
        "Recalculated price for unit '{$unit_details['unit_name']}' from {$old_price} to {$new_price}");

    // Get system settings for currency formatting
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Calculate margin information
    $margin_amount = $new_price - $unit_cost;
    $margin_percentage = $unit_cost > 0 ? (($margin_amount / $unit_cost) * 100) : 0;

    $response = [
        'success' => true,
        'data' => [
            'selling_unit_id' => $selling_unit_id,
            'unit_name' => $unit_details['unit_name'],
            'config_name' => $unit_details['config_name'],
            'product_name' => $unit_details['product_name'],
            'pricing_strategy' => $unit_details['pricing_strategy'],
            'old_price' => $old_price,
            'new_price' => $new_price,
            'price_changed' => $old_price != $new_price,
            'price_change_amount' => $new_price - $old_price,
            'price_change_percentage' => $old_price > 0 ? (($new_price - $old_price) / $old_price * 100) : 0,
            'formatted_old_price' => formatCurrency($old_price, $settings),
            'formatted_new_price' => formatCurrency($new_price, $settings),
            'unit_cost' => $unit_cost,
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
        'error' => $e->getMessage()
    ]);
}
?>
