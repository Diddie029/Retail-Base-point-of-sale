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

if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
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
    
    // Generate 6-digit OTP
    $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Store OTP in database with expiration (15 minutes)
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // Delete any existing OTP for this user
    $delete_stmt = $conn->prepare("DELETE FROM email_verifications WHERE user_id = :user_id");
    $delete_stmt->bindParam(':user_id', $user_id);
    $delete_stmt->execute();
    
    // Insert new OTP
    $insert_stmt = $conn->prepare("
        INSERT INTO email_verifications (user_id, otp_code, expires_at, created_at) 
        VALUES (:user_id, :otp_code, :expires_at, NOW())
    ");
    $insert_stmt->bindParam(':user_id', $user_id);
    $insert_stmt->bindParam(':otp_code', $otp_code);
    $insert_stmt->bindParam(':expires_at', $expires_at);
    $insert_stmt->execute();
    
    // Get email settings and template
    $settings = [];
    $settings_stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('company_name', 'smtp_from_email', 'smtp_from_name', 'email_verification_subject', 'email_verification_body')");
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
    
    if (!$smtp_configured) {
        echo json_encode([
            'success' => false, 
            'message' => 'SMTP is not configured. Please configure email settings in Admin → Settings → Email Settings.',
            'error_type' => 'smtp_not_configured',
            'missing_fields' => $missing_fields
        ]);
        exit;
    }
    
    // Prepare email content
    $company_name = $settings['company_name'] ?? 'POS System';
    $from_email = $settings['smtp_from_email'];
    $from_name = $settings['smtp_from_name'] ?? $company_name;
    
    // Get template or use default
    $subject = $settings['email_verification_subject'] ?? "Email Verification - $company_name";
    $body_template = $settings['email_verification_body'] ?? "Email Verification

Hello {first_name} {last_name},

Thank you for registering with {company_name}. To complete your registration, please verify your email address using the verification code below:

Verification Code: {otp_code}

This code will expire in 15 minutes.

If you didn't request this verification, please ignore this email.

Best regards,
{company_name} Team";
    
    // Replace placeholders
    $subject = str_replace(['{company_name}', '{first_name}', '{last_name}', '{username}', '{email}', '{otp_code}'], 
                          [$company_name, $user['first_name'], $user['last_name'], $user['email'], $user['email'], $otp_code], 
                          $subject);
    
    $body = str_replace(['{company_name}', '{first_name}', '{last_name}', '{username}', '{email}', '{otp_code}'], 
                       [$company_name, $user['first_name'], $user['last_name'], $user['email'], $user['email'], $otp_code], 
                       $body_template);
    
    // Convert to HTML if it's plain text
    if (strpos($body, '<') === false) {
        $body = nl2br(htmlspecialchars($body));
    }
    
    $message = "
    <html>
    <head>
        <title>Email Verification</title>
    </head>
    <body>
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            $body
        </div>
    </body>
    </html>
    ";
    
    // Send email
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'Reply-To: ' . $from_email,
        'X-Mailer: PHP/' . phpversion()
    ];
    
    $mail_sent = mail($user['email'], $subject, $message, implode("\r\n", $headers));
    
    if ($mail_sent) {
        // Log the activity
        $action = "Sent email verification code to user {$user['first_name']} {$user['last_name']}";
        $details = json_encode([
            'target_user_id' => $user_id,
            'email' => $user['email'],
            'action_type' => 'email_verification_sent'
        ]);
        
        $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (:user_id, :action, :details, NOW())");
        $log_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $log_stmt->bindParam(':action', $action);
        $log_stmt->bindParam(':details', $details);
        $log_stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Verification code sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send verification email']);
    }
    
} catch (Exception $e) {
    error_log("Email verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while sending verification email']);
}
?>
