<?php
/**
 * Common Functions for POS System
 * Contains utility functions used throughout the application
 */

/**
 * Check if user has a specific permission
 * @param string $permission - The permission to check
 * @param array $userPermissions - Array of user's permissions
 * @return bool - True if user has permission, false otherwise
 */
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
}

/**
 * Format currency value with system settings
 * @param float $amount - The amount to format
 * @param string $currency - Currency symbol (default: system setting)
 * @return string - Formatted currency string
 */
function formatCurrency($amount, $currency = null) {
    global $conn;
    
    // If no currency specified, get from system settings
    if ($currency === null) {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'currency_symbol'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $currency = $result ? $result['setting_value'] : 'KES';
        } catch (Exception $e) {
            $currency = 'KES'; // Fallback to default
        }
    }
    
    return $currency . number_format($amount, 2);
}

/**
 * Get user permissions for current session
 * @param PDO $conn - Database connection
 * @param int $role_id - User's role ID
 * @return array - Array of permission names
 */
function getUserPermissions($conn, $role_id) {
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
    return $permissions;
}

/**
 * Redirect to login page if user is not logged in
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../auth/login.php");
        exit();
    }
}

/**
 * Check permission and redirect if access denied
 * @param string $permission - Required permission
 * @param array $userPermissions - User's permissions array
 * @param string $redirectUrl - Where to redirect if access denied
 */
function requirePermission($permission, $userPermissions, $redirectUrl = '../index.php') {
    if (!hasPermission($permission, $userPermissions)) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        header("Location: " . $redirectUrl);
        exit();
    }
}

/**
 * Sanitize output for HTML display
 * @param string $string - String to sanitize
 * @return string - Sanitized string
 */
function sanitizeOutput($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Get system settings as an associative array
 * @param PDO $conn - Database connection
 * @return array - Settings array with setting_key => setting_value
 */
function getSystemSettings($conn) {
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}
?>