<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$search_term = $_GET['q'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';

if (empty($search_term)) {
    echo json_encode([]);
    exit();
}

try {
    $sql = "
        SELECT 
            r.id,
            r.return_number,
            s.name as supplier_name,
            r.created_at as return_date,
            r.total_amount,
            r.status,
            r.total_items,
            u.username as created_by_name
        FROM returns r
        LEFT JOIN suppliers s ON r.supplier_id = s.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.status IN ('approved', 'completed', 'processed')
        AND (
            r.return_number LIKE :search_term
            OR s.name LIKE :search_term
            OR u.username LIKE :search_term
        )
    ";
    
    $params = [':search_term' => '%' . $search_term . '%'];
    
    if (!empty($supplier_id)) {
        $sql .= " AND r.supplier_id = :supplier_id";
        $params[':supplier_id'] = $supplier_id;
    }
    
    $sql .= " ORDER BY r.created_at DESC LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure we return an array
    if (!is_array($returns)) {
        $returns = [];
    }
    
    header('Content-Type: application/json');
    echo json_encode($returns);
} catch (Exception $e) {
    error_log("Return search error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
