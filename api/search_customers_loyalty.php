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
    
    // Get customers with loyalty points information
    $sql = "SELECT c.id, c.customer_number, c.first_name, c.last_name, c.email, c.phone, 
                   c.customer_type, c.company_name, c.membership_level, c.loyalty_points,
                   COALESCE(lp.balance, 0) as current_balance
            FROM customers c
            LEFT JOIN (
                SELECT customer_id, 
                       COALESCE(SUM(
                           CASE 
                               WHEN transaction_type = 'earned' THEN points_earned
                               WHEN transaction_type = 'redeemed' THEN -points_redeemed
                               WHEN transaction_type = 'expired' THEN -points_earned
                               ELSE 0
                           END
                       ), 0) as balance
                FROM loyalty_points 
                GROUP BY customer_id
            ) lp ON c.id = lp.customer_id
            WHERE c.membership_status = 'active' 
            AND c.customer_type != 'walk_in'";
    
    $params = [];
    if (!empty($search)) {
        $sql .= " AND (CONCAT(c.first_name, ' ', c.last_name) LIKE :search 
                 OR c.phone LIKE :search 
                 OR c.email LIKE :search 
                 OR c.customer_number LIKE :search
                 OR c.company_name LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY c.customer_type ASC, c.first_name ASC, c.last_name ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            'loyalty_points' => (int)$customer['current_balance'],
            'display_name' => $customer['company_name'] ? $customer['company_name'] : 
                            trim($customer['first_name'] . ' ' . $customer['last_name'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $formattedCustomers,
        'total' => count($formattedCustomers)
    ]);
    
} catch (Exception $e) {
    error_log("search_customers_loyalty.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to search customers: ' . $e->getMessage()
    ]);
}
?>
