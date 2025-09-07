<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Get user info
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

// Check if user has permission to manage POS settings
if (!hasPermission('manage_sales', $permissions) && !hasPermission('manage_settings', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle form submissions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_settings':
                try {
                    foreach ($_POST['settings'] as $key => $value) {
                        // Check if setting exists in main settings table
                        $check = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
                        $check->execute([$key]);
                        
                        if ($check->fetchColumn() > 0) {
                            // Update existing setting
                            $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                            $stmt->execute([$value, $key]);
                        } else {
                            // Insert new setting
                            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                            $stmt->execute([$key, $value]);
                        }
                    }
                    $success = 'POS settings updated successfully!';
                } catch (Exception $e) {
                    $error = 'Error updating settings: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Get system settings
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
$system_settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $system_settings[$row['setting_key']] = $row['setting_value'];
}

// Define POS-specific settings with their configurations
$pos_settings = [
    'receipt' => [
        'title' => 'Receipt Settings',
        'icon' => 'bi-receipt',
        'settings' => [
            'receipt_printer_enabled' => [
                'label' => 'Enable receipt printing',
                'type' => 'boolean',
                'value' => $system_settings['receipt_printer_enabled'] ?? '1'
            ],
            'receipt_template' => [
                'label' => 'Receipt template to use',
                'type' => 'text',
                'value' => $system_settings['receipt_template'] ?? 'default'
            ],
            'auto_print_receipt' => [
                'label' => 'Automatically print receipts',
                'type' => 'boolean',
                'value' => $system_settings['auto_print_receipt'] ?? '0'
            ],
            'receipt_footer_text' => [
                'label' => 'Receipt footer text',
                'type' => 'text',
                'value' => $system_settings['receipt_footer_text'] ?? 'Thank you for your business!'
            ]
        ]
    ],
    'sales' => [
        'title' => 'Sales Settings',
        'icon' => 'bi-cart-check',
        'settings' => [
            'require_customer_info' => [
                'label' => 'Require customer information for sales',
                'type' => 'boolean',
                'value' => $system_settings['require_customer_info'] ?? '0'
            ],
            'max_discount_percentage' => [
                'label' => 'Maximum discount percentage allowed',
                'type' => 'number',
                'value' => $system_settings['max_discount_percentage'] ?? '50'
            ],
            'enable_sales_tax' => [
                'label' => 'Enable sales tax calculation',
                'type' => 'boolean',
                'value' => $system_settings['enable_sales_tax'] ?? '1'
            ],
            'default_tax_rate' => [
                'label' => 'Default tax rate (%)',
                'type' => 'number',
                'value' => $system_settings['default_tax_rate'] ?? '16'
            ]
        ]
    ],
    'payment' => [
        'title' => 'Payment Settings',
        'icon' => 'bi-credit-card',
        'settings' => [
            'default_payment_method' => [
                'label' => 'Default payment method',
                'type' => 'text',
                'value' => $system_settings['default_payment_method'] ?? 'cash'
            ],
            'enable_split_payments' => [
                'label' => 'Enable split payments',
                'type' => 'boolean',
                'value' => $system_settings['enable_split_payments'] ?? '1'
            ],
            'require_payment_confirmation' => [
                'label' => 'Require payment confirmation',
                'type' => 'boolean',
                'value' => $system_settings['require_payment_confirmation'] ?? '0'
            ]
        ]
    ],
    'inventory' => [
        'title' => 'Inventory Settings',
        'icon' => 'bi-boxes',
        'settings' => [
            'low_stock_threshold' => [
                'label' => 'Low stock alert threshold',
                'type' => 'number',
                'value' => $system_settings['low_stock_threshold'] ?? '10'
            ],
            'track_inventory' => [
                'label' => 'Track inventory automatically',
                'type' => 'boolean',
                'value' => $system_settings['track_inventory'] ?? '1'
            ],
            'allow_negative_stock' => [
                'label' => 'Allow negative stock levels',
                'type' => 'boolean',
                'value' => $system_settings['allow_negative_stock'] ?? '0'
            ],
            'auto_reorder' => [
                'label' => 'Enable automatic reorder alerts',
                'type' => 'boolean',
                'value' => $system_settings['auto_reorder'] ?? '1'
            ]
        ]
    ],
    'calculation' => [
        'title' => 'Calculation Settings',
        'icon' => 'bi-calculator',
        'settings' => [
            'round_to_nearest' => [
                'label' => 'Round amounts to nearest value',
                'type' => 'number',
                'value' => $system_settings['round_to_nearest'] ?? '0.01'
            ],
            'tax_inclusive' => [
                'label' => 'Prices include tax by default',
                'type' => 'boolean',
                'value' => $system_settings['tax_inclusive'] ?? '1'
            ],
            'currency_decimal_places' => [
                'label' => 'Currency decimal places',
                'type' => 'number',
                'value' => $system_settings['currency_decimal_places'] ?? '2'
            ]
        ]
    ],
    'customer' => [
        'title' => 'Customer Settings',
        'icon' => 'bi-people',
        'settings' => [
            'enable_loyalty_program' => [
                'label' => 'Enable customer loyalty program',
                'type' => 'boolean',
                'value' => $system_settings['enable_loyalty_program'] ?? '0'
            ],
            'require_customer_phone' => [
                'label' => 'Require customer phone number',
                'type' => 'boolean',
                'value' => $system_settings['require_customer_phone'] ?? '0'
            ],
            'enable_customer_notes' => [
                'label' => 'Enable customer notes',
                'type' => 'boolean',
                'value' => $system_settings['enable_customer_notes'] ?? '1'
            ]
        ]
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Configuration - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-sliders"></i> POS Configuration</h2>
                    <p class="text-muted">Configure your point of sale system settings</p>
                </div>
                <div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <?php foreach ($pos_settings as $category => $category_data): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi <?php echo $category_data['icon']; ?>"></i> 
                            <?php echo $category_data['title']; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($category_data['settings'] as $key => $setting): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="setting_<?php echo $key; ?>">
                                    <?php echo htmlspecialchars($setting['label']); ?>
                                </label>
                                
                                <?php if ($setting['type'] == 'boolean'): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="settings[<?php echo $key; ?>]" 
                                           id="setting_<?php echo $key; ?>"
                                           value="1" 
                                           <?php echo $setting['value'] == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="setting_<?php echo $key; ?>">
                                        Enable
                                    </label>
                                </div>
                                <?php elseif ($setting['type'] == 'number'): ?>
                                <input type="number" class="form-control" 
                                       name="settings[<?php echo $key; ?>]" 
                                       id="setting_<?php echo $key; ?>"
                                       value="<?php echo htmlspecialchars($setting['value']); ?>"
                                       step="0.01">
                                <?php else: ?>
                                <input type="text" class="form-control" 
                                       name="settings[<?php echo $key; ?>]" 
                                       id="setting_<?php echo $key; ?>"
                                       value="<?php echo htmlspecialchars($setting['value']); ?>">
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">Save Configuration</h6>
                                <p class="text-muted mb-0">Save all changes to your POS settings</p>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Save Settings
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
