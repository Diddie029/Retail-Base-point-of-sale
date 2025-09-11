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
    // Get customers with loyalty points
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            c.first_name,
            c.last_name,
            c.phone,
            c.email,
            c.loyalty_points,
            c.membership_level,
            CONCAT(c.first_name, ' ', c.last_name) as name
        FROM customers c
        WHERE c.status = 'active'
        ORDER BY c.first_name, c.last_name
        LIMIT 100
    ");
    
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format customer data
    $formattedCustomers = array_map(function($customer) {
        return [
            'id' => $customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'] ?? '',
            'email' => $customer['email'] ?? '',
            'loyalty_points' => (int)($customer['loyalty_points'] ?? 0),
            'membership_level' => $customer['membership_level'] ?? 'Basic'
        ];
    }, $customers);

    echo json_encode([
        'success' => true,
        'customers' => $formattedCustomers
    ]);

} catch (Exception $e) {
    error_log("Get customers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load customers'
    ]);
}
?>
