<?php
session_start();
require_once __DIR__ . '/../../include/db.php';

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

// Helper function to check permissions
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
}

// Check if user has permission to manage settings
if (!hasPermission('manage_settings', $permissions)) {
    header("Location: ../../dashboard/dashboard.php");
    exit();
}

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
    'receipt_header' => 'POS SYSTEM',
    'receipt_contact' => 'Contact: [Configure in Settings]',
    'receipt_show_tax' => '1',
    'receipt_show_discount' => '1',
    'receipt_footer' => 'Thank you for your purchase!',
    'receipt_thanks_message' => 'Please come again.',
    'receipt_width' => '80',
    'receipt_font_size' => '12',
    'auto_print_receipt' => '0',
    'theme_color' => '#6366f1',
    'sidebar_color' => '#1e293b',
    'timezone' => 'Africa/Nairobi',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
    'low_stock_threshold' => '10',
    'backup_frequency' => 'daily',
    'enable_sound' => '1',
    'default_payment_method' => 'cash',
    'allow_negative_stock' => '0',
    'barcode_type' => 'CODE128'
];

// Merge with defaults
foreach($defaults as $key => $value) {
    if(!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'company';

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
    
    if (isset($_POST['receipt_width'])) {
        $width = intval($_POST['receipt_width']);
        if ($width < 50 || $width > 120) {
            $errors[] = "Receipt width must be between 50 and 120 characters.";
        }
    }
    
    if (isset($_POST['receipt_font_size'])) {
        $font_size = intval($_POST['receipt_font_size']);
        if ($font_size < 8 || $font_size > 24) {
            $errors[] = "Receipt font size must be between 8 and 24.";
        }
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Handle checkbox values
            $checkbox_fields = ['receipt_show_tax', 'receipt_show_discount', 'auto_print_receipt', 'enable_sound', 'allow_negative_stock'];
            foreach($checkbox_fields as $field) {
                if (!isset($_POST[$field])) {
                    $_POST[$field] = '0';
                }
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
                    $value = trim($_POST[$key]);
                    
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
            $_SESSION['success'] = "Settings updated successfully!";
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-shop me-2"></i><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h4>
            <small>Point of Sale System</small>
        </div>
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="../../dashboard/dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </div>
            
            <?php if (hasPermission('process_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../../pos/index.php" class="nav-link">
                    <i class="bi bi-cart-plus"></i>
                    Point of Sale
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_products', $permissions)): ?>
            <div class="nav-item">
                <a href="../../products/products.php" class="nav-link">
                    <i class="bi bi-box"></i>
                    Products
                </a>
            </div>
            <div class="nav-item">
                <a href="../../categories/categories.php" class="nav-link">
                    <i class="bi bi-tags"></i>
                    Categories
                </a>
            </div>
            <div class="nav-item">
                <a href="../../inventory/index.php" class="nav-link">
                    <i class="bi bi-boxes"></i>
                    Inventory
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_sales', $permissions)): ?>
            <div class="nav-item">
                <a href="../../sales/index.php" class="nav-link">
                    <i class="bi bi-receipt"></i>
                    Sales History
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../../customers/index.php" class="nav-link">
                    <i class="bi bi-people"></i>
                    Customers
                </a>
            </div>

            <div class="nav-item">
                <a href="../../reports/index.php" class="nav-link">
                    <i class="bi bi-graph-up"></i>
                    Reports
                </a>
            </div>

            <?php if (hasPermission('manage_users', $permissions)): ?>
            <div class="nav-item">
                <a href="../users/index.php" class="nav-link">
                    <i class="bi bi-person-gear"></i>
                    User Management
                </a>
            </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_settings', $permissions)): ?>
            <div class="nav-item">
                <a href="adminsetting.php" class="nav-link active">
                    <i class="bi bi-gear"></i>
                    Settings
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-item">
                <a href="../../auth/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

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
                    <a href="?tab=company" class="nav-link <?php echo $active_tab == 'company' ? 'active' : ''; ?>">
                        <i class="bi bi-building me-2"></i>
                        Company Details
                    </a>
                    <a href="?tab=currency" class="nav-link <?php echo $active_tab == 'currency' ? 'active' : ''; ?>">
                        <i class="bi bi-currency-exchange me-2"></i>
                        Currency & Tax
                    </a>
                    <a href="?tab=receipt" class="nav-link <?php echo $active_tab == 'receipt' ? 'active' : ''; ?>">
                        <i class="bi bi-receipt me-2"></i>
                        Receipt Settings
                    </a>
                    <a href="?tab=system" class="nav-link <?php echo $active_tab == 'system' ? 'active' : ''; ?>">
                        <i class="bi bi-gear me-2"></i>
                        System Settings
                    </a>
                    <a href="?tab=inventory" class="nav-link <?php echo $active_tab == 'inventory' ? 'active' : ''; ?>">
                        <i class="bi bi-boxes me-2"></i>
                        Inventory Settings
                    </a>
                    <a href="?tab=appearance" class="nav-link <?php echo $active_tab == 'appearance' ? 'active' : ''; ?>">
                        <i class="bi bi-palette me-2"></i>
                        Appearance
                    </a>
                </div>
            </div>

            <!-- Settings Content -->
            <div class="settings-content">
                <!-- Company Details Tab -->
                <?php if ($active_tab == 'company'): ?>
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
                            <div class="form-text">This name will appear on receipts and reports.</div>
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
                <?php if ($active_tab == 'currency'): ?>
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

                <!-- Receipt Settings Tab -->
                <?php if ($active_tab == 'receipt'): ?>
                <div class="data-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <i class="bi bi-receipt me-2"></i>
                            Receipt Settings
                        </h3>
                    </div>
                    
                    <form method="POST" action="" class="settings-form" id="receiptForm">
                        <div class="form-group">
                            <label for="receipt_header" class="form-label">Receipt Header</label>
                            <input type="text" class="form-control" id="receipt_header" name="receipt_header" 
                                   value="<?php echo htmlspecialchars($settings['receipt_header']); ?>" 
                                   maxlength="100" placeholder="Your Business Name">
                            <div class="form-text">This text will appear at the top of all receipts.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="receipt_contact" class="form-label">Contact Information</label>
                            <input type="text" class="form-control" id="receipt_contact" name="receipt_contact" 
                                   value="<?php echo htmlspecialchars($settings['receipt_contact']); ?>" 
                                   maxlength="150" placeholder="Contact: phone@email.com">
                            <div class="form-text">Contact information displayed below the header on receipts.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="receipt_footer" class="form-label">Footer Message</label>
                            <textarea class="form-control" id="receipt_footer" name="receipt_footer" 
                                      rows="3" maxlength="300" placeholder="Thank you for your purchase!"><?php echo htmlspecialchars($settings['receipt_footer']); ?></textarea>
                            <div class="form-text">Message that appears at the bottom of receipts.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="receipt_thanks_message" class="form-label">Thank You Message</label>
                            <input type="text" class="form-control" id="receipt_thanks_message" name="receipt_thanks_message" 
                                   value="<?php echo htmlspecialchars($settings['receipt_thanks_message']); ?>" 
                                   maxlength="100" placeholder="Please come again!">
                            <div class="form-text">Message displayed at the very end of the receipt.</div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="receipt_width" class="form-label">Receipt Width (Characters)</label>
                                    <input type="number" min="50" max="120" class="form-control" id="receipt_width" name="receipt_width" 
                                           value="<?php echo htmlspecialchars($settings['receipt_width']); ?>" 
                                           placeholder="80">
                                    <div class="form-text">Width of the receipt in characters (50-120).</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="receipt_font_size" class="form-label">Receipt Font Size</label>
                                    <input type="number" min="8" max="24" class="form-control" id="receipt_font_size" name="receipt_font_size" 
                                           value="<?php echo htmlspecialchars($settings['receipt_font_size']); ?>" 
                                           placeholder="12">
                                    <div class="form-text">Font size for receipt text (8-24).</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Display Options</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="receipt_show_tax" name="receipt_show_tax" value="1" 
                                       <?php echo (isset($settings['receipt_show_tax']) && $settings['receipt_show_tax'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="receipt_show_tax">
                                    Show Tax Information on Receipts
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="receipt_show_discount" name="receipt_show_discount" value="1" 
                                       <?php echo (isset($settings['receipt_show_discount']) && $settings['receipt_show_discount'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="receipt_show_discount">
                                    Show Discount Information on Receipts
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_print_receipt" name="auto_print_receipt" value="1" 
                                       <?php echo (isset($settings['auto_print_receipt']) && $settings['auto_print_receipt'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_print_receipt">
                                    Automatically Print Receipt After Sale
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Save Receipt Settings
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
                <?php if ($active_tab == 'system'): ?>
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
                <?php if ($active_tab == 'inventory'): ?>
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
                        
                        <div class="form-group">
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
                <?php if ($active_tab == 'appearance'): ?>
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
            
            // Form validation
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
                    }
                });
            });
        });
    </script>
</body>
</html>