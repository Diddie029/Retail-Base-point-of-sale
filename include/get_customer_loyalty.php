<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get request data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('Invalid request data');
    }

    $customer_id = $data['customer_id'] ?? null;
    $points_to_use = $data['points_to_use'] ?? 0;

    if (!$customer_id) {
        throw new Exception('Customer ID is required');
    }

    // Get customer information
    $customer = getCustomerById($conn, $customer_id);
    if (!$customer) {
        throw new Exception('Customer not found');
    }

    // Get customer's loyalty points balance
    $balance = getCustomerLoyaltyBalance($conn, $customer_id);

    // Get loyalty settings
    $loyaltySettings = getLoyaltySettings($conn);

    // Check if loyalty program is enabled
    if (!$loyaltySettings['enable_loyalty_program']) {
        throw new Exception('Loyalty program is not enabled');
    }

    // Validate points to use if provided
    if ($points_to_use > 0) {
        if ($points_to_use > $balance) {
            throw new Exception('Insufficient loyalty points. Available: ' . $balance . ', Requested: ' . $points_to_use);
        }

        // Check minimum redemption requirement
        $minRedemption = $loyaltySettings['minimum_redemption_points'] ?? 100;
        if ($points_to_use < $minRedemption) {
            throw new Exception('Minimum redemption is ' . $minRedemption . ' points');
        }
    }

    // Calculate points value (100 points = 1 currency unit by default)
    $pointsToCurrencyRate = $loyaltySettings['points_to_currency_rate'] ?? 100;
    $pointsValue = calculateLoyaltyPointsValue($points_to_use, $pointsToCurrencyRate);

    // Return success response
    echo json_encode([
        'success' => true,
        'balance' => $balance,
        'points_value' => $pointsValue,
        'points_to_currency_rate' => $pointsToCurrencyRate,
        'minimum_redemption' => $loyaltySettings['minimum_redemption_points'] ?? 100,
        'customer' => [
            'id' => $customer['id'],
            'display_name' => $customer['first_name'] . ' ' . $customer['last_name'],
            'phone' => $customer['phone'],
            'email' => $customer['email'],
            'membership_level' => $customer['membership_level'] ?? 'Basic'
        ]
    ]);

} catch (Exception $e) {
    error_log("Customer loyalty validation error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
