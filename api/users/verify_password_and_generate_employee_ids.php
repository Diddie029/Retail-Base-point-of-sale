<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
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

if (!hasPermission('manage_users', $permissions)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Debug logging
error_log("Employee ID API called");

try {
    
    // Get Employee ID settings
    $settings = getEmployeeIdSettings($conn);
    
    if (!$settings['employee_id_auto_generate']) {
        echo json_encode(['success' => false, 'message' => 'Employee ID auto-generation is disabled']);
        exit;
    }
    
    // Get users without Employee IDs
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name FROM users WHERE employee_id IS NULL OR employee_id = ''");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo json_encode(['success' => true, 'message' => 'All users already have Employee IDs', 'generated' => 0]);
        exit;
    }
    
    $generated = 0;
    $errors = [];
    
    // Generate Employee IDs for users without them
    foreach ($users as $user) {
        try {
            $employee_id = generateEmployeeId($conn);
            
            if ($employee_id) {
                $update_stmt = $conn->prepare("UPDATE users SET employee_id = :employee_id WHERE id = :user_id");
                $update_stmt->bindParam(':employee_id', $employee_id);
                $update_stmt->bindParam(':user_id', $user['id']);
                $update_stmt->execute();
                
                $generated++;
                
                // Log the activity
                $action = "Generated Employee ID for user {$user['username']}";
                $details = json_encode([
                    'target_user_id' => $user['id'],
                    'generated_employee_id' => $employee_id,
                    'username' => $user['username'],
                    'change_type' => 'bulk_generation'
                ]);
                
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                $log_stmt->bindParam(':user_id', $user_id);
                $log_stmt->bindParam(':action', $action);
                $log_stmt->bindParam(':details', $details);
                $log_stmt->execute();
                
            } else {
                $errors[] = "Failed to generate Employee ID for user {$user['username']}";
            }
            
        } catch (Exception $e) {
            $errors[] = "Error generating Employee ID for user {$user['username']}: " . $e->getMessage();
        }
    }
    
    // Log bulk generation activity
    $bulk_action = "Bulk generated Employee IDs for users";
    $bulk_details = json_encode([
        'generated_count' => $generated,
        'total_users' => count($users),
        'errors' => $errors,
        'change_type' => 'bulk_generation'
    ]);
    
    $bulk_log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
    $bulk_log_stmt->bindParam(':user_id', $user_id);
    $bulk_log_stmt->bindParam(':action', $bulk_action);
    $bulk_log_stmt->bindParam(':details', $bulk_details);
    $bulk_log_stmt->execute();
    
    echo json_encode([
        'success' => true, 
        'message' => "Successfully generated {$generated} Employee IDs",
        'generated' => $generated,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    error_log("Employee ID generation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while generating Employee IDs']);
}
?>
