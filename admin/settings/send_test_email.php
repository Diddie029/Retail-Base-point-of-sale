<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id']) || !hasPermission('manage_email_settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Set content type to JSON
header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    // Sanitize and validate input
    $test_email_address = filter_var(trim($_POST['test_email_address'] ?? ''), FILTER_SANITIZE_EMAIL);

    // Validate required fields
    if (empty($test_email_address)) {
        echo json_encode(['success' => false, 'message' => 'Test email address is required']);
        exit;
    }

    // Validate email address
    if (!filter_var($test_email_address, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid test email address format']);
        exit;
    }

    // Get SMTP settings from database
    $settings = [];
    $settings_query = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    while ($row = $settings_query->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Get company name for the email
    $company_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
    $company_name = $company_query->fetchColumn() ?: 'POS System';

    // Validate required SMTP settings
    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_port = $settings['smtp_port'] ?? '';
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
    $smtp_from_email = $settings['smtp_from_email'] ?? '';
    $smtp_from_name = $settings['smtp_from_name'] ?? $company_name;

    if (empty($smtp_host) || empty($smtp_port) || empty($smtp_username) || empty($smtp_password) || empty($smtp_from_email)) {
        echo json_encode(['success' => false, 'message' => 'Missing required SMTP settings']);
        exit;
    }

    // Validate port number
    if (!is_numeric($smtp_port) || $smtp_port < 1 || $smtp_port > 65535) {
        echo json_encode(['success' => false, 'message' => 'SMTP port must be a number between 1 and 65535']);
        exit;
    }

    // Validate encryption method
    if (!in_array($smtp_encryption, ['tls', 'ssl', 'none'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid encryption method']);
        exit;
    }

    // Set default subject
    $test_email_subject = 'SMTP Configuration Test - ' . date('Y-m-d H:i:s');
    
    // Simple predefined test email message with company branding
    $simple_test_message = "
    <html>
    <head>
        <title>SMTP Test Email from {$company_name}</title>
    </head>
    <body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background: #f8f9fa; padding: 30px; border-radius: 10px; text-align: center;'>
            <h2 style='color: #28a745; margin-bottom: 20px;'>✅ SMTP Test Successful!</h2>
            <p style='font-size: 18px; color: #333; margin-bottom: 20px;'>
                If you receive this email, your SMTP configuration is working correctly.
            </p>
            <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h4 style='color: #495057; margin-bottom: 15px;'>Test Details:</h4>
                <ul style='text-align: left; color: #6c757d; line-height: 1.6;'>
                    <li><strong>Company:</strong> {$company_name}</li>
                    <li><strong>SMTP Host:</strong> {$smtp_host}</li>
                    <li><strong>SMTP Port:</strong> {$smtp_port}</li>
                    <li><strong>Encryption:</strong> " . strtoupper($smtp_encryption) . "</li>
                    <li><strong>From Email:</strong> {$smtp_from_email}</li>
                    <li><strong>Test Time:</strong> " . date('F j, Y \a\t g:i A') . "</li>
                </ul>
            </div>
            <div style='background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 8px; margin-top: 20px;'>
                <p style='margin: 0; font-weight: bold;'>🎉 Your email configuration is working perfectly!</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Create PHPMailer instance
    require_once '../../vendor/autoload.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->Port = $smtp_port;

        // Set encryption
        if ($smtp_encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtp_encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        // For 'none', don't set SMTPSecure

        // Recipients
        $mail->setFrom($smtp_from_email, $smtp_from_name);
        $mail->addAddress($test_email_address);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $test_email_subject;
        $mail->Body = $simple_test_message;

        // Send the email
        $mail->send();
        
        // Log the test email
        error_log("Test email sent successfully to: {$test_email_address} using SMTP: {$smtp_host}:{$smtp_port}");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Test email sent successfully to ' . $test_email_address
        ]);

    } catch (Exception $e) {
        error_log("Test email failed: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to send test email: ' . $e->getMessage()
        ]);
    }

} catch (Exception $e) {
    error_log("Test email error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
?>
