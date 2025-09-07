<?php
/**
 * API endpoint to get Employee ID settings
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

try {
    // Get Employee ID settings
    $settings = [];
    $stmt = $conn->query("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key LIKE 'employee_id_%'
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = str_replace('employee_id_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }
    
    // Convert string values to appropriate types
    $settings['auto_generate'] = (bool)($settings['auto_generate'] ?? false);
    $settings['include_year'] = (bool)($settings['include_year'] ?? false);
    $settings['include_month'] = (bool)($settings['include_month'] ?? false);
    $settings['reset_counter_yearly'] = (bool)($settings['reset_counter_yearly'] ?? false);
    $settings['number_length'] = (int)($settings['number_length'] ?? 4);
    $settings['start_number'] = (int)($settings['start_number'] ?? 1);
    $settings['current_counter'] = (int)($settings['current_counter'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    
} catch (Exception $e) {
    error_log("Employee ID settings error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while loading settings: ' . $e->getMessage()
    ]);
}
?>
