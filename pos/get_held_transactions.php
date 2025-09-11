<?php
// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get user information
$user_id = $_SESSION['user_id'];
$till_id = $_SESSION['selected_till_id'] ?? null;

try {
    // Get query parameters
    $filter_till = $_GET['filter_till'] ?? null;
    $filter_cashier = $_GET['filter_cashier'] ?? null;

    // Build query
    $sql = "
        SELECT 
            ht.*,
            u.username as cashier_name,
            rt.till_name,
            rt.till_code
        FROM held_transactions ht
        LEFT JOIN users u ON ht.user_id = u.id
        LEFT JOIN register_tills rt ON ht.till_id = rt.id
        WHERE ht.status = 'held'
    ";
    
    $params = [];
    
    // Add filters
    if ($filter_till && $filter_till !== 'all') {
        $sql .= " AND ht.till_id = ?";
        $params[] = $filter_till;
    }
    
    if ($filter_cashier && $filter_cashier !== 'all') {
        $sql .= " AND ht.user_id = ?";
        $params[] = $filter_cashier;
    }
    
    $sql .= " ORDER BY ht.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $held_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode cart data and format response
    $formatted_transactions = [];
    foreach ($held_transactions as $transaction) {
        $cartData = json_decode($transaction['cart_data'], true);
        
        $formatted_transactions[] = [
            'id' => $transaction['id'],
            'cashier_name' => $transaction['cashier_name'],
            'till_name' => $transaction['till_name'],
            'till_code' => $transaction['till_code'],
            'reason' => $transaction['reason'],
            'customer_reference' => $transaction['customer_reference'],
            'created_at' => $transaction['created_at'],
            'cart' => $cartData['items'] ?? [],
            'totals' => $cartData['totals'] ?? ['subtotal' => 0, 'tax' => 0, 'total' => 0],
            'item_count' => count($cartData['items'] ?? [])
        ];
    }

    // Get available tills and cashiers for filters
    $tills = [];
    $cashiers = [];
    
    if (!$filter_till || $filter_till === 'all') {
        $stmt = $conn->query("
            SELECT DISTINCT rt.id, rt.till_name, rt.till_code
            FROM register_tills rt
            INNER JOIN held_transactions ht ON rt.id = ht.till_id
            WHERE ht.status = 'held' AND rt.is_active = 1
            ORDER BY rt.till_name
        ");
        $tills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (!$filter_cashier || $filter_cashier === 'all') {
        $stmt = $conn->query("
            SELECT DISTINCT u.id, u.username
            FROM users u
            INNER JOIN held_transactions ht ON u.id = ht.user_id
            WHERE ht.status = 'held'
            ORDER BY u.username
        ");
        $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'held_transactions' => $formatted_transactions,
        'filters' => [
            'tills' => $tills,
            'cashiers' => $cashiers
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>
