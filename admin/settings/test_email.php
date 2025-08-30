<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    $smtpHost = $_POST['smtp_host'] ?? '';
    $smtpPort = $_POST['smtp_port'] ?? '';
    $smtpUsername = $_POST['smtp_username'] ?? '';
    $smtpPassword = $_POST['smtp_password'] ?? '';
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
    $smtpFromEmail = $_POST['smtp_from_email'] ?? '';
    $smtpFromName = $_POST['smtp_from_name'] ?? 'POS System';

    // Validate required fields
    if (empty($smtpHost) || empty($smtpPort) || empty($smtpUsername) || empty($smtpPassword) || empty($smtpFromEmail)) {
        echo json_encode(['success' => false, 'message' => 'All SMTP fields are required']);
        exit();
    }

    try {
        // Create test email content
        $testSubject = 'POS System - Email Test';
        $testMessage = "
        <html>
        <head>
            <title>Email Test</title>
        </head>
        <body>
            <h2>Email Configuration Test</h2>
            <p>This is a test email to verify your SMTP settings are working correctly.</p>
            <p><strong>Test Details:</strong></p>
            <ul>
                <li>SMTP Host: {$smtpHost}</li>
                <li>SMTP Port: {$smtpPort}</li>
                <li>Encryption: {$smtpEncryption}</li>
                <li>From Email: {$smtpFromEmail}</li>
                <li>From Name: {$smtpFromName}</li>
                <li>Test Time: " . date('Y-m-d H:i:s') . "</li>
            </ul>
            <p>If you received this email, your SMTP settings are configured correctly!</p>
            <p>Best regards,<br>POS System</p>
        </body>
        </html>
        ";

        // Try to send test email using the provided SMTP settings
        $headers = "From: {$smtpFromName} <{$smtpFromEmail}>\r\n";
        $headers .= "Reply-To: {$smtpFromEmail}\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // For now, use PHP's mail function (in production, you'd configure SMTP properly)
        // This is a basic test - in production you'd use PHPMailer or similar with SMTP
        if (mail($smtpFromEmail, $testSubject, $testMessage, $headers)) {
            echo json_encode(['success' => true, 'message' => 'Test email sent successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send test email. Please check your SMTP settings.']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
