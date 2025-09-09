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
    $search = $_GET['search'] ?? '';
    
    // Debug: Check if we have any customers at all
    $debugStmt = $conn->query("SELECT COUNT(*) as total FROM customers");
    $totalCustomers = $debugStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Debug: Check customers with active status
    $activeStmt = $conn->query("SELECT COUNT(*) as active FROM customers WHERE membership_status = 'active'");
    $activeCustomers = $activeStmt->fetch(PDO::FETCH_ASSOC)['active'];
    
    $customers = getAllCustomers($conn, $search);
    
    // Format customers for display
    $formattedCustomers = [];
    foreach ($customers as $customer) {
        $formattedCustomers[] = [
            'id' => $customer['id'],
            'customer_number' => $customer['customer_number'],
            'name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'customer_type' => $customer['customer_type'],
            'company_name' => $customer['company_name'],
            'membership_level' => $customer['membership_level'],
            'tax_exempt' => (bool)$customer['tax_exempt'],
            'display_name' => $customer['customer_type'] === 'walk_in' ? 'Walk-in Customer' : 
                            ($customer['company_name'] ? $customer['company_name'] : 
                            trim($customer['first_name'] . ' ' . $customer['last_name']))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $formattedCustomers,
        'debug' => [
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'returned_customers' => count($formattedCustomers)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("get_customers.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch customers: ' . $e->getMessage()
    ]);
}
?>
