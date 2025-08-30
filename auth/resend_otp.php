<?php
session_start();
require_once __DIR__ . '/../include/functions.php';
include '../include/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['temp_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit();
}

$userId = $_SESSION['temp_user_id'];

// Generate new OTP
$otpCode = createOTP($conn, $userId);

// Send email with new OTP
$userEmail = $_SESSION['temp_email'];
$subject = "New OTP Code - POS System";
$message = "New Verification Code

Here is your new OTP verification code:

OTP Code: {$otpCode}

This code will expire in 10 minutes.

If you didn't request this code, please ignore this email.

Best regards,
POS System";

if (sendEmail($userEmail, $subject, $message)) {
    echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send email']);
}
?>
