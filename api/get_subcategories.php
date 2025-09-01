<?php
session_start();
require_once '../include/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if category_id is provided
if (!isset($_GET['category_id']) || empty($_GET['category_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Category ID is required']);
    exit();
}

$category_id = intval($_GET['category_id']);

try {
    // Get subcategories for the selected category
    $stmt = $conn->prepare("
        SELECT id, name, color_code 
        FROM expense_categories 
        WHERE parent_id = ? AND is_active = 1 
        ORDER BY sort_order, name
    ");
    $stmt->execute([$category_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($subcategories);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
