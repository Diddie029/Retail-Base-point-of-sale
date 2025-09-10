<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
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

// Check if user has permission to manage settings
if (!hasPermission('manage_settings', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

// Define granular permissions for different sections
$can_manage_company = hasPermission('manage_settings', $permissions) || hasPermission('configure_company_settings', $permissions);
$can_manage_currency = hasPermission('manage_settings', $permissions) || hasPermission('configure_currency_settings', $permissions) || hasPermission('manage_financial_settings', $permissions);
$can_manage_system = hasPermission('manage_settings', $permissions) || hasPermission('configure_system_settings', $permissions);
$can_manage_inventory = hasPermission('manage_settings', $permissions) || hasPermission('configure_inventory_settings', $permissions);
$can_manage_appearance = hasPermission('manage_settings', $permissions) || hasPermission('manage_theme_settings', $permissions);
$can_manage_email = hasPermission('manage_settings', $permissions) || hasPermission('configure_email_settings', $permissions);
$can_manage_security = hasPermission('manage_settings', $permissions) || hasPermission('configure_security_settings', $permissions);
$can_manage_backup = hasPermission('manage_settings', $permissions) || hasPermission('configure_backup_settings', $permissions);
$can_manage_receipts = hasPermission('manage_settings', $permissions) || hasPermission('configure_receipt_settings', $permissions);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default settings if not set
$defaults = [
    'company_name' => 'POS System',
    'company_address' => '',
    'company_phone' => '',
    'company_email' => '',
    'company_website' => '',
    'company_logo' => '',
    'currency_symbol' => 'KES',
    'currency_position' => 'before',
    'currency_decimal_places' => '2',
    'tax_rate' => '0',
    'tax_name' => 'VAT',
    'tax_registration_number' => '',
    'theme_color' => '#6366f1',
    'sidebar_color' => '#1e293b',
    'timezone' => 'Africa/Nairobi',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'low_stock_threshold' => '10',
    'backup_frequency' => 'daily',
    'backup_retention_count' => '10',
    'enable_sound' => '1',
    'default_payment_method' => 'cash',
    'allow_negative_stock' => '0',
    'barcode_type' => 'CODE128',
    'max_login_attempts' => '5',
    'lockout_duration' => '30',
    'rate_limit_attempts' => '5',
    'rate_limit_window' => '15',
    'session_timeout' => '30',
    'password_min_length' => '8',
    'enable_ip_check' => '0',
    'enable_ua_check' => '0',
    'log_security_events' => '1',
    'sku_prefix' => 'LIZ',
    'sku_format' => 'SKU000001',
    'sku_length' => '6',
    'sku_separator' => '',
    'auto_generate_sku' => '1',
    'auto_generate_order_number' => '1',
    'order_number_prefix' => 'ORD',
    'order_number_length' => '6',
    'order_number_separator' => '-',
    'order_number_format' => 'prefix-date-number',

    // Invoice Settings
    'invoice_prefix' => 'INV',
    'invoice_length' => '6',
    'invoice_separator' => '-',
    'invoice_format' => 'prefix-date-number',
    'invoice_auto_generate' => '1',

    // Product Number Settings
    'auto_generate_product_number' => '1',
    'product_number_prefix' => 'PRD',
    'product_number_length' => '6',
    'product_number_separator' => '-',
    'product_number_format' => 'prefix-number',

    // BOM Settings
    'auto_generate_bom_number' => '1',
    'bom_number_prefix' => 'BOM',
    'bom_number_length' => '6',
    'bom_number_separator' => '-',
    'bom_number_format' => 'prefix-date-number',

    // Customer Number Settings
    'auto_generate_customer_number' => '1',
    'customer_number_prefix' => 'CUST',
    'customer_number_length' => '6',
    'customer_number_separator' => '-',
    'customer_number_format' => 'prefix-date-number',

    // Production Order Settings
    'auto_generate_production_order' => '1',
    'bom_production_order_prefix' => 'PROD',
    'bom_production_order_length' => '6',

    // BOM Cost Calculation Settings
    'bom_cost_calculation_method' => 'standard',
    'bom_default_waste_percentage' => '5.0',

    
    // Email Templates
    'email_verification_subject' => 'Email Verification - {company_name}',
    'email_verification_body' => 'Email Verification

Hello {first_name} {last_name},

Thank you for registering with {company_name}. To complete your registration, please verify your email address using the verification code below:

Verification Code: {otp_code}

This code will expire in 15 minutes.

If you didn\'t request this verification, please ignore this email.

Best regards,
{company_name} Team',
    
    // SMTP Settings
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'smtp_from_email' => '',
    'smtp_from_name' => 'POS System',

    // Receipt Settings
    'receipt_header' => '',
    'receipt_contact' => '',
    'receipt_show_tax' => '1',
    'receipt_show_discount' => '1',
    'receipt_footer' => '',
    'receipt_thanks_message' => 'Thank you for your business!',
    'receipt_width' => '80',
    'receipt_font_size' => '12',
    'auto_print_receipt' => '0',
    'auto_close_print_window' => '1',
    'auto_generate_receipt_number' => '1',
    'receipt_number_prefix' => 'RCP',
    'receipt_number_length' => '6',
    'receipt_number_separator' => '-',
    'receipt_number_format' => 'prefix-date-number',
    'allow_receipt_reprint' => '1',
    'max_reprint_attempts' => '3',
    'require_password_for_reprint' => '0',
    'transaction_id_prefix' => 'TXN',
    'transaction_id_length' => '6',
    'transaction_id_format' => 'prefix-random'
];

// Merge with defaults
foreach($defaults as $key => $value) {
    if(!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Function to generate order number preview
function generateOrderNumberPreview($settings) {
    $prefix = $settings['order_number_prefix'] ?? 'ORD';
    $length = intval($settings['order_number_length'] ?? 6);
    $separator = $settings['order_number_separator'] ?? '-';
    $format = $settings['order_number_format'] ?? 'prefix-date-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
    }
}

// Function to generate invoice number preview
function generateInvoiceNumberPreview($settings) {
    $prefix = $settings['invoice_prefix'] ?? 'INV';
    $length = intval($settings['invoice_length'] ?? 6);
    $separator = $settings['invoice_separator'] ?? '-';
    $format = $settings['invoice_format'] ?? 'prefix-date-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
    }
}

// Function to generate BOM number preview
function generateBOMNumberPreview($settings) {
    $prefix = $settings['bom_number_prefix'] ?? 'BOM';
    $length = intval($settings['bom_number_length'] ?? 6);
    $separator = $settings['bom_number_separator'] ?? '-';
    $format = $settings['bom_number_format'] ?? 'prefix-date-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
    }
}

// Function to generate customer number preview
function generateCustomerNumberPreview($settings) {
    $prefix = $settings['customer_number_prefix'] ?? 'CUST';
    $length = intval($settings['customer_number_length'] ?? 6);
    $separator = $settings['customer_number_separator'] ?? '-';
    $format = $settings['customer_number_format'] ?? 'prefix-date-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
    }
}

// Function to generate product number preview
function generateProductNumberPreview($settings) {
    $prefix = $settings['product_number_prefix'] ?? 'PRD';
    $length = intval($settings['product_number_length'] ?? 6);
    $separator = $settings['product_number_separator'] ?? '-';
    $format = $settings['product_number_format'] ?? 'prefix-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $sampleNumber;
    }
}

// Function to generate production order preview
function generateProductionOrderPreview($settings) {
    $prefix = $settings['bom_production_order_prefix'] ?? 'PROD';
    $length = intval($settings['bom_production_order_length'] ?? 6);

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    return $prefix . '-' . $currentDate . '-' . $sampleNumber;
}

// Function to generate receipt number preview
function generateReceiptNumberPreview($settings) {
    $prefix = $settings['receipt_number_prefix'] ?? 'RCP';
    $length = intval($settings['receipt_number_length'] ?? 6);
    $separator = $settings['receipt_number_separator'] ?? '-';
    $format = $settings['receipt_number_format'] ?? 'prefix-date-number';

    // Generate sample number
    $sampleNumber = str_pad('1', $length, '0', STR_PAD_LEFT);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
        case 'prefix-number':
            return $prefix . $separator . $sampleNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sampleNumber;
        case 'number-only':
            return $sampleNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sampleNumber;
    }
}

// Function to generate transaction ID preview
function generateTransactionIdPreview($settings) {
    $prefix = $settings['transaction_id_prefix'] ?? 'TXN';
    $length = intval($settings['transaction_id_length'] ?? 6);
    $format = $settings['transaction_id_format'] ?? 'prefix-random';

    // Define character sets based on format
    $characters = '';
    switch($format) {
        case 'prefix-random':
        case 'random-only':
        case 'prefix-date-random':
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            break;
        case 'prefix-mixed':
        case 'mixed-only':
        case 'prefix-date-mixed':
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            break;
        case 'prefix-lowercase':
        case 'lowercase-only':
        case 'prefix-date-lowercase':
            $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
            break;
        case 'prefix-numbers':
        case 'numbers-only':
        case 'prefix-date-numbers':
            $characters = '0123456789';
            break;
        case 'prefix-letters':
        case 'letters-only':
        case 'prefix-date-letters':
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            break;
        default:
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    }

    // Generate sample random string
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }

    switch ($format) {
        case 'prefix-random':
        case 'prefix-mixed':
        case 'prefix-lowercase':
        case 'prefix-numbers':
        case 'prefix-letters':
            return $prefix . $randomString;
        case 'random-only':
        case 'mixed-only':
        case 'lowercase-only':
        case 'numbers-only':
        case 'letters-only':
            return $randomString;
        case 'prefix-date-random':
        case 'prefix-date-mixed':
        case 'prefix-date-lowercase':
        case 'prefix-date-numbers':
        case 'prefix-date-letters':
            $currentDate = date('Ymd');
            return $prefix . $currentDate . $randomString;
        default:
            return $prefix . $randomString;
    }
}


// Active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'company';

// Validate active tab permissions and redirect if needed
$valid_tabs = [
    'company' => $can_manage_company,
    'currency' => $can_manage_currency,
    'system' => $can_manage_system,
    'inventory' => $can_manage_inventory,
    'appearance' => $can_manage_appearance,
    'email' => $can_manage_email,
    'security' => $can_manage_security,
    'receipt' => $can_manage_receipts
];

if (!isset($valid_tabs[$active_tab]) || !$valid_tabs[$active_tab]) {
    // Find first available tab
    foreach ($valid_tabs as $tab => $has_permission) {
        if ($has_permission) {
            $active_tab = $tab;
            break;
        }
    }
}

// Process form submission
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validation
    if (isset($_POST['company_name']) && empty(trim($_POST['company_name']))) {
        $errors[] = "Company name is required.";
    }
    
    if (isset($_POST['company_email']) && !empty($_POST['company_email']) && !filter_var($_POST['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    }
    
    if (isset($_POST['tax_rate'])) {
        $tax_rate = floatval($_POST['tax_rate']);
        if ($tax_rate < 0 || $tax_rate > 100) {
            $errors[] = "Tax rate must be between 0 and 100.";
        }
    }
    
    if (isset($_POST['currency_decimal_places'])) {
        $decimal_places = intval($_POST['currency_decimal_places']);
        if ($decimal_places < 0 || $decimal_places > 4) {
            $errors[] = "Currency decimal places must be between 0 and 4.";
        }
    }
    
    if (isset($_POST['low_stock_threshold'])) {
        $threshold = intval($_POST['low_stock_threshold']);
        if ($threshold < 0) {
            $errors[] = "Low stock threshold cannot be negative.";
        }
    }
    
    
    // SKU Settings Validation
    if (isset($_POST['sku_prefix'])) {
        $sku_prefix = trim($_POST['sku_prefix']);
        if (strlen($sku_prefix) > 10) {
            $errors[] = "SKU prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $sku_prefix)) {
            $errors[] = "SKU prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }
    
    if (isset($_POST['sku_length'])) {
        $sku_length = intval($_POST['sku_length']);
        if ($sku_length < 3 || $sku_length > 10) {
            $errors[] = "SKU length must be between 3 and 10 digits.";
        }
    }
    
    if (isset($_POST['sku_format'])) {
        $sku_format = trim($_POST['sku_format']);
        if (strlen($sku_format) > 20) {
            $errors[] = "SKU format cannot exceed 20 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_#]*$/', $sku_format)) {
            $errors[] = "SKU format can only contain letters, numbers, underscores, and hash symbols.";
        }
    }

    // Order Number Settings Validation
    if (isset($_POST['order_number_prefix'])) {
        $order_prefix = trim($_POST['order_number_prefix']);
        if (strlen($order_prefix) > 10) {
            $errors[] = "Order number prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $order_prefix)) {
            $errors[] = "Order number prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }

    if (isset($_POST['order_number_length'])) {
        $order_length = intval($_POST['order_number_length']);
        if ($order_length < 3 || $order_length > 10) {
            $errors[] = "Order number length must be between 3 and 10 digits.";
        }
    }

    if (isset($_POST['order_number_separator'])) {
        $order_separator = trim($_POST['order_number_separator']);
        if (strlen($order_separator) > 5) {
            $errors[] = "Order number separator cannot exceed 5 characters.";
        }
    }

    // Invoice Settings Validation
    if (isset($_POST['invoice_prefix'])) {
        $invoice_prefix = trim($_POST['invoice_prefix']);
        if (strlen($invoice_prefix) > 10) {
            $errors[] = "Invoice prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $invoice_prefix)) {
            $errors[] = "Invoice prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }

    if (isset($_POST['invoice_length'])) {
        $invoice_length = intval($_POST['invoice_length']);
        if ($invoice_length < 3 || $invoice_length > 10) {
            $errors[] = "Invoice length must be between 3 and 10 digits.";
        }
    }

    if (isset($_POST['invoice_separator'])) {
        $invoice_separator = trim($_POST['invoice_separator']);
        if (strlen($invoice_separator) > 5) {
            $errors[] = "Invoice separator cannot exceed 5 characters.";
        }
    }


    // Customer Number Settings Validation
    if (isset($_POST['customer_number_prefix'])) {
        $customer_prefix = trim($_POST['customer_number_prefix']);
        if (strlen($customer_prefix) > 10) {
            $errors[] = "Customer number prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $customer_prefix)) {
            $errors[] = "Customer number prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }

    if (isset($_POST['customer_number_length'])) {
        $customer_length = intval($_POST['customer_number_length']);
        if ($customer_length < 3 || $customer_length > 10) {
            $errors[] = "Customer number length must be between 3 and 10 digits.";
        }
    }

    if (isset($_POST['customer_number_separator'])) {
        $customer_separator = trim($_POST['customer_number_separator']);
        if (strlen($customer_separator) > 5) {
            $errors[] = "Customer number separator cannot exceed 5 characters.";
        }
    }

    // Receipt Settings Validation
    if (isset($_POST['receipt_width'])) {
        $receipt_width = intval($_POST['receipt_width']);
        if ($receipt_width < 58 || $receipt_width > 210) {
            $errors[] = "Receipt width must be between 58 and 210 millimeters.";
        }
    }

    if (isset($_POST['receipt_font_size'])) {
        $receipt_font_size = intval($_POST['receipt_font_size']);
        if ($receipt_font_size < 8 || $receipt_font_size > 20) {
            $errors[] = "Receipt font size must be between 8 and 20 pixels.";
        }
    }

    if (isset($_POST['receipt_number_prefix'])) {
        $receipt_prefix = trim($_POST['receipt_number_prefix']);
        if (strlen($receipt_prefix) > 10) {
            $errors[] = "Receipt number prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $receipt_prefix)) {
            $errors[] = "Receipt number prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }

    if (isset($_POST['receipt_number_length'])) {
        $receipt_length = intval($_POST['receipt_number_length']);
        if ($receipt_length < 3 || $receipt_length > 10) {
            $errors[] = "Receipt number length must be between 3 and 10 digits.";
        }
    }

    if (isset($_POST['receipt_number_separator'])) {
        $receipt_separator = trim($_POST['receipt_number_separator']);
        if (strlen($receipt_separator) > 3) {
            $errors[] = "Receipt number separator cannot exceed 3 characters.";
        }
    }

    if (isset($_POST['max_reprint_attempts'])) {
        $max_reprint = intval($_POST['max_reprint_attempts']);
        if ($max_reprint < 1 || $max_reprint > 10) {
            $errors[] = "Maximum reprint attempts must be between 1 and 10.";
        }
    }

    // Transaction ID settings validation
    if (isset($_POST['transaction_id_prefix'])) {
        $transaction_prefix = trim($_POST['transaction_id_prefix']);
        if (strlen($transaction_prefix) > 10) {
            $errors[] = "Transaction ID prefix cannot exceed 10 characters.";
        }
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $transaction_prefix)) {
            $errors[] = "Transaction ID prefix can only contain letters, numbers, hyphens, and underscores.";
        }
    }

    if (isset($_POST['transaction_id_length'])) {
        $transaction_length = intval($_POST['transaction_id_length']);
        if ($transaction_length < 3 || $transaction_length > 10) {
            $errors[] = "Transaction ID length must be between 3 and 10 digits.";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Handle checkbox values
            $checkbox_fields = ['enable_sound', 'allow_negative_stock', 'auto_generate_sku', 'auto_generate_order_number', 'invoice_auto_generate', 'auto_generate_customer_number', 'receipt_show_tax', 'receipt_show_discount', 'auto_print_receipt', 'auto_close_print_window', 'auto_generate_receipt_number', 'allow_receipt_reprint', 'require_password_for_reprint'];
            foreach($checkbox_fields as $field) {
                if (!isset($_POST[$field])) {
                    $_POST[$field] = '0';
                }
            }
            
            // Sanitize SMTP settings
            if (isset($_POST['smtp_host'])) {
                // Sanitize SMTP settings
                $smtp_settings = [
                    'smtp_host' => filter_var(trim($_POST['smtp_host'] ?? ''), FILTER_SANITIZE_STRING),
                    'smtp_port' => filter_var(trim($_POST['smtp_port'] ?? ''), FILTER_SANITIZE_NUMBER_INT),
                    'smtp_username' => filter_var(trim($_POST['smtp_username'] ?? ''), FILTER_SANITIZE_EMAIL),
                    'smtp_password' => trim($_POST['smtp_password'] ?? ''), // Don't sanitize password
                    'smtp_encryption' => filter_var(trim($_POST['smtp_encryption'] ?? 'tls'), FILTER_SANITIZE_STRING),
                    'smtp_from_email' => filter_var(trim($_POST['smtp_from_email'] ?? ''), FILTER_SANITIZE_EMAIL),
                    'smtp_from_name' => filter_var(trim($_POST['smtp_from_name'] ?? ''), FILTER_SANITIZE_STRING),
                    'email_verification_subject' => filter_var(trim($_POST['email_verification_subject'] ?? ''), FILTER_SANITIZE_STRING),
                    'email_verification_body' => filter_var(trim($_POST['email_verification_body'] ?? ''), FILTER_SANITIZE_STRING)
                ];
                
                // Validate email addresses
                if (!empty($smtp_settings['smtp_username']) && !filter_var($smtp_settings['smtp_username'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid SMTP username email format.";
                }
                
                if (!empty($smtp_settings['smtp_from_email']) && !filter_var($smtp_settings['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid from email address format.";
                }
                
                // Validate port number
                if (!empty($smtp_settings['smtp_port']) && (!is_numeric($smtp_settings['smtp_port']) || $smtp_settings['smtp_port'] < 1 || $smtp_settings['smtp_port'] > 65535)) {
                    $errors[] = "SMTP port must be a number between 1 and 65535.";
                }
                
                // Validate encryption method
                if (!in_array($smtp_settings['smtp_encryption'], ['tls', 'ssl', 'none'])) {
                    $errors[] = "Invalid encryption method selected.";
                }
                
                // Debug: Log SMTP settings being processed
                error_log("SMTP Settings being processed: " . print_r([
                    'smtp_host' => $smtp_settings['smtp_host'],
                    'smtp_port' => $smtp_settings['smtp_port'],
                    'smtp_username' => $smtp_settings['smtp_username'],
                    'smtp_password' => !empty($smtp_settings['smtp_password']) ? '***hidden***' : 'not set',
                    'smtp_encryption' => $smtp_settings['smtp_encryption'],
                    'smtp_from_email' => $smtp_settings['smtp_from_email'],
                    'smtp_from_name' => $smtp_settings['smtp_from_name']
                ], true));
            }
            
            // Handle file upload for company logo
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == UPLOAD_ERR_OK) {
                $upload_dir = '../../assets/images/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $filename = 'logo_' . time() . '.' . $file_extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $filepath)) {
                        $_POST['company_logo'] = 'assets/images/' . $filename;
                    }
                }
            }
            
            // Update or insert settings
            foreach($defaults as $key => $default) {
                if(isset($_POST[$key])) {
                    // Use sanitized values for SMTP settings
                    if (isset($smtp_settings) && array_key_exists($key, $smtp_settings)) {
                        $value = $smtp_settings[$key];
                    } else {
                        $value = trim($_POST[$key]);
                    }
                    
                    // Check if setting exists
                    $check = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = :key");
                    $check->bindParam(':key', $key);
                    $check->execute();
                    
                    if($check->fetchColumn() > 0) {
                        // Update
                        $stmt = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
                    } else {
                        // Insert
                        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)");
                    }
                    
                    $stmt->bindParam(':key', $key);
                    $stmt->bindParam(':value', $value);
                    $stmt->execute();
                    
                    // Update local array
                    $settings[$key] = $value;
                }
            }
            
            $conn->commit();
            
            // Check if SMTP settings were updated
            $smtp_updated = false;
            if (isset($_POST['smtp_host'])) {
                $smtp_updated = true;
            }
            
            if ($smtp_updated) {
                $_SESSION['success'] = "Email settings updated successfully! SMTP configuration has been saved.";
            } else {
                $_SESSION['success'] = "Settings updated successfully!";
            }
            
            header("Location: adminsetting.php?tab=" . $active_tab);
            exit();
            
        } catch(PDOException $e) {
            $conn->rollBack();
            $error = "Failed to update settings: " . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .color-picker-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .color-picker-group .form-control-color {
            width: 60px;
            height: 40px;
            padding: 2px;
            border: 1px solid #ddd;
        }
        
        .color-picker-group input[type="text"] {
            flex: 1;
            font-family: monospace;
        }
        
        .theme-preview {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .preview-container {
            min-height: 150px;
        }
        
        .preview-sidebar {
            width: 200px;
            background-color: var(--sidebar-color);
            border-right: 1px solid #ddd;
        }
        
        .preview-header {
            background-color: var(--primary-color);
            color: white;
            padding: 15px;
            margin: 0;
        }
        
        .preview-header h6 {
            margin: 0;
            color: white;
        }
        
        .preview-body {
            padding: 15px;
        }
        
        .preview-body button {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .settings-navigation {
            margin-bottom: 30px;
        }
        
        .management-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .management-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .management-card .card-body {
            padding: 1.5rem;
        }
        
        .management-card .bi {
            transition: transform 0.2s ease-in-out;
        }
        
        .management-card:hover .bi {
            transform: scale(1.1);
        }
        
        .management-card .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
        }
        
        .management-card .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            background: none;
            color: #6c757d;
            padding: 12px 20px;
        }
        
        .nav-tabs .nav-link:hover {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .nav-tabs .nav-link.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
            background: none;
        }
        
        .current-logo {
            display: inline-block;
        }
        
        .current-logo img {
            max-width: 200px;
            height: auto;
        }
        
        /* Email Settings Enhancements */
        .section-subtitle {
            font-size: 0.95rem;
            margin-top: 0.5rem;
        }
        
        .form-control-lg {
            border-radius: 0.5rem;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control-lg:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        
        .form-select-lg {
            border-radius: 0.5rem;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-select-lg:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }
        
        .input-group-lg .form-control {
            border-radius: 0.5rem 0 0 0.5rem;
        }
        
        .input-group-lg .btn {
            border-radius: 0 0.5rem 0.5rem 0;
        }
        
        .card-header {
            border-radius: 0.5rem 0.5rem 0 0 !important;
            border: none;
        }
        
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 0.5rem;
        }
        
        .form-label.fw-semibold {
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            border-radius: 0.5rem;
        }
        
        .alert-info {
            border: none;
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            border-radius: 0.5rem;
        }
        
        .form-group.mb-3 {
            margin-bottom: 1.5rem !important;
        }
        
        /* SMTP Status Blinking Animation */
        .smtp-connected {
            animation: blinkSuccess 2s ease-in-out 3;
        }
        
        @keyframes blinkSuccess {
            0%, 100% { 
                background-color: #d1e7dd; 
                border-color: #badbcc;
                color: #0f5132;
            }
            50% { 
                background-color: #a3d9a4; 
                border-color: #75b798;
                color: #0a3622;
                transform: scale(1.02);
            }
        }
        
        .smtp-status-icon {
            display: inline-block;
            margin-right: 8px;
        }
        
        .smtp-status-icon.connected {
            color: #198754;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        /* Test Email Modal Enhancements */
        .modal-content {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 0.75rem 0.75rem 0 0;
            border: none;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            border: none;
            padding: 1rem 2rem 2rem;
        }
        
        /* Separated Button Sections */
        .action-buttons-section h6 {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 0.75rem;
        }
        
        .action-buttons-section .btn {
            min-width: 140px;
        }
        
        .action-buttons-section .text-muted {
            font-size: 0.8rem;
            line-height: 1.3;
        }
        
        /* Responsive improvements */
        @media (max-width: 768px) {
            .btn-lg {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .d-flex.gap-2 {
                flex-direction: column;
                gap: 0.5rem !important;
            }
            
            .d-flex.flex-wrap.gap-3.justify-content-between {
                flex-direction: column;
                align-items: stretch !important;
            }
            
            .form-control-lg, .form-select-lg {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'settings';
    include __DIR__ . '/../../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Settings</h1>
                    <div class="header-subtitle">Manage system configuration and preferences</div>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php 
                    echo htmlspecialchars($_SESSION['success']); 
                    unset($_SESSION['success']);
                ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Settings Navigation -->
            <div class="settings-navigation">
                <div class="nav nav-tabs" role="tablist">
                    <?php if ($can_manage_company): ?>
                    <a href="?tab=company" class="nav-link <?php echo $active_tab == 'company' ? 'active' : ''; ?>">
                        <i class="bi bi-building me-2"></i>
                        Company Details
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_currency): ?>
                    <a href="?tab=currency" class="nav-link <?php echo $active_tab == 'currency' ? 'active' : ''; ?>">
                        <i class="bi bi-currency-exchange me-2"></i>
                        Currency & Tax
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_system): ?>
                    <a href="?tab=system" class="nav-link <?php echo $active_tab == 'system' ? 'active' : ''; ?>">
                        <i class="bi bi-gear me-2"></i>
                        System Settings
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_inventory): ?>
                    <a href="?tab=inventory" class="nav-link <?php echo $active_tab == 'inventory' ? 'active' : ''; ?>">
                        <i class="bi bi-boxes me-2"></i>
                        Inventory Settings
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_appearance): ?>
                    <a href="?tab=appearance" class="nav-link <?php echo $active_tab == 'appearance' ? 'active' : ''; ?>">
                        <i class="bi bi-palette me-2"></i>
                        Appearance
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_email): ?>
                    <a href="?tab=email" class="nav-link <?php echo $active_tab == 'email' ? 'active' : ''; ?>">
                        <i class="bi bi-envelope me-2"></i>
                        Email Settings
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_security): ?>
                    <a href="?tab=security" class="nav-link <?php echo $active_tab == 'security' ? 'active' : ''; ?>">
                        <i class="bi bi-shield-check me-2"></i>
                        Security Settings
                    </a>
                    <?php endif; ?>
                    <?php if ($can_manage_receipts): ?>
                    <a href="?tab=receipt" class="nav-link <?php echo $active_tab == 'receipt' ? 'active' : ''; ?>">
                        <i class="bi bi-receipt me-2"></i>
                        Receipt Settings
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Settings Content -->
            <div class="settings-content">
                <!-- Company Details Tab -->
                <?php if ($active_tab == 'company' && $can_manage_company): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-building me-2"></i>
                            Company Details
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="companyForm" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="company_name" class="form-label">Company Name *</label>
                            <input type="text" class="form-control" id="company_name" name="company_name" 
                                   value="<?php echo htmlspecialchars($settings['company_name']); ?>" required>
                            <div class="form-text">This name will appear on receipts.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="company_address" class="form-label">Company Address</label>
                            <textarea class="form-control" id="company_address" name="company_address" 
                                      rows="3" placeholder="Enter your company address"><?php echo htmlspecialchars($settings['company_address']); ?></textarea>
                            <div class="form-text">Full address including street, city, and postal code.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="company_phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="company_phone" name="company_phone" 
                                           value="<?php echo htmlspecialchars($settings['company_phone']); ?>" 
                                           placeholder="+254 700 000 000">
                                    <div class="form-text">Primary contact phone number.</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="company_email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="company_email" name="company_email" 
                                           value="<?php echo htmlspecialchars($settings['company_email']); ?>" 
                                           placeholder="contact@company.com">
                                    <div class="form-text">Primary contact email address.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="company_website" class="form-label">Website URL</label>
                            <input type="url" class="form-control" id="company_website" name="company_website" 
                                   value="<?php echo htmlspecialchars($settings['company_website']); ?>" 
                                   placeholder="https://www.company.com">
                            <div class="form-text">Company website URL.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="company_logo" class="form-label">Company Logo</label>
                            <?php if (!empty($settings['company_logo'])): ?>
                                <div class="current-logo mb-3">
                                    <img src="../../<?php echo htmlspecialchars($settings['company_logo']); ?>" 
                                         alt="Current Logo" class="img-thumbnail" style="max-height: 100px;">
                                    <div class="form-text">Current logo</div>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="company_logo" name="company_logo" 
                                   accept="image/jpeg,image/jpg,image/png,image/gif">
                            <div class="form-text">Upload company logo (JPG, PNG, GIF - Max 2MB). Will appear on receipts and throughout the system.</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Company Details
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Currency & Tax Tab -->
                <?php if ($active_tab == 'currency' && $can_manage_currency): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-currency-exchange me-2"></i>
                            Currency & Tax Settings
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="currencyForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="currency_symbol" class="form-label">Currency Symbol *</label>
                                    <input type="text" class="form-control" id="currency_symbol" name="currency_symbol" 
                                           value="<?php echo htmlspecialchars($settings['currency_symbol']); ?>" 
                                           required maxlength="10" placeholder="e.g., $, €, £, KES">
                                    <div class="form-text">This symbol will appear with prices throughout the system.</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="currency_position" class="form-label">Currency Position</label>
                                    <select class="form-control" id="currency_position" name="currency_position">
                                        <option value="before" <?php echo ($settings['currency_position'] ?? 'before') == 'before' ? 'selected' : ''; ?>>Before Amount ($ 100.00)</option>
                                        <option value="after" <?php echo ($settings['currency_position'] ?? 'before') == 'after' ? 'selected' : ''; ?>>After Amount (100.00 $)</option>
                                    </select>
                                    <div class="form-text">Where to display the currency symbol.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="currency_decimal_places" class="form-label">Decimal Places</label>
                            <select class="form-control" id="currency_decimal_places" name="currency_decimal_places">
                                <option value="0" <?php echo ($settings['currency_decimal_places'] ?? '2') == '0' ? 'selected' : ''; ?>>0 (100)</option>
                                <option value="1" <?php echo ($settings['currency_decimal_places'] ?? '2') == '1' ? 'selected' : ''; ?>>1 (100.0)</option>
                                <option value="2" <?php echo ($settings['currency_decimal_places'] ?? '2') == '2' ? 'selected' : ''; ?>>2 (100.00)</option>
                                <option value="3" <?php echo ($settings['currency_decimal_places'] ?? '2') == '3' ? 'selected' : ''; ?>>3 (100.000)</option>
                                <option value="4" <?php echo ($settings['currency_decimal_places'] ?? '2') == '4' ? 'selected' : ''; ?>>4 (100.0000)</option>
                            </select>
                            <div class="form-text">Number of decimal places to display for currency amounts.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="tax_rate" class="form-label">Default Tax Rate (%)</label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" min="0" max="100" class="form-control" 
                                               id="tax_rate" name="tax_rate" 
                                               value="<?php echo htmlspecialchars($settings['tax_rate']); ?>" 
                                               placeholder="0.00">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <div class="form-text">Default tax rate applied to sales.</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="tax_name" class="form-label">Tax Name</label>
                                    <input type="text" class="form-control" id="tax_name" name="tax_name" 
                                           value="<?php echo htmlspecialchars($settings['tax_name'] ?? 'VAT'); ?>" 
                                           placeholder="VAT, GST, Sales Tax, etc.">
                                    <div class="form-text">Name of the tax (e.g., VAT, GST).</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tax_registration_number" class="form-label">Tax Registration Number</label>
                            <input type="text" class="form-control" id="tax_registration_number" name="tax_registration_number" 
                                   value="<?php echo htmlspecialchars($settings['tax_registration_number'] ?? ''); ?>" 
                                   placeholder="Enter your tax registration number">
                            <div class="form-text">Your business tax registration number (will appear on receipts).</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Currency Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>


                <!-- System Settings Tab -->
                <?php if ($active_tab == 'system' && $can_manage_system): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-gear me-2"></i>
                            System Settings
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="systemForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="timezone" class="form-label">Timezone</label>
                                    <select class="form-control" id="timezone" name="timezone">
                                        <option value="Africa/Nairobi" <?php echo ($settings['timezone'] ?? 'Africa/Nairobi') == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (EAT)</option>
                                        <option value="UTC" <?php echo ($settings['timezone'] ?? 'Africa/Nairobi') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                        <option value="America/New_York" <?php echo ($settings['timezone'] ?? 'Africa/Nairobi') == 'America/New_York' ? 'selected' : ''; ?>>America/New_York (EST)</option>
                                        <option value="Europe/London" <?php echo ($settings['timezone'] ?? 'Africa/Nairobi') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                                        <option value="Asia/Tokyo" <?php echo ($settings['timezone'] ?? 'Africa/Nairobi') == 'Asia/Tokyo' ? 'selected' : ''; ?>>Asia/Tokyo (JST)</option>
                                    </select>
                                    <div class="form-text">System timezone for date and time display.</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="date_format" class="form-label">Date Format</label>
                                    <select class="form-control" id="date_format" name="date_format">
                                        <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (2024-01-15)</option>
                                        <option value="d/m/Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (15/01/2024)</option>
                                        <option value="m/d/Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (01/15/2024)</option>
                                        <option value="d-M-Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'd-M-Y' ? 'selected' : ''; ?>>DD-Mon-YYYY (15-Jan-2024)</option>
                                    </select>
                                    <div class="form-text">Format for displaying dates throughout the system.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="time_format" class="form-label">Time Format</label>
                            <select class="form-control" id="time_format" name="time_format">
                                <option value="H:i:s" <?php echo ($settings['time_format'] ?? 'H:i:s') == 'H:i:s' ? 'selected' : ''; ?>>24-hour (14:30:00)</option>
                                <option value="h:i:s A" <?php echo ($settings['time_format'] ?? 'H:i:s') == 'h:i:s A' ? 'selected' : ''; ?>>12-hour (02:30:00 PM)</option>
                                <option value="H:i" <?php echo ($settings['time_format'] ?? 'H:i:s') == 'H:i' ? 'selected' : ''; ?>>24-hour short (14:30)</option>
                                <option value="h:i A" <?php echo ($settings['time_format'] ?? 'H:i:s') == 'h:i A' ? 'selected' : ''; ?>>12-hour short (02:30 PM)</option>
                            </select>
                            <div class="form-text">Format for displaying time throughout the system.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="default_payment_method" class="form-label">Default Payment Method</label>
                            <select class="form-control" id="default_payment_method" name="default_payment_method">
                                <option value="cash" <?php echo ($settings['default_payment_method'] ?? 'cash') == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo ($settings['default_payment_method'] ?? 'cash') == 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="mobile_money" <?php echo ($settings['default_payment_method'] ?? 'cash') == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="bank_transfer" <?php echo ($settings['default_payment_method'] ?? 'cash') == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            </select>
                            <div class="form-text">Default payment method selected for new sales.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="backup_frequency" class="form-label">Backup Frequency</label>
                            <select class="form-control" id="backup_frequency" name="backup_frequency">
                                <option value="never" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'never' ? 'selected' : ''; ?>>Never</option>
                                <option value="daily" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                <option value="weekly" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo ($settings['backup_frequency'] ?? 'daily') == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                            </select>
                            <div class="form-text">How often to automatically backup the database.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="backup_retention_count" class="form-label">Backup Retention Count</label>
                            <input type="number" min="1" max="50" class="form-control" id="backup_retention_count" name="backup_retention_count" 
                                   value="<?php echo htmlspecialchars($settings['backup_retention_count'] ?? '10'); ?>" 
                                   placeholder="10">
                            <div class="form-text">Number of backup files to keep. Older backups will be automatically deleted.</div>
                        </div>
                        
                        <!-- Backup Status -->
                        <div class="form-group">
                            <label class="form-label">Backup Status</label>
                            <div class="card">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2">
                                                <strong>Last Backup:</strong><br>
                                                <span class="text-muted">
                                                    <?php 
                                                    if (isset($settings['last_backup_time']) && !empty($settings['last_backup_time'])) {
                                                        echo date('Y-m-d H:i:s', strtotime($settings['last_backup_time']));
                                                    } else {
                                                        echo 'Never';
                                                    }
                                                    ?>
                                                </span>
                                            </p>
                                            <p class="mb-2">
                                                <strong>Frequency:</strong><br>
                                                <span class="text-muted"><?php echo ucfirst($settings['backup_frequency'] ?? 'Daily'); ?></span>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-2">
                                                <strong>Total Backups:</strong><br>
                                                <span class="text-muted">
                                                    <?php
                                                    $backup_dir = __DIR__ . '/../../backups/database/';
                                                    $backup_count = 0;
                                                    if (is_dir($backup_dir)) {
                                                        $backup_files = glob($backup_dir . 'pos_system_*.sql');
                                                        $backup_count = count($backup_files);
                                                    }
                                                    echo $backup_count;
                                                    ?>
                                                </span>
                                            </p>
                                            <p class="mb-0">
                                                <strong>Storage Used:</strong><br>
                                                <span class="text-muted">
                                                    <?php
                                                    $total_size = 0;
                                                    if (is_dir($backup_dir)) {
                                                        $backup_files = glob($backup_dir . 'pos_system_*.sql');
                                                        foreach ($backup_files as $file) {
                                                            $total_size += filesize($file);
                                                        }
                                                    }
                                                    
                                                    function formatBytes($size, $precision = 2) {
                                                        $units = ['B', 'KB', 'MB', 'GB'];
                                                        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
                                                            $size /= 1024;
                                                        }
                                                        return round($size, $precision) . ' ' . $units[$i];
                                                    }
                                                    
                                                    echo formatBytes($total_size);
                                                    ?>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-12">
                                                                    <div class="d-flex gap-2">
                            <?php if ($can_manage_backup): ?>
                            <a href="../backup/create_backup.php" class="btn btn-sm btn-primary">
                                <i class="bi bi-download me-1"></i>Create Backup
                            </a>
                            <a href="../backup/manage_backups.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-database me-1"></i>Manage Backups
                            </a>
                            <a href="../backup/restore_backup.php" class="btn btn-sm btn-warning">
                                <i class="bi bi-upload me-1"></i>Restore
                            </a>
                            <?php else: ?>
                            <div class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i>
                                You don't have permission to manage backups
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="bi bi-shield-lock me-1"></i>
                                <strong>Security:</strong> All backup operations require password verification for your protection.
                            </small>
                        </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="enable_sound" name="enable_sound" value="1" 
                                       <?php echo (isset($settings['enable_sound']) && $settings['enable_sound'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enable_sound">
                                    Enable Sound Effects
                                </label>
                            </div>
                            <div class="form-text">Play sound effects for various system actions.</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save System Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Inventory Settings Tab -->
                <?php if ($active_tab == 'inventory' && $can_manage_inventory): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-boxes me-2"></i>
                            Inventory Settings
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="inventoryForm">
                        <div class="form-group">
                            <label for="low_stock_threshold" class="form-label">Low Stock Threshold</label>
                            <input type="number" min="0" class="form-control" id="low_stock_threshold" name="low_stock_threshold" 
                                   value="<?php echo htmlspecialchars($settings['low_stock_threshold'] ?? '10'); ?>" 
                                   placeholder="10">
                            <div class="form-text">Alert when product quantity falls below this number.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="barcode_type" class="form-label">Barcode Type</label>
                            <select class="form-control" id="barcode_type" name="barcode_type">
                                <option value="CODE128" <?php echo ($settings['barcode_type'] ?? 'CODE128') == 'CODE128' ? 'selected' : ''; ?>>CODE128</option>
                                <option value="CODE39" <?php echo ($settings['barcode_type'] ?? 'CODE128') == 'CODE39' ? 'selected' : ''; ?>>CODE39</option>
                                <option value="EAN13" <?php echo ($settings['barcode_type'] ?? 'CODE128') == 'EAN13' ? 'selected' : ''; ?>>EAN13</option>
                                <option value="UPC" <?php echo ($settings['barcode_type'] ?? 'CODE128') == 'UPC' ? 'selected' : ''; ?>>UPC</option>
                            </select>
                            <div class="form-text">Type of barcode to generate for products.</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="allow_negative_stock" name="allow_negative_stock" value="1" 
                                       <?php echo (isset($settings['allow_negative_stock']) && $settings['allow_negative_stock'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="allow_negative_stock">
                                    Allow Negative Stock
                                </label>
                            </div>
                            <div class="form-text">Allow selling products even when stock quantity is zero or negative.</div>
                        </div>
                        
                        <!-- SKU Settings Section -->
                        <div class="form-group">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-upc-scan me-2"></i>
                                SKU Code Settings
                            </h5>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_sku" name="auto_generate_sku" value="1"
                                       <?php echo (isset($settings['auto_generate_sku']) && $settings['auto_generate_sku'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_sku">
                                    Auto-generate SKU codes
                                </label>
                            </div>
                            <div class="form-text">Automatically generate SKU codes when adding new products.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="sku_prefix" class="form-label">SKU Prefix</label>
                                    <input type="text" class="form-control" id="sku_prefix" name="sku_prefix" 
                                           value="<?php echo htmlspecialchars($settings['sku_prefix'] ?? 'LIZ'); ?>" 
                                           placeholder="LIZ" maxlength="10">
                                    <div class="form-text">Prefix for all SKU codes (e.g., LIZ, MUS, PROD).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="sku_length" class="form-label">SKU Number Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="sku_length" name="sku_length" 
                                           value="<?php echo htmlspecialchars($settings['sku_length'] ?? '6'); ?>" 
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the SKU code (3-10).</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="sku_format" class="form-label">SKU Display Format</label>
                            <input type="text" class="form-control" id="sku_format" name="sku_format" 
                                   value="<?php echo htmlspecialchars($settings['sku_format'] ?? 'SKU000001'); ?>" 
                                   placeholder="SKU000001" maxlength="20">
                            <div class="form-text">Format for displaying SKU codes. Use # for number placeholders (e.g., SKU000001, ITEM-000001).</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="sku_separator" class="form-label">SKU Separator</label>
                            <input type="text" class="form-control" id="sku_separator" name="sku_separator" 
                                   value="<?php echo htmlspecialchars($settings['sku_separator'] ?? ''); ?>" 
                                   placeholder="-" maxlength="5">
                            <div class="form-text">Optional separator between prefix and numbers (e.g., -, _, space).</div>
                        </div>
                        
                        <div class="form-group">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>SKU Preview:</strong> 
                                <span id="skuPreview"><?php echo htmlspecialchars($settings['sku_prefix'] ?? 'LIZ') . ($settings['sku_separator'] ?? '') . str_pad('1', intval($settings['sku_length'] ?? '6'), '0', STR_PAD_LEFT); ?></span>
                            </div>
                        </div>

                        <!-- Order Number Settings Section -->
                        <div class="form-group mt-4">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-hash me-2"></i>
                                Order Number Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_order_number" name="auto_generate_order_number" value="1"
                                       <?php echo (isset($settings['auto_generate_order_number']) && $settings['auto_generate_order_number'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_order_number">
                                    Auto-generate Order Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate order numbers when creating new purchase orders.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="order_number_prefix" class="form-label">Order Number Prefix</label>
                                    <input type="text" class="form-control" id="order_number_prefix" name="order_number_prefix"
                                           value="<?php echo htmlspecialchars($settings['order_number_prefix'] ?? 'ORD'); ?>"
                                           placeholder="ORD" maxlength="10">
                                    <div class="form-text">Prefix for all order numbers (e.g., ORD, PO, PUR).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="order_number_length" class="form-label">Order Number Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="order_number_length" name="order_number_length"
                                           value="<?php echo htmlspecialchars($settings['order_number_length'] ?? '6'); ?>"
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the order number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="order_number_separator" class="form-label">Order Number Separator</label>
                                    <input type="text" class="form-control" id="order_number_separator" name="order_number_separator"
                                           value="<?php echo htmlspecialchars($settings['order_number_separator'] ?? '-'); ?>"
                                           placeholder="-" maxlength="5">
                                    <div class="form-text">Separator between prefix and numbers (e.g., -, _, space).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="order_number_format" class="form-label">Order Number Format</label>
                                    <select class="form-control" id="order_number_format" name="order_number_format">
                                        <option value="prefix-date-number" <?php echo ($settings['order_number_format'] ?? 'prefix-date-number') == 'prefix-date-number' ? 'selected' : ''; ?>>Prefix-Date-Number (ORD-20241201-000001)</option>
                                        <option value="prefix-number" <?php echo ($settings['order_number_format'] ?? 'prefix-date-number') == 'prefix-number' ? 'selected' : ''; ?>>Prefix-Number (ORD-000001)</option>
                                        <option value="date-prefix-number" <?php echo ($settings['order_number_format'] ?? 'prefix-date-number') == 'date-prefix-number' ? 'selected' : ''; ?>>Date-Prefix-Number (20241201-ORD-000001)</option>
                                        <option value="number-only" <?php echo ($settings['order_number_format'] ?? 'prefix-date-number') == 'number-only' ? 'selected' : ''; ?>>Number Only (000001)</option>
                                    </select>
                                    <div class="form-text">Format for generating order numbers.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="order_number_preview" class="form-label">Order Number Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="orderNumberPreview"><?php echo generateOrderNumberPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- Invoice Number Settings Section -->
                        <div class="form-group mt-5">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-file-earmark-text me-2"></i>
                                Invoice Number Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="invoice_auto_generate" name="invoice_auto_generate" value="1"
                                       <?php echo (isset($settings['invoice_auto_generate']) && $settings['invoice_auto_generate'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="invoice_auto_generate">
                                    Auto-generate Invoice Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate invoice numbers when creating purchase invoices.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_prefix" class="form-label">Invoice Number Prefix</label>
                                    <input type="text" class="form-control" id="invoice_prefix" name="invoice_prefix"
                                           value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'INV'); ?>"
                                           placeholder="INV" maxlength="10">
                                    <div class="form-text">Prefix for all invoice numbers (e.g., INV, PUR, BILL).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_length" class="form-label">Invoice Number Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="invoice_length" name="invoice_length"
                                           value="<?php echo htmlspecialchars($settings['invoice_length'] ?? '6'); ?>"
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the invoice number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_separator" class="form-label">Invoice Number Separator</label>
                                    <input type="text" class="form-control" id="invoice_separator" name="invoice_separator"
                                           value="<?php echo htmlspecialchars($settings['invoice_separator'] ?? '-'); ?>"
                                           placeholder="-" maxlength="5">
                                    <div class="form-text">Separator between prefix and numbers (e.g., -, _, space).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="invoice_format" class="form-label">Invoice Number Format</label>
                                    <select class="form-control" id="invoice_format" name="invoice_format">
                                        <option value="prefix-date-number" <?php echo ($settings['invoice_format'] ?? 'prefix-date-number') == 'prefix-date-number' ? 'selected' : ''; ?>>Prefix-Date-Number (INV-20241201-000001)</option>
                                        <option value="prefix-number" <?php echo ($settings['invoice_format'] ?? 'prefix-date-number') == 'prefix-number' ? 'selected' : ''; ?>>Prefix-Number (INV-000001)</option>
                                        <option value="date-prefix-number" <?php echo ($settings['invoice_format'] ?? 'prefix-date-number') == 'date-prefix-number' ? 'selected' : ''; ?>>Date-Prefix-Number (20241201-INV-000001)</option>
                                        <option value="number-only" <?php echo ($settings['invoice_format'] ?? 'prefix-date-number') == 'number-only' ? 'selected' : ''; ?>>Number Only (000001)</option>
                                    </select>
                                    <div class="form-text">Format for generating invoice numbers.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="invoice_number_preview" class="form-label">Invoice Number Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="invoiceNumberPreview"><?php echo generateInvoiceNumberPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- Product Number Settings Section -->
                        <div class="form-group mt-5">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-box me-2"></i>
                                Product Number Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_product_number" name="auto_generate_product_number" value="1"
                                       <?php echo (isset($settings['auto_generate_product_number']) && $settings['auto_generate_product_number'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_product_number">
                                    Auto-generate Product Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate product numbers when creating new products.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="product_number_prefix" class="form-label">Product Number Prefix</label>
                                    <input type="text" class="form-control" id="product_number_prefix" name="product_number_prefix"
                                           value="<?php echo htmlspecialchars($settings['product_number_prefix'] ?? 'PRD'); ?>"
                                           placeholder="PRD" maxlength="10">
                                    <div class="form-text">Prefix for all product numbers (e.g., PRD, ITEM, PROD).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="product_number_length" class="form-label">Product Number Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="product_number_length" name="product_number_length"
                                           value="<?php echo htmlspecialchars($settings['product_number_length'] ?? '6'); ?>"
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the product number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="product_number_separator" class="form-label">Product Number Separator</label>
                                    <input type="text" class="form-control" id="product_number_separator" name="product_number_separator"
                                           value="<?php echo htmlspecialchars($settings['product_number_separator'] ?? '-'); ?>"
                                           placeholder="-" maxlength="3">
                                    <div class="form-text">Separator character between prefix and number (e.g., -, _, .).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="product_number_format" class="form-label">Product Number Format</label>
                                    <select class="form-select" id="product_number_format" name="product_number_format">
                                        <option value="prefix-date-number" <?php echo ($settings['product_number_format'] ?? 'prefix-number') == 'prefix-date-number' ? 'selected' : ''; ?>>Prefix-Date-Number (PRD-20241201-000001)</option>
                                        <option value="prefix-number" <?php echo ($settings['product_number_format'] ?? 'prefix-number') == 'prefix-number' ? 'selected' : ''; ?>>Prefix-Number (PRD-000001)</option>
                                        <option value="date-prefix-number" <?php echo ($settings['product_number_format'] ?? 'prefix-number') == 'date-prefix-number' ? 'selected' : ''; ?>>Date-Prefix-Number (20241201-PRD-000001)</option>
                                        <option value="number-only" <?php echo ($settings['product_number_format'] ?? 'prefix-number') == 'number-only' ? 'selected' : ''; ?>>Number Only (000001)</option>
                                    </select>
                                    <div class="form-text">Format for generating product numbers.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="product_number_preview" class="form-label">Product Number Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="productNumberPreview"><?php echo generateProductNumberPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- BOM Settings Section -->
                        <div class="form-group mt-5">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-diagram-3 me-2"></i>
                                Bill of Materials (BOM) Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_bom_number" name="auto_generate_bom_number" value="1"
                                       <?php echo (isset($settings['auto_generate_bom_number']) && $settings['auto_generate_bom_number'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_bom_number">
                                    Auto-generate BOM Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate BOM numbers when creating new bills of materials.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_number_prefix" class="form-label">BOM Number Prefix</label>
                                    <input type="text" class="form-control" id="bom_number_prefix" name="bom_number_prefix"
                                           value="<?php echo htmlspecialchars($settings['bom_number_prefix'] ?? 'BOM'); ?>"
                                           placeholder="BOM" maxlength="10">
                                    <div class="form-text">Prefix for all BOM numbers (e.g., BOM, RECIPE, FORMULA).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_number_length" class="form-label">BOM Number Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="bom_number_length" name="bom_number_length"
                                           value="<?php echo htmlspecialchars($settings['bom_number_length'] ?? '6'); ?>"
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the BOM number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_number_separator" class="form-label">BOM Number Separator</label>
                                    <input type="text" class="form-control" id="bom_number_separator" name="bom_number_separator"
                                           value="<?php echo htmlspecialchars($settings['bom_number_separator'] ?? '-'); ?>"
                                           placeholder="-" maxlength="5">
                                    <div class="form-text">Separator between prefix and numbers (e.g., -, _, space).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_number_format" class="form-label">BOM Number Format</label>
                                    <select class="form-control" id="bom_number_format" name="bom_number_format">
                                        <option value="prefix-date-number" <?php echo ($settings['bom_number_format'] ?? 'prefix-date-number') == 'prefix-date-number' ? 'selected' : ''; ?>>Prefix-Date-Number (BOM-20241201-000001)</option>
                                        <option value="prefix-number" <?php echo ($settings['bom_number_format'] ?? 'prefix-date-number') == 'prefix-number' ? 'selected' : ''; ?>>Prefix-Number (BOM-000001)</option>
                                        <option value="date-prefix-number" <?php echo ($settings['bom_number_format'] ?? 'prefix-date-number') == 'date-prefix-number' ? 'selected' : ''; ?>>Date-Prefix-Number (20241201-BOM-000001)</option>
                                        <option value="number-only" <?php echo ($settings['bom_number_format'] ?? 'prefix-date-number') == 'number-only' ? 'selected' : ''; ?>>Number Only (000001)</option>
                                    </select>
                                    <div class="form-text">Format for generating BOM numbers.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="bom_number_preview" class="form-label">BOM Number Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="bomNumberPreview"><?php echo generateBOMNumberPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- Customer Number Settings Section -->
                        <div class="form-group mt-5">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-person-badge me-2"></i>
                                Customer Number Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_customer_number" name="auto_generate_customer_number" value="1"
                                       <?php echo (isset($settings['auto_generate_customer_number']) && $settings['auto_generate_customer_number'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_customer_number">
                                    Auto-generate Customer Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate customer numbers when creating new customers.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="customer_number_prefix" class="form-label">Customer Number Prefix</label>
                                    <input type="text" class="form-control" id="customer_number_prefix" name="customer_number_prefix"
                                           value="<?php echo htmlspecialchars($settings['customer_number_prefix'] ?? 'CUST'); ?>"
                                           placeholder="CUST" maxlength="10">
                                    <div class="form-text">Prefix for all customer numbers (e.g., CUST, CLIENT, C).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="customer_number_length" class="form-label">Number Length</label>
                                    <input type="number" class="form-control" id="customer_number_length" name="customer_number_length"
                                           value="<?php echo htmlspecialchars($settings['customer_number_length'] ?? '6'); ?>"
                                           min="3" max="10">
                                    <div class="form-text">Number of digits in the customer number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="customer_number_separator" class="form-label">Separator</label>
                                    <input type="text" class="form-control" id="customer_number_separator" name="customer_number_separator"
                                           value="<?php echo htmlspecialchars($settings['customer_number_separator'] ?? '-'); ?>"
                                           placeholder="-" maxlength="5">
                                    <div class="form-text">Character to separate prefix, date, and number (e.g., -, _, #).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="customer_number_format" class="form-label">Number Format</label>
                                    <select class="form-select" id="customer_number_format" name="customer_number_format">
                                        <option value="prefix-date-number" <?php echo ($settings['customer_number_format'] ?? 'prefix-date-number') === 'prefix-date-number' ? 'selected' : ''; ?>>
                                            Prefix + Date + Number (CUST-20241201-000001)
                                        </option>
                                        <option value="prefix-number" <?php echo ($settings['customer_number_format'] ?? '') === 'prefix-number' ? 'selected' : ''; ?>>
                                            Prefix + Number (CUST-000001)
                                        </option>
                                        <option value="date-prefix-number" <?php echo ($settings['customer_number_format'] ?? '') === 'date-prefix-number' ? 'selected' : ''; ?>>
                                            Date + Prefix + Number (20241201-CUST-000001)
                                        </option>
                                        <option value="number-only" <?php echo ($settings['customer_number_format'] ?? '') === 'number-only' ? 'selected' : ''; ?>>
                                            Number Only (000001)
                                        </option>
                                    </select>
                                    <div class="form-text">Choose how customer numbers are formatted.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="customer_number_preview" class="form-label">Customer Number Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="customerNumberPreview"><?php echo generateCustomerNumberPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- Production Order Settings Section -->
                        <div class="form-group mt-4">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-gear me-2"></i>
                                Production Order Settings
                            </h5>
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_generate_production_order" name="auto_generate_production_order" value="1"
                                       <?php echo (isset($settings['auto_generate_production_order']) && $settings['auto_generate_production_order'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_production_order">
                                    Auto-generate Production Order Numbers
                                </label>
                            </div>
                            <div class="form-text">Automatically generate production order numbers when creating production orders.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_production_order_prefix" class="form-label">Production Order Prefix</label>
                                    <input type="text" class="form-control" id="bom_production_order_prefix" name="bom_production_order_prefix"
                                           value="<?php echo htmlspecialchars($settings['bom_production_order_prefix'] ?? 'PROD'); ?>"
                                           placeholder="PROD" maxlength="10">
                                    <div class="form-text">Prefix for all production order numbers (e.g., PROD, PO, WORK).</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_production_order_length" class="form-label">Production Order Length</label>
                                    <input type="number" min="3" max="10" class="form-control" id="bom_production_order_length" name="bom_production_order_length"
                                           value="<?php echo htmlspecialchars($settings['bom_production_order_length'] ?? '6'); ?>"
                                           placeholder="6">
                                    <div class="form-text">Number of digits in the production order number (3-10).</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="bom_production_order_preview" class="form-label">Production Order Preview</label>
                            <div class="alert alert-info">
                                <i class="bi bi-eye me-2"></i>
                                <strong>Preview:</strong>
                                <span id="productionOrderPreview"><?php echo generateProductionOrderPreview($settings); ?></span>
                            </div>
                        </div>

                        <!-- BOM Cost Calculation Settings -->
                        <div class="form-group mt-4">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-calculator me-2"></i>
                                BOM Cost Calculation Settings
                            </h5>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_cost_calculation_method" class="form-label">Cost Calculation Method</label>
                                    <select class="form-control" id="bom_cost_calculation_method" name="bom_cost_calculation_method">
                                        <option value="standard" <?php echo ($settings['bom_cost_calculation_method'] ?? 'standard') == 'standard' ? 'selected' : ''; ?>>Standard Costing</option>
                                        <option value="actual" <?php echo ($settings['bom_cost_calculation_method'] ?? 'standard') == 'actual' ? 'selected' : ''; ?>>Actual Costing</option>
                                        <option value="average" <?php echo ($settings['bom_cost_calculation_method'] ?? 'standard') == 'average' ? 'selected' : ''; ?>>Average Costing</option>
                                    </select>
                                    <div class="form-text">Method used to calculate component costs in BOMs.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="bom_default_waste_percentage" class="form-label">Default Waste Percentage (%)</label>
                                    <input type="number" step="0.1" min="0" max="50" class="form-control" id="bom_default_waste_percentage" name="bom_default_waste_percentage"
                                           value="<?php echo htmlspecialchars($settings['bom_default_waste_percentage'] ?? '5.0'); ?>"
                                           placeholder="5.0">
                                    <div class="form-text">Default waste/loss percentage for BOM components (0-50).</div>
                                </div>
                            </div>
                        </div>

                        <!-- Brand and Supplier Management Section -->
                        <div class="form-group">
                            <h5 class="mb-3 text-primary">
                                <i class="bi bi-gear me-2"></i>
                                Related Management
                            </h5>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card border-primary management-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-star text-primary" style="font-size: 2rem;"></i>
                                        <h6 class="card-title mt-2">Brand Management</h6>
                                        <p class="card-text text-muted">Manage product brands, categories, and organization</p>
                                        <a href="../../brands/brands.php" class="btn btn-outline-primary">
                                            <i class="bi bi-arrow-right"></i>
                                            Manage Brands
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-success management-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-truck text-success" style="font-size: 2rem;"></i>
                                        <h6 class="card-title mt-2">Supplier Management</h6>
                                        <p class="card-text text-muted">Manage suppliers, contacts, and procurement</p>
                                        <a href="../../suppliers/suppliers.php" class="btn btn-outline-success">
                                            <i class="bi bi-arrow-right"></i>
                                            Manage Suppliers
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-info management-card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-diagram-3 text-info" style="font-size: 2rem;"></i>
                                        <h6 class="card-title mt-2">BOM Management</h6>
                                        <p class="card-text text-muted">Manage bills of materials and production recipes</p>
                                        <a href="../../bom/index.php" class="btn btn-outline-info">
                                            <i class="bi bi-arrow-right"></i>
                                            Manage BOMs
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group mt-4">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Inventory Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Appearance Tab -->
                <?php if ($active_tab == 'appearance' && $can_manage_appearance): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-palette me-2"></i>
                            Appearance Settings
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="appearanceForm">
                        <div class="form-group">
                            <label for="theme_color" class="form-label">Primary Theme Color</label>
                            <div class="color-picker-group">
                                <input type="color" class="form-control form-control-color" id="theme_color" 
                                       name="theme_color" value="<?php echo htmlspecialchars($settings['theme_color']); ?>">
                                <input type="text" class="form-control" id="theme_color_hex" 
                                       value="<?php echo htmlspecialchars($settings['theme_color']); ?>" readonly>
                            </div>
                            <div class="form-text">Select the primary color for buttons, links, and highlights throughout the system.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="sidebar_color" class="form-label">Sidebar Color</label>
                            <div class="color-picker-group">
                                <input type="color" class="form-control form-control-color" id="sidebar_color" 
                                       name="sidebar_color" value="<?php echo htmlspecialchars($settings['sidebar_color']); ?>">
                                <input type="text" class="form-control" id="sidebar_color_hex" 
                                       value="<?php echo htmlspecialchars($settings['sidebar_color']); ?>" readonly>
                            </div>
                            <div class="form-text">Select the background color for the sidebar navigation.</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Theme Preview</label>
                            <div class="theme-preview">
                                <div class="preview-container d-flex">
                                    <div class="preview-sidebar" id="previewSidebar">
                                        <div class="p-3">
                                            <h6 class="text-white">Sidebar</h6>
                                            <div class="nav-item text-white-50">Menu Item</div>
                                        </div>
                                    </div>
                                    <div class="preview-card flex-grow-1">
                                    <div class="preview-header" id="previewHeader">
                                        <h6>Sample Card Header</h6>
                                    </div>
                                    <div class="preview-body">
                                            <p>This shows how your theme colors will appear in the interface.</p>
                                        <button class="btn btn-sm" id="previewButton">Sample Button</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Appearance Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Email Settings Tab -->
                <?php if ($active_tab == 'email' && $can_manage_email): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-envelope me-2"></i>
                            Email Settings
                        </h3>
                        <p class="section-subtitle text-muted">Configure SMTP settings for sending emails from the system</p>
                    </div>

                    <!-- SMTP Status Indicator -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="alert alert-light border" id="smtpStatusAlert">
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm me-3" role="status" id="smtpStatusSpinner">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <i class="bi bi-check-circle-fill smtp-status-icon" id="smtpStatusIcon" style="display: none;"></i>
                                    <div>
                                        <strong>SMTP Status:</strong> <span id="smtpStatusText">Please configure your SMTP settings</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="" class="settings-form" id="emailForm">
                        <!-- SMTP Server Configuration -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-server me-2"></i>
                                    SMTP Server Configuration
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_host" class="form-label fw-semibold">
                                                <i class="bi bi-globe me-1"></i>SMTP Host *
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="smtp_host" name="smtp_host"
                                                   value="<?php echo htmlspecialchars($settings['smtp_host'] ?? 'smtp.gmail.com'); ?>"
                                                   required placeholder="smtp.gmail.com">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>SMTP server hostname or IP address
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_port" class="form-label fw-semibold">
                                                <i class="bi bi-hash me-1"></i>SMTP Port *
                                            </label>
                                            <input type="number" class="form-control form-control-lg" id="smtp_port" name="smtp_port"
                                                   value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>"
                                                   required placeholder="587" min="1" max="65535">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>587 for TLS, 465 for SSL, 25 for non-encrypted
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Authentication Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-shield-lock me-2"></i>
                                    Authentication Settings
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_username" class="form-label fw-semibold">
                                                <i class="bi bi-person me-1"></i>SMTP Username
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="smtp_username" name="smtp_username"
                                                   value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>"
                                                   placeholder="your-email@gmail.com">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Username for SMTP authentication (usually your email)
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_password" class="form-label fw-semibold">
                                                <i class="bi bi-key me-1"></i>SMTP Password
                                            </label>
                                            <div class="input-group input-group-lg">
                                                <input type="password" class="form-control" id="smtp_password" name="smtp_password"
                                                       value="<?php echo htmlspecialchars($settings['smtp_password'] ?? ''); ?>"
                                                       placeholder="Enter your SMTP password">
                                                <button class="btn btn-outline-secondary" type="button" id="toggleSmtpPassword" title="Toggle password visibility">
                                                    <i class="bi bi-eye" id="smtpPasswordIcon"></i>
                                                </button>
                                            </div>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Password for SMTP authentication
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Security & Sender Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="bi bi-gear me-2"></i>
                                    Security & Sender Settings
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_encryption" class="form-label fw-semibold">
                                                <i class="bi bi-shield-check me-1"></i>Encryption Method
                                            </label>
                                            <select class="form-select form-select-lg" id="smtp_encryption" name="smtp_encryption">
                                                <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') == 'tls' ? 'selected' : ''; ?>>
                                                    <i class="bi bi-shield-lock me-1"></i>TLS (Recommended)
                                                </option>
                                                <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? 'tls') == 'ssl' ? 'selected' : ''; ?>>
                                                    <i class="bi bi-shield me-1"></i>SSL
                                                </option>
                                                <option value="none" <?php echo ($settings['smtp_encryption'] ?? 'tls') == 'none' ? 'selected' : ''; ?>>
                                                    <i class="bi bi-shield-exclamation me-1"></i>None (Not Recommended)
                                                </option>
                                            </select>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Encryption method for SMTP connection
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="smtp_from_email" class="form-label fw-semibold">
                                                <i class="bi bi-envelope me-1"></i>From Email Address
                                            </label>
                                            <input type="email" class="form-control form-control-lg" id="smtp_from_email" name="smtp_from_email"
                                                   value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>"
                                                   placeholder="noreply@yourcompany.com">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Email address used as sender (From field)
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="form-group mb-3">
                                            <label for="smtp_from_name" class="form-label fw-semibold">
                                                <i class="bi bi-person-badge me-1"></i>From Name
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="smtp_from_name" name="smtp_from_name"
                                                   value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? 'POS System'); ?>"
                                                   placeholder="Your Company Name">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Name displayed as sender in emails
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <!-- Save Settings Section -->
                                    <div class="col-md-6 action-buttons-section">
                                        <h6 class="text-muted mb-3">
                                            <i class="bi bi-gear me-2"></i>Configuration
                                        </h6>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-save me-2"></i>
                                                Save Settings
                                            </button>
                                            <button type="reset" class="btn btn-outline-secondary btn-lg">
                                                <i class="bi bi-arrow-clockwise me-2"></i>
                                                Reset
                                            </button>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Save your SMTP configuration
                                        </div>
                                    </div>
                                    
                                    <!-- Test Email Section -->
                                    <div class="col-md-6 action-buttons-section">
                                        <h6 class="text-muted mb-3">
                                            <i class="bi bi-send me-2"></i>Testing
                                        </h6>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-info btn-lg" onclick="openTestEmailModal()">
                                                <i class="bi bi-envelope me-2"></i>
                                                Test Email
                                            </button>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Send a test email to verify configuration
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Test Email Template Section -->
                    <div class="card mt-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-envelope-check me-2"></i>
                                Test Email Template
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="bi bi-lightbulb me-2"></i>
                                <strong>Simple Test Email:</strong> Use this to test if your SMTP configuration is working correctly.
                            </div>
                            
                            <!-- Test Email Message Area -->
                            <div id="testEmailMessageArea" style="display: none;">
                                <div id="testEmailAlert" class="alert" role="alert">
                                    <div class="d-flex align-items-start">
                                        <i id="testEmailIcon" class="me-2" style="font-size: 1.2rem; margin-top: 2px;"></i>
                                        <div>
                                            <strong id="testEmailTitle"></strong>
                                            <p id="testEmailMessage" class="mb-0"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group mb-3">
                                        <label for="test_email_address" class="form-label fw-semibold">
                                            <i class="bi bi-envelope me-1"></i>Test Email Address
                                        </label>
                                        <input type="email" class="form-control form-control-lg" id="test_email_address_simple" 
                                               placeholder="test@example.com">
                                        <div class="form-text">
                                            <i class="bi bi-info-circle me-1"></i>Enter the email address where you want to receive the test email
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="button" class="btn btn-info btn-lg w-100" id="sendTestEmailBtn" onclick="sendSimpleTestEmail()">
                                        <i class="bi bi-send me-2"></i>
                                        Send Test Email
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <h6 class="text-muted">Test Email Preview:</h6>
                                <div class="border rounded p-3 bg-light">
                                    <div class="fw-bold">Subject:</div>
                                    <div class="mb-2">SMTP Configuration Test - <?php echo date('Y-m-d H:i:s'); ?></div>
                                    
                                    <div class="fw-bold">Message:</div>
                                    <div class="text-muted">
                                        <p class="mb-1">✅ <strong>SMTP Test Successful!</strong></p>
                                        <p class="mb-1">If you receive this email, your SMTP configuration is working correctly.</p>
                                        <p class="mb-0">Test sent on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Email Modal -->
                    <div class="modal fade" id="testEmailModal" tabindex="-1" aria-labelledby="testEmailModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="testEmailModalLabel">
                                        <i class="bi bi-send me-2"></i>Test Email Configuration
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i>
                                        <strong>Test Email:</strong> Enter an email address to send a test email using your current SMTP configuration. Make sure your SMTP settings are configured first.
                                    </div>
                                    
                                    <form id="testEmailForm">
                                        <div class="form-group mb-3">
                                            <label for="test_email_address" class="form-label fw-semibold">
                                                <i class="bi bi-envelope me-1"></i>Test Email Address *
                                            </label>
                                            <input type="email" class="form-control form-control-lg" id="test_email_address" 
                                                   name="test_email_address" placeholder="test@example.com" required>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Enter the email address where you want to receive the test email
                                            </div>
                                        </div>
                                        
                                        <div class="form-group mb-3">
                                            <label for="test_email_subject" class="form-label fw-semibold">
                                                <i class="bi bi-tag me-1"></i>Test Subject (Optional)
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="test_email_subject" 
                                                   name="test_email_subject" placeholder="SMTP Test Email" 
                                                   value="SMTP Configuration Test - <?php echo date('Y-m-d H:i:s'); ?>">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Subject line for the test email
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x me-1"></i>Cancel
                                    </button>
                                    <button type="button" class="btn btn-info" onclick="sendTestEmail()">
                                        <i class="bi bi-send me-1"></i>Send Test Email
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Email Templates Section -->
                    <div class="mt-5">
                        <h4 class="mb-3">
                            <i class="bi bi-file-earmark-text me-2"></i>
                            Email Templates
                        </h4>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-person-plus text-success" style="font-size: 2rem;"></i>
                                        <h6 class="mt-2">Signup Verification</h6>
                                        <p class="text-muted small">Email sent when user registers</p>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate('signup_verification')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-key text-warning" style="font-size: 2rem;"></i>
                                        <h6 class="mt-2">Password Reset</h6>
                                        <p class="text-muted small">Email for password recovery</p>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate('password_reset')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-check-circle text-info" style="font-size: 2rem;"></i>
                                        <h6 class="mt-2">Welcome Email</h6>
                                        <p class="text-muted small">Sent after successful registration</p>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate('welcome_email')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <i class="bi bi-envelope-check text-primary" style="font-size: 2rem;"></i>
                                        <h6 class="mt-2">Email Verification</h6>
                                        <p class="text-muted small">OTP verification email</p>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editTemplate('email_verification')">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Short Codes Reference -->
                    <div class="mt-4">
                        <h5 class="mb-3">
                            <i class="bi bi-code-slash me-2"></i>
                            Available Short Codes
                        </h5>
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>User Information:</h6>
                                        <ul class="list-unstyled">
                                            <li><code>{username}</code> - User's username</li>
                                            <li><code>{email}</code> - User's email address</li>
                                            <li><code>{first_name}</code> - User's first name</li>
                                            <li><code>{last_name}</code> - User's last name</li>
                                            <li><code>{full_name}</code> - User's full name</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>System Information:</h6>
                                        <ul class="list-unstyled">
                                            <li><code>{company_name}</code> - Company name</li>
                                            <li><code>{site_url}</code> - Website URL</li>
                                            <li><code>{current_year}</code> - Current year</li>
                                            <li><code>{reset_link}</code> - Password reset link</li>
                                            <li><code>{verification_link}</code> - Email verification link</li>
                                            <li><code>{otp_code}</code> - 6-digit verification code</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Security Settings Tab -->
                <?php if ($active_tab == 'security' && $can_manage_security): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-shield-check me-2"></i>
                            Security Settings & Monitoring
                        </h3>
                    </div>

                    <!-- Security Overview -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="bi bi-person-check text-success" style="font-size: 2rem;"></i>
                                    <h4 class="mt-2">
                                        <?php
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE account_locked = 0");
                                        echo $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                        ?>
                                    </h4>
                                    <p class="text-muted mb-0">Active Users</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="bi bi-person-lock text-warning" style="font-size: 2rem;"></i>
                                    <h4 class="mt-2">
                                        <?php
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE account_locked = 1");
                                        echo $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                        ?>
                                    </h4>
                                    <p class="text-muted mb-0">Locked Accounts</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="bi bi-shield-exclamation text-danger" style="font-size: 2rem;"></i>
                                    <h4 class="mt-2">
                                        <?php
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM login_attempts WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                                        echo $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                        ?>
                                    </h4>
                                    <p class="text-muted mb-0">Failed Logins (24h)</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="bi bi-clock text-info" style="font-size: 2rem;"></i>
                                    <h4 class="mt-2">
                                        <?php
                                        $stmt = $conn->query("SELECT COUNT(*) as count FROM login_attempts WHERE success = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                                        echo $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                        ?>
                                    </h4>
                                    <p class="text-muted mb-0">Successful Logins (24h)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Security Settings Form -->
                    <form method="POST" action="" class="settings-form" id="securityForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                                    <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts"
                                           value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? '5'); ?>"
                                           min="3" max="20" required>
                                    <div class="form-text">Maximum failed login attempts before account lockout.</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="lockout_duration" class="form-label">Lockout Duration (minutes)</label>
                                    <input type="number" class="form-control" id="lockout_duration" name="lockout_duration"
                                           value="<?php echo htmlspecialchars($settings['lockout_duration'] ?? '30'); ?>"
                                           min="5" max="1440" required>
                                    <div class="form-text">How long to lock account after failed attempts.</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="rate_limit_attempts" class="form-label">Rate Limit Attempts</label>
                                    <input type="number" class="form-control" id="rate_limit_attempts" name="rate_limit_attempts"
                                           value="<?php echo htmlspecialchars($settings['rate_limit_attempts'] ?? '5'); ?>"
                                           min="3" max="50" required>
                                    <div class="form-text">Maximum login attempts per IP within time window.</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="rate_limit_window" class="form-label">Rate Limit Window (minutes)</label>
                                    <input type="number" class="form-control" id="rate_limit_window" name="rate_limit_window"
                                           value="<?php echo htmlspecialchars($settings['rate_limit_window'] ?? '15'); ?>"
                                           min="5" max="1440" required>
                                    <div class="form-text">Time window for rate limiting in minutes.</div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="session_timeout" class="form-label">Session Timeout (minutes)</label>
                                    <input type="number" class="form-control" id="session_timeout" name="session_timeout"
                                           value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>"
                                           min="5" max="480" required>
                                    <div class="form-text">User session timeout due to inactivity.</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password_min_length" class="form-label">Minimum Password Length</label>
                                    <input type="number" class="form-control" id="password_min_length" name="password_min_length"
                                           value="<?php echo htmlspecialchars($settings['password_min_length'] ?? '8'); ?>"
                                           min="6" max="32" required>
                                    <div class="form-text">Minimum characters required for passwords.</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Security Options</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="enable_ip_check" name="enable_ip_check" value="1"
                                       <?php echo (isset($settings['enable_ip_check']) && $settings['enable_ip_check'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enable_ip_check">
                                    Enable IP Address Consistency Check
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="enable_ua_check" name="enable_ua_check" value="1"
                                       <?php echo (isset($settings['enable_ua_check']) && $settings['enable_ua_check'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enable_ua_check">
                                    Enable User-Agent Consistency Check
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="log_security_events" name="log_security_events" value="1"
                                       <?php echo (isset($settings['log_security_events']) && $settings['log_security_events'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="log_security_events">
                                    Log Security Events
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Security Settings
                                </button>
                                <?php if (hasPermission('manage_system_cleanup', $permissions)): ?>
                                <button type="button" class="btn btn-info" onclick="clearSecurityLogs()">
                                    <i class="bi bi-trash"></i>
                                    Clear Old Logs (30+ days)
                                </button>
                                <?php endif; ?>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Recent Login Attempts -->
                    <div class="mt-5">
                        <h4 class="mb-3">
                            <i class="bi bi-clock-history me-2"></i>
                            Recent Login Attempts
                        </h4>

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Identifier</th>
                                        <th>Type</th>
                                        <th>IP Address</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $login_attempts = getRecentLoginAttempts($conn, null, null, 20);
                                    if (empty($login_attempts)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No login attempts found</td>
                                        </tr>
                                    <?php else:
                                        foreach ($login_attempts as $attempt): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($attempt['identifier']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $attempt['attempt_type'] == 'email' ? 'primary' : 'info'; ?>">
                                                        <?php echo ucfirst($attempt['attempt_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($attempt['ip_address']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $attempt['success'] ? 'success' : 'danger'; ?>">
                                                        <?php echo $attempt['success'] ? 'Success' : 'Failed'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M d, H:i', strtotime($attempt['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Receipt Settings Tab -->
                <?php if ($active_tab == 'receipt' && $can_manage_receipts): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-receipt me-2"></i>
                            Receipt Settings
                        </h3>
                        <p class="section-subtitle text-muted">Configure receipt generation and formatting options</p>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="receiptForm">
                        <!-- Receipt Display Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-display me-2"></i>
                                    Receipt Display Settings
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_header" class="form-label fw-semibold">
                                                <i class="bi bi-file-earmark-text me-1"></i>Receipt Header
                                            </label>
                                            <textarea class="form-control" id="receipt_header" name="receipt_header" rows="3" 
                                                      placeholder="Enter receipt header text"><?php echo htmlspecialchars($settings['receipt_header'] ?? ''); ?></textarea>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Text displayed at the top of receipts
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_footer" class="form-label fw-semibold">
                                                <i class="bi bi-file-earmark-text me-1"></i>Receipt Footer
                                            </label>
                                            <textarea class="form-control" id="receipt_footer" name="receipt_footer" rows="3" 
                                                      placeholder="Enter receipt footer text"><?php echo htmlspecialchars($settings['receipt_footer'] ?? ''); ?></textarea>
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Text displayed at the bottom of receipts
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_contact" class="form-label fw-semibold">
                                                <i class="bi bi-telephone me-1"></i>Contact Information
                                            </label>
                                            <input type="text" class="form-control" id="receipt_contact" name="receipt_contact" 
                                                   value="<?php echo htmlspecialchars($settings['receipt_contact'] ?? ''); ?>" 
                                                   placeholder="Phone, email, or website">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Contact information shown on receipts
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_thanks_message" class="form-label fw-semibold">
                                                <i class="bi bi-heart me-1"></i>Thank You Message
                                            </label>
                                            <input type="text" class="form-control" id="receipt_thanks_message" name="receipt_thanks_message" 
                                                   value="<?php echo htmlspecialchars($settings['receipt_thanks_message'] ?? 'Thank you for your business!'); ?>" 
                                                   placeholder="Thank you message">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Appreciation message for customers
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Receipt Formatting Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-layout-text-sidebar me-2"></i>
                                    Receipt Formatting
                                </h5>
                                <small class="text-muted">Receipt IDs are sequential (+1 from previous transaction)</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_width" class="form-label fw-semibold">
                                                <i class="bi bi-arrows-expand me-1"></i>Receipt Width (mm)
                                            </label>
                                            <input type="number" class="form-control" id="receipt_width" name="receipt_width" 
                                                   value="<?php echo htmlspecialchars($settings['receipt_width'] ?? '80'); ?>" 
                                                   min="58" max="210" placeholder="80">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Width in millimeters (58-210mm)
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="receipt_font_size" class="form-label fw-semibold">
                                                <i class="bi bi-fonts me-1"></i>Font Size (px)
                                            </label>
                                            <input type="number" class="form-control" id="receipt_font_size" name="receipt_font_size" 
                                                   value="<?php echo htmlspecialchars($settings['receipt_font_size'] ?? '12'); ?>" 
                                                   min="8" max="20" placeholder="12">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Font size in pixels (8-20px)
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="receipt_show_tax" name="receipt_show_tax" value="1"
                                                       <?php echo (isset($settings['receipt_show_tax']) && $settings['receipt_show_tax'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="receipt_show_tax">
                                                    <i class="bi bi-percent me-1"></i>Show Tax Details
                                                </label>
                                            </div>
                                            <div class="form-text">Display tax breakdown on receipts</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="receipt_show_discount" name="receipt_show_discount" value="1"
                                                       <?php echo (isset($settings['receipt_show_discount']) && $settings['receipt_show_discount'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="receipt_show_discount">
                                                    <i class="bi bi-tag me-1"></i>Show Discount Details
                                                </label>
                                            </div>
                                            <div class="form-text">Display discount information on receipts</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Receipt Numbering Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="bi bi-hash me-2"></i>
                                    Receipt Numbering
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="form-group mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="auto_generate_receipt_number" name="auto_generate_receipt_number" value="1"
                                               <?php echo (isset($settings['auto_generate_receipt_number']) && $settings['auto_generate_receipt_number'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-semibold" for="auto_generate_receipt_number">
                                            <i class="bi bi-gear-fill me-1"></i>Auto-generate Receipt Numbers
                                        </label>
                                    </div>
                                    <div class="form-text">Automatically generate sequential receipt numbers</div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="receipt_number_prefix" class="form-label fw-semibold">Receipt Prefix</label>
                                            <input type="text" class="form-control" id="receipt_number_prefix" name="receipt_number_prefix"
                                                   value="<?php echo htmlspecialchars($settings['receipt_number_prefix'] ?? 'RCP'); ?>"
                                                   placeholder="RCP" maxlength="10">
                                            <div class="form-text">Prefix for receipt numbers</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="receipt_number_length" class="form-label fw-semibold">Number Length</label>
                                            <input type="number" class="form-control" id="receipt_number_length" name="receipt_number_length"
                                                   value="<?php echo htmlspecialchars($settings['receipt_number_length'] ?? '6'); ?>"
                                                   min="3" max="10" placeholder="6">
                                            <div class="form-text">Number of digits (3-10)</div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="receipt_number_separator" class="form-label fw-semibold">Separator</label>
                                            <input type="text" class="form-control" id="receipt_number_separator" name="receipt_number_separator"
                                                   value="<?php echo htmlspecialchars($settings['receipt_number_separator'] ?? '-'); ?>"
                                                   placeholder="-" maxlength="3">
                                            <div class="form-text">Character between parts</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group mb-3">
                                    <label for="receipt_number_format" class="form-label fw-semibold">Receipt Number Format</label>
                                    <select class="form-select" id="receipt_number_format" name="receipt_number_format">
                                        <option value="prefix-date-number" <?php echo ($settings['receipt_number_format'] ?? 'prefix-date-number') == 'prefix-date-number' ? 'selected' : ''; ?>>
                                            Prefix-Date-Number (RCP-20241201-000001)
                                        </option>
                                        <option value="prefix-number" <?php echo ($settings['receipt_number_format'] ?? '') == 'prefix-number' ? 'selected' : ''; ?>>
                                            Prefix-Number (RCP-000001)
                                        </option>
                                        <option value="date-prefix-number" <?php echo ($settings['receipt_number_format'] ?? '') == 'date-prefix-number' ? 'selected' : ''; ?>>
                                            Date-Prefix-Number (20241201-RCP-000001)
                                        </option>
                                        <option value="number-only" <?php echo ($settings['receipt_number_format'] ?? '') == 'number-only' ? 'selected' : ''; ?>>
                                            Number Only (000001)
                                        </option>
                                    </select>
                                    <div class="form-text">Choose how receipt numbers are formatted</div>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-label fw-semibold">Receipt Number Preview</label>
                                    <div class="alert alert-info">
                                        <i class="bi bi-eye me-2"></i>
                                        <strong>Preview:</strong>
                                        <span id="receiptNumberPreview"><?php echo generateReceiptNumberPreview($settings); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Transaction ID Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="bi bi-hash me-2"></i>
                                    Transaction ID Settings
                                </h5>
                                <small class="text-muted">Transaction IDs are random with various character combinations (e.g., TXN1g2h3j, TXNabcDEF)</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="transaction_id_prefix" class="form-label fw-semibold">Transaction ID Prefix</label>
                                            <input type="text" class="form-control" id="transaction_id_prefix" name="transaction_id_prefix"
                                                   value="<?php echo htmlspecialchars($settings['transaction_id_prefix'] ?? 'TXN'); ?>"
                                                   placeholder="TXN">
                                            <div class="form-text">Prefix for transaction IDs</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="transaction_id_length" class="form-label fw-semibold">Number Length</label>
                                            <input type="number" class="form-control" id="transaction_id_length" name="transaction_id_length"
                                                   value="<?php echo htmlspecialchars($settings['transaction_id_length'] ?? '6'); ?>"
                                                   min="3" max="10">
                                            <div class="form-text">Number of digits (3-10)</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group mb-3">
                                    <label for="transaction_id_format" class="form-label fw-semibold">Transaction ID Format</label>
                                    <select class="form-select" id="transaction_id_format" name="transaction_id_format">
                                        <option value="prefix-random" <?php echo ($settings['transaction_id_format'] ?? 'prefix-random') == 'prefix-random' ? 'selected' : ''; ?>>
                                            Prefix-Random (TXN123ABC)
                                        </option>
                                        <option value="random-only" <?php echo ($settings['transaction_id_format'] ?? '') == 'random-only' ? 'selected' : ''; ?>>
                                            Random Only (123ABC)
                                        </option>
                                        <option value="prefix-mixed" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-mixed' ? 'selected' : ''; ?>>
                                            Prefix-Mixed (TXN1g2h3j)
                                        </option>
                                        <option value="mixed-only" <?php echo ($settings['transaction_id_format'] ?? '') == 'mixed-only' ? 'selected' : ''; ?>>
                                            Mixed Only (1g2h3j)
                                        </option>
                                        <option value="prefix-lowercase" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-lowercase' ? 'selected' : ''; ?>>
                                            Prefix-Lowercase (TXN1a2b3c)
                                        </option>
                                        <option value="lowercase-only" <?php echo ($settings['transaction_id_format'] ?? '') == 'lowercase-only' ? 'selected' : ''; ?>>
                                            Lowercase Only (1a2b3c)
                                        </option>
                                        <option value="prefix-numbers" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-numbers' ? 'selected' : ''; ?>>
                                            Prefix-Numbers (TXN123456)
                                        </option>
                                        <option value="numbers-only" <?php echo ($settings['transaction_id_format'] ?? '') == 'numbers-only' ? 'selected' : ''; ?>>
                                            Numbers Only (123456)
                                        </option>
                                        <option value="prefix-letters" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-letters' ? 'selected' : ''; ?>>
                                            Prefix-Letters (TXNabcDEF)
                                        </option>
                                        <option value="letters-only" <?php echo ($settings['transaction_id_format'] ?? '') == 'letters-only' ? 'selected' : ''; ?>>
                                            Letters Only (abcDEF)
                                        </option>
                                        <option value="prefix-date-random" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-date-random' ? 'selected' : ''; ?>>
                                            Prefix-Date-Random (TXN20241201123ABC)
                                        </option>
                                        <option value="prefix-date-mixed" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-date-mixed' ? 'selected' : ''; ?>>
                                            Prefix-Date-Mixed (TXN202412011g2h3j)
                                        </option>
                                        <option value="prefix-date-lowercase" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-date-lowercase' ? 'selected' : ''; ?>>
                                            Prefix-Date-Lowercase (TXN202412011a2b3c)
                                        </option>
                                        <option value="prefix-date-numbers" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-date-numbers' ? 'selected' : ''; ?>>
                                            Prefix-Date-Numbers (TXN20241201123456)
                                        </option>
                                        <option value="prefix-date-letters" <?php echo ($settings['transaction_id_format'] ?? '') == 'prefix-date-letters' ? 'selected' : ''; ?>>
                                            Prefix-Date-Letters (TXN20241201abcDEF)
                                        </option>
                                    </select>
                                    <div class="form-text">Choose how transaction IDs are formatted (random with various character combinations)</div>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="form-label fw-semibold">Transaction ID Preview</label>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-eye me-2"></i>
                                        <strong>Preview:</strong>
                                        <span id="transactionIdPreview"><?php echo generateTransactionIdPreview($settings); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Printing & Reprint Settings -->
                        <div class="card mb-4">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-printer me-2"></i>
                                    Printing & Reprint Settings
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="auto_print_receipt" name="auto_print_receipt" value="1"
                                                       <?php echo (isset($settings['auto_print_receipt']) && $settings['auto_print_receipt'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="auto_print_receipt">
                                                    <i class="bi bi-printer-fill me-1"></i>Auto-print Receipts
                                                </label>
                                            </div>
                                            <div class="form-text">Automatically print receipts after payment</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="auto_close_print_window" name="auto_close_print_window" value="1"
                                                       <?php echo (isset($settings['auto_close_print_window']) && $settings['auto_close_print_window'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="auto_close_print_window">
                                                    <i class="bi bi-x-square-fill me-1"></i>Auto-close Print Window
                                                </label>
                                            </div>
                                            <div class="form-text">Automatically close print window after printing</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="allow_receipt_reprint" name="allow_receipt_reprint" value="1"
                                                       <?php echo (isset($settings['allow_receipt_reprint']) && $settings['allow_receipt_reprint'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="allow_receipt_reprint">
                                                    <i class="bi bi-arrow-repeat me-1"></i>Allow Receipt Reprint
                                                </label>
                                            </div>
                                            <div class="form-text">Allow reprinting of existing receipts</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="max_reprint_attempts" class="form-label fw-semibold">
                                                <i class="bi bi-arrow-repeat me-1"></i>Max Reprint Attempts
                                            </label>
                                            <input type="number" class="form-control" id="max_reprint_attempts" name="max_reprint_attempts" 
                                                   value="<?php echo htmlspecialchars($settings['max_reprint_attempts'] ?? '3'); ?>" 
                                                   min="1" max="10" placeholder="3">
                                            <div class="form-text">
                                                <i class="bi bi-info-circle me-1"></i>Maximum times a receipt can be reprinted
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="require_password_for_reprint" name="require_password_for_reprint" value="1"
                                                       <?php echo (isset($settings['require_password_for_reprint']) && $settings['require_password_for_reprint'] == '1') ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-semibold" for="require_password_for_reprint">
                                                    <i class="bi bi-key me-1"></i>Require Password for Reprint
                                                </label>
                                            </div>
                                            <div class="form-text">Require password verification for reprints</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save me-2"></i>
                                    Save Receipt Settings
                                </button>
                                <button type="reset" class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-arrow-clockwise me-2"></i>
                                    Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Color picker functionality
            const themeColorPicker = document.getElementById('theme_color');
            const themeColorHex = document.getElementById('theme_color_hex');
            const sidebarColorPicker = document.getElementById('sidebar_color');
            const sidebarColorHex = document.getElementById('sidebar_color_hex');
            
            const previewHeader = document.getElementById('previewHeader');
            const previewButton = document.getElementById('previewButton');
            const previewSidebar = document.getElementById('previewSidebar');
            
            // Sync color pickers with hex inputs
            if (themeColorPicker && themeColorHex) {
                themeColorPicker.addEventListener('input', function() {
                    themeColorHex.value = this.value;
                    updateThemePreview();
                });
                
                themeColorHex.addEventListener('input', function() {
                    if (/^#[0-9A-F]{6}$/i.test(this.value)) {
                        themeColorPicker.value = this.value;
                        updateThemePreview();
                    }
                });
            }
            
            if (sidebarColorPicker && sidebarColorHex) {
                sidebarColorPicker.addEventListener('input', function() {
                    sidebarColorHex.value = this.value;
                    updateSidebarPreview();
                });
                
                sidebarColorHex.addEventListener('input', function() {
                    if (/^#[0-9A-F]{6}$/i.test(this.value)) {
                        sidebarColorPicker.value = this.value;
                        updateSidebarPreview();
                    }
                });
            }
            
            function updateThemePreview() {
                const color = themeColorPicker.value;
                if (previewHeader) {
                    previewHeader.style.backgroundColor = color;
                }
                if (previewButton) {
                    previewButton.style.backgroundColor = color;
                    previewButton.style.borderColor = color;
                }
                // Update CSS custom property
                document.documentElement.style.setProperty('--primary-color', color);
            }
            
            function updateSidebarPreview() {
                const color = sidebarColorPicker.value;
                if (previewSidebar) {
                    previewSidebar.style.backgroundColor = color;
                }
                // Update CSS custom property
                document.documentElement.style.setProperty('--sidebar-color', color);
            }
            
            // File upload preview
            const logoInput = document.getElementById('company_logo');
            if (logoInput) {
                logoInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Create or update preview
                            let preview = document.querySelector('.logo-preview');
                            if (!preview) {
                                preview = document.createElement('div');
                                preview.className = 'logo-preview mt-2';
                                logoInput.parentNode.appendChild(preview);
                            }
                            preview.innerHTML = `
                                <img src="${e.target.result}" alt="Logo Preview" 
                                     class="img-thumbnail" style="max-height: 100px;">
                                <div class="form-text">Preview (will be saved when form is submitted)</div>
                            `;
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Form validation and loading states
            document.querySelectorAll('form.settings-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('input[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                        return;
                    }
                    
                    // Show loading state for email form
                    if (form.id === 'emailForm') {
                        const submitBtn = form.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            const originalText = submitBtn.innerHTML;
                            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving Email Settings...';
                            submitBtn.disabled = true;
                            
                            // Re-enable after 3 seconds as fallback
                            setTimeout(() => {
                                submitBtn.innerHTML = originalText;
                                submitBtn.disabled = false;
                            }, 3000);
                        }
                    }
                });
                
                // Listen for successful form submission
                document.getElementById('emailForm').addEventListener('submit', function(e) {
                    // Check if this is an email form submission
                    const smtpHost = document.getElementById('smtp_host').value;
                    if (smtpHost) {
                        // Store the form data for testing after save
                        sessionStorage.setItem('smtpTestAfterSave', 'true');
                        
                        // Show settings saved animation after a short delay
                        setTimeout(() => {
                            showSettingsSaved();
                        }, 500);
                    }
                });
                });
            });
        });

        // Show SMTP connected animation
        function showSMTPConnected() {
            const statusAlert = document.getElementById('smtpStatusAlert');
            const statusText = document.getElementById('smtpStatusText');
            const statusSpinner = document.getElementById('smtpStatusSpinner');
            const statusIcon = document.getElementById('smtpStatusIcon');
            
            if (statusAlert && statusText && statusIcon) {
                // Update status to connected
                statusAlert.className = 'alert alert-success border smtp-connected';
                statusText.textContent = 'Settings Saved - Ready to send emails';
                statusSpinner.style.display = 'none';
                statusIcon.style.display = 'inline-block';
                statusIcon.classList.add('connected');
                
                // Remove blinking animation after 6 seconds
                setTimeout(() => {
                    statusAlert.classList.remove('smtp-connected');
                }, 6000);
            }
        }

        // Show settings saved animation
        function showSettingsSaved() {
            const statusAlert = document.getElementById('smtpStatusAlert');
            const statusText = document.getElementById('smtpStatusText');
            const statusSpinner = document.getElementById('smtpStatusSpinner');
            const statusIcon = document.getElementById('smtpStatusIcon');
            
            if (statusAlert && statusText && statusIcon) {
                // Update status to saved
                statusAlert.className = 'alert alert-success border smtp-connected';
                statusText.textContent = 'Settings Saved - Ready to send emails';
                statusSpinner.style.display = 'none';
                statusIcon.style.display = 'inline-block';
                statusIcon.classList.add('connected');
                
                // Remove blinking animation after 6 seconds
                setTimeout(() => {
                    statusAlert.classList.remove('smtp-connected');
                }, 6000);
            }
        }

        // Test SMTP connection and show connected animation
        function testSMTPConnection() {
            const smtpHost = document.getElementById('smtp_host').value;
            const smtpPort = document.getElementById('smtp_port').value;
            const smtpUsername = document.getElementById('smtp_username').value;
            const smtpPassword = document.getElementById('smtp_password').value;
            const smtpFromEmail = document.getElementById('smtp_from_email').value;

            if (!smtpHost || !smtpPort || !smtpUsername || !smtpPassword || !smtpFromEmail) {
                return;
            }

            // Show loading state
            const statusAlert = document.getElementById('smtpStatusAlert');
            const statusText = document.getElementById('smtpStatusText');
            const statusSpinner = document.getElementById('smtpStatusSpinner');
            const statusIcon = document.getElementById('smtpStatusIcon');
            
            statusAlert.className = 'alert alert-info border';
            statusText.textContent = 'Testing SMTP connection...';
            statusSpinner.style.display = 'inline-block';
            statusIcon.style.display = 'none';

            // Create form data for connection test
            const formData = new FormData();
            formData.append('action', 'test_connection');
            formData.append('smtp_host', smtpHost);
            formData.append('smtp_port', smtpPort);
            formData.append('smtp_username', smtpUsername);
            formData.append('smtp_password', smtpPassword);
            formData.append('smtp_encryption', document.getElementById('smtp_encryption').value);
            formData.append('smtp_from_email', smtpFromEmail);
            formData.append('smtp_from_name', document.getElementById('smtp_from_name').value);

            // Test connection
            fetch('test_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show connected animation
                    showSMTPConnected();
                } else {
                    // Show error state
                    statusAlert.className = 'alert alert-danger border';
                    statusText.textContent = 'Connection failed: ' + (data.message || 'Unknown error');
                    statusSpinner.style.display = 'none';
                    statusIcon.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Connection test error:', error);
                statusAlert.className = 'alert alert-danger border';
                statusText.textContent = 'Connection test failed';
                statusSpinner.style.display = 'none';
                statusIcon.style.display = 'none';
            });
        }

        // Check SMTP status
        function checkSMTPStatus() {
            const smtpHost = document.getElementById('smtp_host').value;
            const smtpPort = document.getElementById('smtp_port').value;
            const smtpUsername = document.getElementById('smtp_username').value;
            const smtpPassword = document.getElementById('smtp_password').value;
            const smtpFromEmail = document.getElementById('smtp_from_email').value;
            
            console.log('SMTP Status Check:', {
                smtpHost: smtpHost,
                smtpPort: smtpPort,
                smtpUsername: smtpUsername,
                smtpPassword: smtpPassword ? '***hidden***' : 'empty',
                smtpFromEmail: smtpFromEmail
            });
            
            const statusAlert = document.getElementById('smtpStatusAlert');
            const statusText = document.getElementById('smtpStatusText');
            const statusSpinner = document.getElementById('smtpStatusSpinner');
            const statusIcon = document.getElementById('smtpStatusIcon');
            
            // Always hide spinner first
            if (statusSpinner) {
                statusSpinner.style.display = 'none';
            }
            
            // Check if all required fields are filled
            // Note: Password field might be empty for security reasons even if saved
            const requiredFields = [smtpHost, smtpPort, smtpUsername, smtpFromEmail];
            const allFieldsFilled = requiredFields.every(field => field && field.trim() !== '');
            
            // If password is empty but other fields are filled, consider it configured
            // Also check if any field has been modified from defaults (indicating user has configured)
            const hasCustomHost = smtpHost && smtpHost !== 'smtp.gmail.com';
            const hasCustomPort = smtpPort && smtpPort !== '587';
            const hasUsername = smtpUsername && smtpUsername.trim() !== '';
            const hasFromEmail = smtpFromEmail && smtpFromEmail.trim() !== '';
            const hasPassword = smtpPassword && smtpPassword.trim() !== '';
            
            const isConfigured = hasCustomHost || (hasUsername && hasFromEmail && (hasPassword || hasCustomPort));
            
            console.log('Field analysis:', {
                hasCustomHost,
                hasCustomPort,
                hasUsername,
                hasFromEmail,
                hasPassword,
                allFieldsFilled,
                isConfigured
            });
            
            if (isConfigured) {
                statusAlert.className = 'alert alert-success border';
                statusText.textContent = 'Settings Saved - Ready to send emails';
                if (statusIcon) {
                    statusIcon.style.display = 'inline-block';
                    statusIcon.classList.add('connected');
                }
                console.log('Status updated to: Settings Saved');
            } else {
                statusAlert.className = 'alert alert-warning border';
                statusText.textContent = 'Please configure your SMTP settings';
                if (statusIcon) {
                    statusIcon.style.display = 'none';
                    statusIcon.classList.remove('connected');
                }
                console.log('Status updated to: Please configure');
            }
        }

        // Email settings functions
        function openTestEmailModal() {
            console.log('openTestEmailModal called');
            
            // Open the test email modal directly - no need to check SMTP settings first
            const modalElement = document.getElementById('testEmailModal');
            if (modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
                console.log('Test email modal opened successfully');
            } else {
                console.error('Modal element not found');
                alert('Test email modal not found. Please refresh the page.');
            }
        }

        // Display test email message
        function showTestEmailMessage(success, message, email) {
            const messageArea = document.getElementById('testEmailMessageArea');
            const alert = document.getElementById('testEmailAlert');
            const icon = document.getElementById('testEmailIcon');
            const title = document.getElementById('testEmailTitle');
            const messageText = document.getElementById('testEmailMessage');
            
            // Show the message area
            messageArea.style.display = 'block';
            
            if (success) {
                alert.className = 'alert alert-success';
                icon.className = 'bi bi-check-circle-fill me-2';
                title.textContent = 'Test Email Sent Successfully!';
                messageText.innerHTML = `Test email has been sent to <strong>${email}</strong>. If you receive this email, your SMTP configuration is working correctly.`;
            } else {
                alert.className = 'alert alert-danger';
                icon.className = 'bi bi-exclamation-triangle-fill me-2';
                title.textContent = 'Test Email Failed';
                messageText.textContent = message;
            }
            
            // Scroll to message
            messageArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Auto-hide success messages after 10 seconds
            if (success) {
                setTimeout(() => {
                    messageArea.style.display = 'none';
                }, 10000);
            }
        }
        
        // Simple test email function
        function sendSimpleTestEmail() {
            console.log('sendSimpleTestEmail called');
            
            const testEmailAddress = document.getElementById('test_email_address_simple').value;
            
            if (!testEmailAddress) {
                showTestEmailMessage(false, 'Please enter a test email address.');
                return;
            }

            // Validate email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(testEmailAddress)) {
                showTestEmailMessage(false, 'Please enter a valid email address.');
                return;
            }

            // Hide any previous messages
            document.getElementById('testEmailMessageArea').style.display = 'none';

            // Show loading state
            const sendBtn = document.getElementById('sendTestEmailBtn');
            const originalText = sendBtn.innerHTML;
            sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
            sendBtn.disabled = true;

            // Create form data and send using our new endpoint
            const formData = new FormData();
            formData.append('test_email_address', testEmailAddress);

            // Send test email request
            console.log('Sending simple test email request...');
            fetch('send_test_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response received:', response);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    showTestEmailMessage(true, data.message, testEmailAddress);
                } else {
                    showTestEmailMessage(false, data.message || 'Unknown error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showTestEmailMessage(false, 'Network error occurred while sending test email');
            })
            .finally(() => {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
            });
        }

        function sendTestEmail() {
            console.log('sendTestEmail called');
            
            const testEmailAddress = document.getElementById('test_email_address').value;
            const testEmailSubject = document.getElementById('test_email_subject').value;
            
            console.log('Test email values:', { testEmailAddress, testEmailSubject });
            
            if (!testEmailAddress) {
                alert('Please enter a test email address.');
                return;
            }

            // Validate email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(testEmailAddress)) {
                alert('Please enter a valid email address.');
                return;
            }

            // Get SMTP settings from form
            const smtpHost = document.getElementById('smtp_host').value;
            const smtpPort = document.getElementById('smtp_port').value;
            const smtpUsername = document.getElementById('smtp_username').value;
            const smtpPassword = document.getElementById('smtp_password').value;
            const smtpEncryption = document.getElementById('smtp_encryption').value;
            const smtpFromEmail = document.getElementById('smtp_from_email').value;
            const smtpFromName = document.getElementById('smtp_from_name').value;

            // Validate SMTP settings before sending
            if (!smtpHost || !smtpPort || !smtpUsername || !smtpPassword || !smtpFromEmail) {
                alert('Please configure your SMTP settings before sending a test email.');
                return;
            }

            // Show loading state
            const sendBtn = document.querySelector('button[onclick="sendTestEmail()"]');
            const originalText = sendBtn.innerHTML;
            sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
            sendBtn.disabled = true;

            // Create form data
            const formData = new FormData();
            formData.append('action', 'test_email');
            formData.append('smtp_host', smtpHost);
            formData.append('smtp_port', smtpPort);
            formData.append('smtp_username', smtpUsername);
            formData.append('smtp_password', smtpPassword);
            formData.append('smtp_encryption', smtpEncryption);
            formData.append('smtp_from_email', smtpFromEmail);
            formData.append('smtp_from_name', smtpFromName);
            formData.append('test_email_address', testEmailAddress);
            formData.append('test_email_subject', testEmailSubject);

            // Send test email request
            console.log('Sending test email request...');
            fetch('test_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Response received:', response);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    alert('Test email sent successfully to ' + testEmailAddress + '!');
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('testEmailModal'));
                    modal.hide();
                } else {
                    alert('Failed to send test email: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while sending test email');
            })
            .finally(() => {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
            });
        }

        function editTemplate(templateType) {
            // Open template editor modal
            const modalHtml = `
                <div class="modal fade" id="templateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit ${templateType.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <form id="templateForm">
                                    <div class="mb-3">
                                        <label class="form-label">Subject Line</label>
                                        <input type="text" class="form-control" id="templateSubject" placeholder="Enter email subject">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Email Body (HTML)</label>
                                        <textarea class="form-control" id="templateBody" rows="15" placeholder="Enter email content with HTML"></textarea>
                                    </div>
                                    <div class="alert alert-info">
                                        <small><strong>Available short codes:</strong> {username}, {email}, {company_name}, {site_url}, {reset_link}, {verification_link}</small>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" onclick="saveTemplate('${templateType}')">Save Template</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Remove existing modal if present
            const existingModal = document.getElementById('templateModal');
            if (existingModal) {
                existingModal.remove();
            }

            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);

            // Load existing template content
            loadTemplateContent(templateType);

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('templateModal'));
            modal.show();
        }

        function loadTemplateContent(templateType) {
            // This would typically load from the database
            // For now, show default content
            let subject = '';
            let body = '';

            switch(templateType) {
                case 'signup_verification':
                    subject = 'Verify Your Email - POS System';
                    body = `Welcome to POS System!

Thank you for registering. Please verify your email address to complete your registration.

Click the link below to verify your email:
{verification_link}

If you didn't create an account, please ignore this email.

Best regards,
{company_name}`;
                    break;
                case 'password_reset':
                    subject = 'Password Reset - POS System';
                    body = `Password Reset Request

Hi {username},

You have requested to reset your password for your POS System account.

Click the link below to reset your password:
{reset_link}

This link will expire in 1 hour.

If you didn't request this password reset, please ignore this email.

Best regards,
{company_name}`;
                    break;
                case 'welcome_email':
                    subject = 'Welcome to POS System';
                    body = `Welcome to POS System, {username}!

Your account has been successfully verified and activated.

You can now log in to your POS System account.

If you have any questions, please contact our support team.

Happy selling!

Best regards,
{company_name}`;
                    break;
                case 'email_verification':
                    subject = 'Email Verification - {company_name}';
                    body = `Email Verification

Hello {first_name} {last_name},

Thank you for registering with {company_name}. To complete your registration, please verify your email address using the verification code below:

Verification Code: {otp_code}

This code will expire in 15 minutes.

If you didn't request this verification, please ignore this email.

Best regards,
{company_name} Team`;
                    break;
            }

            document.getElementById('templateSubject').value = subject;
            document.getElementById('templateBody').value = body;
        }

        function saveTemplate(templateType) {
            const subject = document.getElementById('templateSubject').value;
            const body = document.getElementById('templateBody').value;

            if (!subject.trim() || !body.trim()) {
                alert('Please fill in both subject and body.');
                return;
            }

            // Here you would typically save to database
            alert('Template saved successfully! (Note: Database integration needed for persistence)');

            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('templateModal'));
            modal.hide();
        }

        function clearSecurityLogs() {
            if (!confirm('Are you sure you want to clear security logs older than 30 days? This action cannot be undone.')) {
                return;
            }

            // Show loading state
            const clearBtn = document.querySelector('button[onclick="clearSecurityLogs()"]');
            const originalText = clearBtn.innerHTML;
            clearBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Clearing...';
            clearBtn.disabled = true;

            // Create form data
            const formData = new FormData();
            formData.append('action', 'clear_security_logs');

            // Send request
            fetch('clear_logs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Security logs cleared successfully! ${data.deleted_count} records removed.`);
                    location.reload();
                } else {
                    alert('Failed to clear security logs: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Network error occurred while clearing logs.');
                console.error('Clear logs error:', error);
            })
            .finally(() => {
                // Reset button state
                clearBtn.innerHTML = originalText;
                clearBtn.disabled = false;
            });
        }

        // SKU Preview Functionality
        function updateSKUPreview() {
            const prefix = document.getElementById('sku_prefix').value || 'LIZ';
            const separator = document.getElementById('sku_separator').value || '';
            const length = parseInt(document.getElementById('sku_length').value) || 6;
            const format = document.getElementById('sku_format').value || 'SKU000001';
            
            // Generate a sample SKU based on current settings
            let sampleNumber = '1';
            if (format.includes('000')) {
                // Replace zeros with padded number
                sampleNumber = str_pad(sampleNumber, length, '0', STR_PAD_LEFT);
            } else {
                // Use format as template
                sampleNumber = format.replace(/#/g, str_pad(sampleNumber, length, '0', STR_PAD_LEFT));
            }
            
            const preview = prefix + separator + sampleNumber;
            document.getElementById('skuPreview').textContent = preview;
        }

        // Helper function for string padding (similar to PHP's str_pad)
        function str_pad(str, length, pad_char, pad_type) {
            str = String(str);
            length = parseInt(length);
            pad_char = String(pad_char);
            
            if (pad_type === 'STR_PAD_LEFT') {
                while (str.length < length) {
                    str = pad_char + str;
                }
            } else if (pad_type === 'STR_PAD_RIGHT') {
                while (str.length < length) {
                    str = str + pad_char;
                }
            }
            return str;
        }

        // Add event listeners for SKU preview updates
        document.addEventListener('DOMContentLoaded', function() {
            const skuInputs = ['sku_prefix', 'sku_separator', 'sku_length', 'sku_format'];
            skuInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateSKUPreview);
                    input.addEventListener('change', updateSKUPreview);
                }
            });
            
            // Initial preview update
            updateSKUPreview();

            // Order Number Preview Functionality
            function updateOrderNumberPreview() {
                const prefix = document.getElementById('order_number_prefix').value || 'ORD';
                const separator = document.getElementById('order_number_separator').value || '-';
                const length = parseInt(document.getElementById('order_number_length').value) || 6;
                const format = document.getElementById('order_number_format').value || 'prefix-date-number';

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                }

                document.getElementById('orderNumberPreview').textContent = preview;
            }

            // Add event listeners for order number preview updates
            const orderNumberInputs = ['order_number_prefix', 'order_number_separator', 'order_number_length', 'order_number_format'];
            orderNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateOrderNumberPreview);
                    input.addEventListener('change', updateOrderNumberPreview);
                }
            });

            // Initial order number preview update
            updateOrderNumberPreview();

            // Invoice Number Preview Functionality
            function updateInvoiceNumberPreview() {
                const prefix = document.getElementById('invoice_prefix').value || 'INV';
                const separator = document.getElementById('invoice_separator').value || '-';
                const length = parseInt(document.getElementById('invoice_length').value) || 6;
                const format = document.getElementById('invoice_format').value || 'prefix-date-number';

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                }

                document.getElementById('invoiceNumberPreview').textContent = preview;
            }

            // Add event listeners for invoice number preview updates
            const invoiceNumberInputs = ['invoice_prefix', 'invoice_separator', 'invoice_length', 'invoice_format'];
            invoiceNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateInvoiceNumberPreview);
                    input.addEventListener('change', updateInvoiceNumberPreview);
                }
            });

            // Initial invoice number preview update
            updateInvoiceNumberPreview();

            // BOM Number Preview Functionality
            function updateBOMNumberPreview() {
                const prefix = document.getElementById('bom_number_prefix').value || 'BOM';
                const separator = document.getElementById('bom_number_separator').value || '-';
                const length = parseInt(document.getElementById('bom_number_length').value) || 6;
                const format = document.getElementById('bom_number_format').value || 'prefix-date-number';

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                }

                document.getElementById('bomNumberPreview').textContent = preview;
            }

            // Add event listeners for BOM number preview updates
            const bomNumberInputs = ['bom_number_prefix', 'bom_number_separator', 'bom_number_length', 'bom_number_format'];
            bomNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateBOMNumberPreview);
                    input.addEventListener('change', updateBOMNumberPreview);
                }
            });

            // Initial BOM number preview update
            updateBOMNumberPreview();

            // Customer Number Preview Functionality
            function updateCustomerNumberPreview() {
                const prefix = document.getElementById('customer_number_prefix').value || 'CUST';
                const separator = document.getElementById('customer_number_separator').value || '-';
                const length = parseInt(document.getElementById('customer_number_length').value) || 6;
                const format = document.getElementById('customer_number_format').value || 'prefix-date-number';

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                }

                document.getElementById('customerNumberPreview').textContent = preview;
            }

            // Add event listeners for customer number preview updates
            const customerNumberInputs = ['customer_number_prefix', 'customer_number_separator', 'customer_number_length', 'customer_number_format'];
            customerNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateCustomerNumberPreview);
                    input.addEventListener('change', updateCustomerNumberPreview);
                }
            });

            // Initial customer number preview update
            updateCustomerNumberPreview();

            // Product Number Preview Functionality
            function updateProductNumberPreview() {
                const prefix = document.getElementById('product_number_prefix').value || 'PRD';
                const separator = document.getElementById('product_number_separator').value || '-';
                const length = parseInt(document.getElementById('product_number_length').value) || 6;
                const format = document.getElementById('product_number_format').value || 'prefix-number';

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + sampleNumber;
                }

                document.getElementById('productNumberPreview').textContent = preview;
            }

            // Add event listeners for product number preview updates
            const productNumberInputs = ['product_number_prefix', 'product_number_separator', 'product_number_length', 'product_number_format'];
            productNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateProductNumberPreview);
                    input.addEventListener('change', updateProductNumberPreview);
                }
            });

            // Initial product number preview update
            updateProductNumberPreview();

            // Production Order Preview Functionality
            function updateProductionOrderPreview() {
                const prefix = document.getElementById('bom_production_order_prefix').value || 'PROD';
                const length = parseInt(document.getElementById('bom_production_order_length').value) || 6;

                // Generate sample number
                let sampleNumber = str_pad('1', length, '0', 'STR_PAD_LEFT');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                const preview = prefix + '-' + currentDate + '-' + sampleNumber;
                document.getElementById('productionOrderPreview').textContent = preview;
            }

            // Add event listeners for production order preview updates
            const productionOrderInputs = ['bom_production_order_prefix', 'bom_production_order_length'];
            productionOrderInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateProductionOrderPreview);
                    input.addEventListener('change', updateProductionOrderPreview);
                }
            });

            // Initial production order preview update
            updateProductionOrderPreview();

            // Receipt Number Preview Functionality
            function updateReceiptNumberPreview() {
                const prefix = document.getElementById('receipt_number_prefix').value || 'RCP';
                const separator = document.getElementById('receipt_number_separator').value || '-';
                const length = parseInt(document.getElementById('receipt_number_length').value) || 6;
                const format = document.getElementById('receipt_number_format').value || 'prefix-date-number';

                // Generate sample number (pad with zeros)
                let sampleNumber = '1'.padStart(length, '0');
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-date-number':
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                        break;
                    case 'prefix-number':
                        preview = prefix + separator + sampleNumber;
                        break;
                    case 'date-prefix-number':
                        preview = currentDate + separator + prefix + separator + sampleNumber;
                        break;
                    case 'number-only':
                        preview = sampleNumber;
                        break;
                    default:
                        preview = prefix + separator + currentDate + separator + sampleNumber;
                }

                document.getElementById('receiptNumberPreview').textContent = preview;
            }

            // Add event listeners for receipt number preview updates
            const receiptNumberInputs = ['receipt_number_prefix', 'receipt_number_separator', 'receipt_number_length', 'receipt_number_format'];
            receiptNumberInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateReceiptNumberPreview);
                    input.addEventListener('change', updateReceiptNumberPreview);
                }
            });

            // Initial receipt number preview update
            updateReceiptNumberPreview();

            // Transaction ID Preview Functionality
            function updateTransactionIdPreview() {
                const prefix = document.getElementById('transaction_id_prefix').value || 'TXN';
                const length = parseInt(document.getElementById('transaction_id_length').value) || 6;
                const format = document.getElementById('transaction_id_format').value || 'prefix-random';

                // Define character sets based on format
                let characters = '';
                switch(format) {
                    case 'prefix-random':
                    case 'random-only':
                    case 'prefix-date-random':
                        characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                        break;
                    case 'prefix-mixed':
                    case 'mixed-only':
                    case 'prefix-date-mixed':
                        characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                        break;
                    case 'prefix-lowercase':
                    case 'lowercase-only':
                    case 'prefix-date-lowercase':
                        characters = '0123456789abcdefghijklmnopqrstuvwxyz';
                        break;
                    case 'prefix-numbers':
                    case 'numbers-only':
                    case 'prefix-date-numbers':
                        characters = '0123456789';
                        break;
                    case 'prefix-letters':
                    case 'letters-only':
                    case 'prefix-date-letters':
                        characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                        break;
                    default:
                        characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                }

                // Generate sample random string
                let randomString = '';
                for (let i = 0; i < length; i++) {
                    randomString += characters.charAt(Math.floor(Math.random() * characters.length));
                }
                const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');

                let preview = '';
                switch(format) {
                    case 'prefix-random':
                    case 'prefix-mixed':
                    case 'prefix-lowercase':
                    case 'prefix-numbers':
                    case 'prefix-letters':
                        preview = prefix + randomString;
                        break;
                    case 'random-only':
                    case 'mixed-only':
                    case 'lowercase-only':
                    case 'numbers-only':
                    case 'letters-only':
                        preview = randomString;
                        break;
                    case 'prefix-date-random':
                    case 'prefix-date-mixed':
                    case 'prefix-date-lowercase':
                    case 'prefix-date-numbers':
                    case 'prefix-date-letters':
                        preview = prefix + currentDate + randomString;
                        break;
                    default:
                        preview = prefix + randomString;
                }

                document.getElementById('transactionIdPreview').textContent = preview;
            }

            // Add event listeners for transaction ID preview updates
            const transactionIdInputs = ['transaction_id_prefix', 'transaction_id_length', 'transaction_id_format'];
            transactionIdInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', updateTransactionIdPreview);
                    input.addEventListener('change', updateTransactionIdPreview);
                }
            });

            // Initial transaction ID preview update
            updateTransactionIdPreview();

            // Check SMTP status on page load with a small delay to ensure DOM is loaded
            setTimeout(() => {
                checkSMTPStatus();
            }, 100);
            
            // Check if we just successfully saved SMTP settings
            if (window.location.href.indexOf('tab=email') > -1) {
                // Check for success message in the page
                const successAlert = document.querySelector('.alert-success');
                if (successAlert && successAlert.textContent.includes('Email settings')) {
                    // Trigger settings saved animation after a short delay
                    setTimeout(() => {
                        showSettingsSaved();
                    }, 500);
                }
                
                // Check if we should test connection after save
                if (sessionStorage.getItem('smtpTestAfterSave') === 'true') {
                    sessionStorage.removeItem('smtpTestAfterSave');
                    // Test connection and show connected animation
                    setTimeout(() => {
                        testSMTPConnection();
                    }, 1000);
                }
            }

            // Add event listeners to update SMTP status when fields change
            const smtpFields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email'];
            smtpFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', checkSMTPStatus);
                    field.addEventListener('change', checkSMTPStatus);
                }
            });

            // Password visibility toggle for SMTP password
            const toggleSmtpPassword = document.getElementById('toggleSmtpPassword');
            const smtpPasswordInput = document.getElementById('smtp_password');
            const smtpPasswordIcon = document.getElementById('smtpPasswordIcon');

            if (toggleSmtpPassword && smtpPasswordInput && smtpPasswordIcon) {
                toggleSmtpPassword.addEventListener('click', function() {
                    if (smtpPasswordInput.type === 'password') {
                        smtpPasswordInput.type = 'text';
                        smtpPasswordIcon.classList.remove('bi-eye');
                        smtpPasswordIcon.classList.add('bi-eye-slash');
                    } else {
                        smtpPasswordInput.type = 'password';
                        smtpPasswordIcon.classList.remove('bi-eye-slash');
                        smtpPasswordIcon.classList.add('bi-eye');
                    }
                });
            }
        });
    </script>
</body>
</html>