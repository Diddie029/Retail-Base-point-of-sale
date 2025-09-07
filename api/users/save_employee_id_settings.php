<?php
/**
 * API endpoint to save Employee ID settings
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
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit();
    }
    
    // Validate and sanitize input
    $settings = [
        'auto_generate' => (bool)($input['auto_generate'] ?? false),
        'prefix' => trim($input['prefix'] ?? ''),
        'suffix' => trim($input['suffix'] ?? ''),
        'number_length' => max(3, min(6, (int)($input['number_length'] ?? 4))),
        'start_number' => max(1, (int)($input['start_number'] ?? 1)),
        'separator' => trim($input['separator'] ?? ''),
        'include_year' => (bool)($input['include_year'] ?? false),
        'include_month' => (bool)($input['include_month'] ?? false),
        'reset_counter_yearly' => (bool)($input['reset_counter_yearly'] ?? false)
    ];
    
    // Validate separator
    $allowedSeparators = ['', '-', '_', '.', '/'];
    if (!in_array($settings['separator'], $allowedSeparators)) {
        $settings['separator'] = '';
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Update settings
        $settingKeys = [
            'auto_generate' => 'employee_id_auto_generate',
            'prefix' => 'employee_id_prefix',
            'suffix' => 'employee_id_suffix',
            'number_length' => 'employee_id_number_length',
            'start_number' => 'employee_id_start_number',
            'separator' => 'employee_id_separator',
            'include_year' => 'employee_id_include_year',
            'include_month' => 'employee_id_include_month',
            'reset_counter_yearly' => 'employee_id_reset_counter_yearly'
        ];
        
        foreach ($settingKeys as $key => $settingKey) {
            $value = $settings[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$settingKey, $value]);
        }
        
        // Reset counter if yearly reset is enabled and it's a new year
        if ($settings['reset_counter_yearly']) {
            $currentYear = date('Y');
            $lastResetYear = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'employee_id_last_reset_year'")->fetchColumn();
            
            if ($lastResetYear != $currentYear) {
                // Reset counter for new year
                $stmt = $conn->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES ('employee_id_current_counter', ?) 
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$settings['start_number'] - 1]);
                
                // Update last reset year
                $stmt = $conn->prepare("
                    INSERT INTO settings (setting_key, setting_value) 
                    VALUES ('employee_id_last_reset_year', ?) 
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$currentYear]);
            }
        }
        
        // Log activity
        $action = "Updated Employee ID settings";
        $details = json_encode($settings);
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $log_stmt->execute([$user_id, $action, $details]);
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee ID settings saved successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Employee ID settings save error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving settings: ' . $e->getMessage()
    ]);
}
?>
