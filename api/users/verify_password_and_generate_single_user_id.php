<?php
/**
 * API endpoint to verify password and generate a single User ID
 */

session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Set content type to JSON
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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['password'])) {
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit();
    }
    
    $password = $input['password'];
    
    // Verify current user's password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit();
    }
    
    // Generate a single User ID
    $new_user_id = generateUniqueUserID($conn);
    
    // Log activity
    $action = "Generated single User ID: $new_user_id (password verified)";
    $details = json_encode([
        'generated_user_id' => $new_user_id,
        'password_verified' => true
    ]);
    
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
    $log_stmt->execute([$user_id, $action, $details]);
    
    echo json_encode([
        'success' => true,
        'user_id' => $new_user_id,
        'message' => 'User ID generated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Password verification and single User ID generation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while generating User ID: ' . $e->getMessage()
    ]);
}
?>
