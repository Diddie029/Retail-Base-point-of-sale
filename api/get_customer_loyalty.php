<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $customerId = $_GET['customer_id'] ?? null;
    
    if (!$customerId) {
        throw new Exception('Customer ID is required');
    }
    
    // Get customer details
    $customer = getCustomerById($conn, $customerId);
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // Get loyalty balance
    $loyaltyBalance = getCustomerLoyaltyBalance($conn, $customerId);
    
    // Get available rewards
    $availableRewards = getAvailableLoyaltyRewards($conn, $loyaltyBalance);
    
    // Get recent loyalty history
    $loyaltyHistory = getCustomerLoyaltyHistory($conn, $customerId, 10);
    
    // Calculate loyalty points value
    $pointsValue = calculateLoyaltyPointsValue($loyaltyBalance);
    
    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => $customer['id'],
            'name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'membership_level' => $customer['membership_level'],
            'customer_type' => $customer['customer_type']
        ],
        'loyalty' => [
            'balance' => $loyaltyBalance,
            'points_value' => $pointsValue,
            'available_rewards' => $availableRewards,
            'recent_history' => $loyaltyHistory
        ]
    ]);
    
} catch (Exception $e) {
    error_log("get_customer_loyalty.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
