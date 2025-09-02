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
if (!hasPermission('manage_roles', $permissions)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$delete_role_id = intval($input['role_id'] ?? 0);

if (!$delete_role_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role ID is required']);
    exit();
}

try {
    // Get role details before deletion
    $stmt = $conn->prepare("SELECT name, description FROM roles WHERE id = :role_id");
    $stmt->bindParam(':role_id', $delete_role_id);
    $stmt->execute();
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Role not found']);
        exit();
    }

    // Check if role has users assigned
    $stmt = $conn->prepare("SELECT COUNT(*) as user_count FROM users WHERE role_id = :role_id");
    $stmt->bindParam(':role_id', $delete_role_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['user_count'] > 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete role that has users assigned to it. Please reassign or remove users first.'
        ]);
        exit();
    }

    // Prevent deletion of critical system roles (optional check)
    $critical_roles = ['admin', 'administrator', 'super admin'];
    if (in_array(strtolower($role['name']), $critical_roles)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete critical system role: ' . $role['name']
        ]);
        exit();
    }

    $conn->beginTransaction();

    // Delete role permissions first (foreign key constraint)
    $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
    $stmt->bindParam(':role_id', $delete_role_id);
    $stmt->execute();

    // Delete the role
    $stmt = $conn->prepare("DELETE FROM roles WHERE id = :role_id");
    $stmt->bindParam(':role_id', $delete_role_id);
    $stmt->execute();

    // Log activity
    $action = "Deleted role: " . $role['name'];
    $details = json_encode([
        'deleted_role_id' => $delete_role_id,
        'role_name' => $role['name'],
        'role_description' => $role['description']
    ]);
    
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
    $log_stmt->bindParam(':user_id', $current_user_id);
    $log_stmt->bindParam(':action', $action);
    $log_stmt->bindParam(':details', $details);
    $log_stmt->execute();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role deleted successfully'
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
