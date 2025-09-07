<?php
/**
 * API endpoint to generate User IDs for existing users
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
    // Generate user IDs for existing users
    $results = generateUserIDsForExistingUsers($conn);
    
    if ($results['success']) {
        // Log activity
        $action = "Generated User IDs for {$results['generated']} users";
        $details = json_encode([
            'generated_count' => $results['generated'],
            'errors' => $results['errors']
        ]);
        
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
        $log_stmt->bindParam(':user_id', $user_id);
        $log_stmt->bindParam(':action', $action);
        $log_stmt->bindParam(':details', $details);
        $log_stmt->execute();
        
        echo json_encode([
            'success' => true,
            'generated' => $results['generated'],
            'message' => "Successfully generated {$results['generated']} User IDs"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate User IDs',
            'errors' => $results['errors']
        ]);
    }
    
} catch (Exception $e) {
    error_log("User ID generation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while generating User IDs: ' . $e->getMessage()
    ]);
}
?>
