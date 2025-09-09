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

// Check if user has permission to manage loyalty points
// You can add role-based permission checking here

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $customerId = $input['customer_id'] ?? null;
    $points = $input['points'] ?? null;
    $description = $input['description'] ?? '';
    $expiryDate = $input['expiry_date'] ?? null;
    
    if (!$customerId || !$points) {
        throw new Exception('Customer ID and points are required');
    }
    
    if ($points <= 0) {
        throw new Exception('Points must be greater than 0');
    }
    
    // Validate customer exists
    $customer = getCustomerById($conn, $customerId);
    if (!$customer) {
        throw new Exception('Customer not found');
    }
    
    // Generate transaction reference
    $transactionRef = 'MANUAL_' . date('YmdHis') . '_' . $customerId;
    
    // Add loyalty points
    $success = addLoyaltyPoints(
        $conn, 
        $customerId, 
        $points, 
        $description ?: "Manual points addition by " . ($_SESSION['username'] ?? 'Admin'),
        $transactionRef,
        $expiryDate
    );
    
    if ($success) {
        // Get updated customer info
        $updatedCustomer = getCustomerById($conn, $customerId);
        $loyaltyBalance = getCustomerLoyaltyBalance($conn, $customerId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Loyalty points added successfully',
            'customer' => [
                'id' => $updatedCustomer['id'],
                'name' => trim($updatedCustomer['first_name'] . ' ' . $updatedCustomer['last_name']),
                'loyalty_points' => $loyaltyBalance
            ],
            'points_added' => $points,
            'new_balance' => $loyaltyBalance
        ]);
    } else {
        throw new Exception('Failed to add loyalty points');
    }
    
} catch (Exception $e) {
    error_log("add_loyalty_points.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
