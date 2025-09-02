<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p 
        JOIN role_permissions rp ON p.id = rp.permission_id 
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check permissions
if (!hasPermission('manage_users', $permissions)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['user_id']) || !isset($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$target_user_id = intval($input['user_id']);
$new_status = $input['status'];

// Validate status
$valid_statuses = ['active', 'inactive', 'suspended'];
if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Prevent user from deactivating themselves
if ($target_user_id == $user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cannot change your own status']);
    exit();
}

try {
    // Check if target user exists
    $stmt = $conn->prepare("SELECT id, username, status FROM users WHERE id = :id");
    $stmt->bindParam(':id', $target_user_id);
    $stmt->execute();
    $target_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // Update user status
    $stmt = $conn->prepare("UPDATE users SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindParam(':status', $new_status);
    $stmt->bindParam(':id', $target_user_id);
    $stmt->execute();

    // Log activity
    $action = "Changed user status from '{$target_user['status']}' to '$new_status' for user: {$target_user['username']}";
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
    $log_stmt->bindParam(':user_id', $user_id);
    $log_stmt->bindParam(':action', $action);
    $log_stmt->bindParam(':details', json_encode([
        'target_user_id' => $target_user_id,
        'target_username' => $target_user['username'],
        'old_status' => $target_user['status'],
        'new_status' => $new_status
    ]));
    $log_stmt->execute();

    echo json_encode([
        'success' => true, 
        'message' => 'User status updated successfully',
        'new_status' => $new_status
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
