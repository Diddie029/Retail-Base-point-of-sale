<?php
/**
 * Security helper functions for backup operations
 */

/**
 * Check if backup verification is required and valid
 */
function requireBackupVerification($redirect_url = '') {
    // Check if verification is required
    if (!isset($_SESSION['backup_verified']) || !$_SESSION['backup_verified']) {
        $redirect = $redirect_url ?: 'backup_verify.php';
        header("Location: {$redirect}");
        exit();
    }
    
    // Check if verification has expired (30 minutes)
    if (!isset($_SESSION['backup_verified_time']) || 
        (time() - $_SESSION['backup_verified_time']) > 1800) {
        // Clear expired verification
        unset($_SESSION['backup_verified']);
        unset($_SESSION['backup_verified_time']);
        
        $redirect = $redirect_url ?: 'backup_verify.php';
        header("Location: {$redirect}");
        exit();
    }
    
    // Verification is valid, refresh the time
    $_SESSION['backup_verified_time'] = time();
}

/**
 * Check if user has permission to manage backups
 */
function hasBackupPermission() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $user_id = $_SESSION['user_id'];

    // Get user permissions from database using role-based system
    global $conn;
    $stmt = $conn->prepare("
        SELECT p.name as permission_name
        FROM users u
        JOIN role_permissions rp ON u.role_id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return in_array('manage_settings', $permissions);
}

/**
 * Require backup permission
 */
function requireBackupPermission() {
    if (!hasBackupPermission()) {
        header('Location: ../../dashboard/dashboard.php');
        exit();
    }
}

/**
 * Log backup security event
 */
function logBackupSecurityEvent($event, $user_id = null) {
    if (!$user_id) {
        $user_id = $_SESSION['user_id'] ?? 'Unknown';
    }
    
    $log_file = __DIR__ . '/../../backups/logs/security.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $log_entry = "[{$timestamp}] [SECURITY] [User: {$user_id}] [IP: {$ip}] [Event: {$event}] [UA: {$user_agent}]" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}
?>
