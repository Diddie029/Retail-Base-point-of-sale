<?php
session_start();
require_once __DIR__ . '/../../include/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get SMTP settings
    $settings = [];
    $settings_stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Check if SMTP is properly configured
    $smtp_required_fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email'];
    $smtp_configured = true;
    $missing_fields = [];
    
    foreach ($smtp_required_fields as $field) {
        if (empty($settings[$field])) {
            $smtp_configured = false;
            $missing_fields[] = $field;
        }
    }
    
    echo json_encode([
        'success' => true,
        'smtp_configured' => $smtp_configured,
        'missing_fields' => $missing_fields,
        'message' => $smtp_configured ? 'SMTP is configured' : 'SMTP configuration incomplete'
    ]);
    
} catch (Exception $e) {
    error_log("SMTP status check error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'smtp_configured' => false,
        'message' => 'Error checking SMTP configuration'
    ]);
}
?>
