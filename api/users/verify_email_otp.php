<?php
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

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? '';
$otp_code = $input['otp_code'] ?? '';

if (empty($user_id) || empty($otp_code)) {
    echo json_encode(['success' => false, 'message' => 'User ID and OTP code are required']);
    exit;
}

try {
    // Get user information
    $stmt = $conn->prepare("SELECT id, email, first_name, last_name, email_verified FROM users WHERE id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    if ($user['email_verified']) {
        echo json_encode(['success' => false, 'message' => 'Email is already verified']);
        exit;
    }
    
    // Check OTP code
    $stmt = $conn->prepare("
        SELECT id, otp_code, expires_at 
        FROM email_verifications 
        WHERE user_id = :user_id AND otp_code = :otp_code AND expires_at > NOW()
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':otp_code', $otp_code);
    $stmt->execute();
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verification) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired verification code']);
        exit;
    }
    
    // Verify the email
    $update_stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = :user_id");
    $update_stmt->bindParam(':user_id', $user_id);
    $update_stmt->execute();
    
    // Delete the used OTP
    $delete_stmt = $conn->prepare("DELETE FROM email_verifications WHERE user_id = :user_id");
    $delete_stmt->bindParam(':user_id', $user_id);
    $delete_stmt->execute();
    
    // Log the activity
    $action = "Email verified for user {$user['first_name']} {$user['last_name']}";
    $details = json_encode([
        'target_user_id' => $user_id,
        'email' => $user['email'],
        'action_type' => 'email_verified'
    ]);
    
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
    $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $log_stmt->bindParam(':action', $action);
    $log_stmt->bindParam(':details', $details);
    $log_stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
    
} catch (Exception $e) {
    error_log("Email verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while verifying email']);
}
?>
