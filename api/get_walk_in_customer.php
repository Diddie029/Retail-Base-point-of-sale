<?php
/**
 * API endpoint to get walk-in customer information
 * Returns the default walk-in customer data for POS systems
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
require_once __DIR__ . '/../include/db.php';

try {
    // Get walk-in customer
    $walk_in_customer = getWalkInCustomer($conn);

    if (!$walk_in_customer) {
        // If walk-in customer doesn't exist, try to create it
        $walk_in_check = $conn->prepare("SELECT id FROM customers WHERE customer_number = 'WALK-IN-001' LIMIT 1");
        $walk_in_check->execute();

        if ($walk_in_check->rowCount() == 0) {
            // Get the first admin user ID
            $admin_user = $conn->prepare("SELECT id FROM users WHERE role = 'Admin' LIMIT 1");
            $admin_user->execute();
            $admin_id = $admin_user->fetch(PDO::FETCH_ASSOC)['id'] ?? 1;

            $walk_in_stmt = $conn->prepare("
                INSERT INTO customers (
                    customer_number, first_name, last_name, customer_type,
                    membership_status, membership_level, notes, created_by
                ) VALUES (
                    'WALK-IN-001', 'Walk-in', 'Customer', 'walk_in',
                    'active', 'Bronze', 'Default customer for walk-in purchases', ?
                )
            ");
            $walk_in_stmt->execute([$admin_id]);

            // Get the newly created customer
            $walk_in_customer = getWalkInCustomer($conn);
        }
    }

    if ($walk_in_customer) {
        // Return customer data
        echo json_encode([
            'success' => true,
            'customer' => [
                'id' => $walk_in_customer['id'],
                'customer_number' => $walk_in_customer['customer_number'],
                'first_name' => $walk_in_customer['first_name'],
                'last_name' => $walk_in_customer['last_name'],
                'full_name' => $walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name'],
                'email' => $walk_in_customer['email'],
                'phone' => $walk_in_customer['phone'],
                'mobile' => $walk_in_customer['mobile'],
                'customer_type' => $walk_in_customer['customer_type'],
                'membership_status' => $walk_in_customer['membership_status'],
                'membership_level' => $walk_in_customer['membership_level'],
                'credit_limit' => $walk_in_customer['credit_limit'],
                'current_balance' => $walk_in_customer['current_balance'],
                'loyalty_points' => $walk_in_customer['loyalty_points'],
                'preferred_payment_method' => $walk_in_customer['preferred_payment_method']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Walk-in customer not found and could not be created'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving walk-in customer: ' . $e->getMessage()
    ]);
}
?>
