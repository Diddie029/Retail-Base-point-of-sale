<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
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

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($method) {
        case 'POST':
            // Handle various status operations
            $action = $input['action'] ?? '';
            $user_id = intval($input['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            // Prevent users from modifying their own status
            if ($user_id == $current_user_id) {
                throw new Exception('You cannot modify your own account status');
            }
            
            // Get target user
            $stmt = $conn->prepare("SELECT username, first_name, last_name, status, account_locked FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$target_user) {
                throw new Exception('User not found');
            }
            
            switch ($action) {
                case 'toggle_status':
                    $new_status = $input['status'] ?? '';
                    if (!in_array($new_status, ['active', 'inactive', 'suspended'])) {
                        throw new Exception('Invalid status');
                    }
                    
                    $stmt = $conn->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :user_id");
                    $stmt->bindParam(':status', $new_status);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    // Log activity
                    $user_name = trim($target_user['first_name'] . ' ' . $target_user['last_name']) ?: $target_user['username'];
                    $action_log = "Changed user status: {$user_name} from {$target_user['status']} to {$new_status}";
                    $details = json_encode([
                        'target_user_id' => $user_id,
                        'old_status' => $target_user['status'],
                        'new_status' => $new_status,
                        'username' => $target_user['username']
                    ]);
                    
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                    $log_stmt->bindParam(':user_id', $current_user_id);
                    $log_stmt->bindParam(':action', $action_log);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->execute();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "User status changed to {$new_status}",
                        'new_status' => $new_status
                    ]);
                    break;
                    
                case 'lock_account':
                    $lock_duration = intval($input['duration'] ?? 60); // minutes
                    $reason = trim($input['reason'] ?? '');
                    $locked_until = date('Y-m-d H:i:s', strtotime("+{$lock_duration} minutes"));
                    
                    $stmt = $conn->prepare("
                        UPDATE users SET 
                            account_locked = 1, 
                            locked_until = :locked_until,
                            lock_reason = :reason,
                            updated_at = NOW() 
                        WHERE id = :user_id
                    ");
                    $stmt->bindParam(':locked_until', $locked_until);
                    $stmt->bindParam(':reason', $reason);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    // Log activity
                    $user_name = trim($target_user['first_name'] . ' ' . $target_user['last_name']) ?: $target_user['username'];
                    $action_log = "Locked user account: {$user_name} until {$locked_until}";
                    $details = json_encode([
                        'target_user_id' => $user_id,
                        'locked_until' => $locked_until,
                        'reason' => $reason,
                        'duration_minutes' => $lock_duration
                    ]);
                    
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                    $log_stmt->bindParam(':user_id', $current_user_id);
                    $log_stmt->bindParam(':action', $action_log);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->execute();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "Account locked until " . date('M j, Y g:i A', strtotime($locked_until)),
                        'locked_until' => $locked_until
                    ]);
                    break;
                    
                case 'unlock_account':
                    $stmt = $conn->prepare("
                        UPDATE users SET 
                            account_locked = 0, 
                            locked_until = NULL,
                            lock_reason = NULL,
                            failed_login_attempts = 0,
                            updated_at = NOW() 
                        WHERE id = :user_id
                    ");
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    // Log activity
                    $user_name = trim($target_user['first_name'] . ' ' . $target_user['last_name']) ?: $target_user['username'];
                    $action_log = "Unlocked user account: {$user_name}";
                    $details = json_encode([
                        'target_user_id' => $user_id,
                        'action' => 'account_unlock'
                    ]);
                    
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                    $log_stmt->bindParam(':user_id', $current_user_id);
                    $log_stmt->bindParam(':action', $action_log);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->execute();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "Account unlocked successfully"
                    ]);
                    break;
                    
                case 'reset_login_attempts':
                    $stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, updated_at = NOW() WHERE id = :user_id");
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();
                    
                    // Log activity
                    $user_name = trim($target_user['first_name'] . ' ' . $target_user['last_name']) ?: $target_user['username'];
                    $action_log = "Reset failed login attempts for: {$user_name}";
                    $details = json_encode([
                        'target_user_id' => $user_id,
                        'action' => 'reset_login_attempts'
                    ]);
                    
                    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
                    $log_stmt->bindParam(':user_id', $current_user_id);
                    $log_stmt->bindParam(':action', $action_log);
                    $log_stmt->bindParam(':details', $details);
                    $log_stmt->execute();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "Failed login attempts reset successfully"
                    ]);
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        case 'GET':
            // Get user status information
            $user_id = intval($_GET['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('User ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT id, username, first_name, last_name, status, account_locked, 
                       locked_until, lock_reason, failed_login_attempts, last_login,
                       created_at, updated_at
                FROM users 
                WHERE id = :user_id
            ");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Get recent login attempts
            $stmt = $conn->prepare("
                SELECT success, ip_address, user_agent, created_at
                FROM login_attempts 
                WHERE identifier IN (
                    SELECT username FROM users WHERE id = :user_id
                    UNION
                    SELECT email FROM users WHERE id = :user_id
                )
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $login_attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'user' => $user,
                'login_attempts' => $login_attempts
            ]);
            break;
            
        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
