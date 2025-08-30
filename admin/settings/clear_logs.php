<?php
session_start();
require_once '../../include/db.php';

// Check if user is logged in and has admin permissions
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if this is a POST request with the correct action
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'clear_security_logs') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    // Clear old login attempts (older than 30 days)
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $login_attempts_deleted = $stmt->rowCount();

    // Clear old signup attempts (older than 30 days)
    $stmt = $conn->prepare("DELETE FROM signup_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $signup_attempts_deleted = $stmt->rowCount();

    // Clear old password reset attempts (older than 30 days)
    $stmt = $conn->prepare("DELETE FROM password_reset_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $password_reset_attempts_deleted = $stmt->rowCount();

    $total_deleted = $login_attempts_deleted + $signup_attempts_deleted + $password_reset_attempts_deleted;

    echo json_encode([
        'success' => true,
        'message' => 'Security logs cleared successfully',
        'deleted_count' => $total_deleted,
        'details' => [
            'login_attempts' => $login_attempts_deleted,
            'signup_attempts' => $signup_attempts_deleted,
            'password_reset_attempts' => $password_reset_attempts_deleted
        ]
    ]);

} catch (PDOException $e) {
    error_log("Failed to clear security logs: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
