<?php
// Fresh Installation Script for POS System
session_start();

// Function to validate email
function validateEmail($email) {
    if (empty($email)) {
        return "Email is required";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email format";
    }
    if (strlen($email) > 100) {
        return "Email must be less than 100 characters";
    }
    return true;
}

// Function to validate password
function validatePassword($password, $confirmPassword) {
    if (empty($password)) {
        return "Password is required";
    }
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return "Password must contain at least one special character";
    }
    if ($password !== $confirmPassword) {
        return "Passwords do not match";
    }
    return true;
}

// Function to validate company details
function validateCompanyDetails($companyData) {
    $errors = [];
    
    if (empty(trim($companyData['company_name']))) {
        $errors[] = "Company name is required";
    } elseif (strlen(trim($companyData['company_name'])) > 100) {
        $errors[] = "Company name must be less than 100 characters";
    }
    
    if (!empty($companyData['company_email']) && !filter_var($companyData['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid company email format";
    }
    
    if (!empty($companyData['company_phone']) && !preg_match('/^[+]?[0-9\s\-\(\)]{10,15}$/', $companyData['company_phone'])) {
        $errors[] = "Invalid phone number format";
    }
    
    if (!empty($companyData['tax_rate']) && (!is_numeric($companyData['tax_rate']) || $companyData['tax_rate'] < 0 || $companyData['tax_rate'] > 100)) {
        $errors[] = "Tax rate must be a number between 0 and 100";
    }
    
    return empty($errors) ? true : $errors;
}

// Check if form is submitted
$currentStep = $_SESSION['install_step'] ?? 'admin';
$formErrors = [];
$adminEmail = '';
$adminPassword = '';
$companyData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_submit'])) {
        // Handle admin account creation
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate email
        $emailValidation = validateEmail($adminEmail);
        if ($emailValidation !== true) {
            $formErrors[] = $emailValidation;
        }
        
        // Validate password
        $passwordValidation = validatePassword($adminPassword, $confirmPassword);
        if ($passwordValidation !== true) {
            $formErrors[] = $passwordValidation;
        }
        
        // If no errors, proceed to company details
        if (empty($formErrors)) {
            $_SESSION['admin_email'] = $adminEmail;
            $_SESSION['admin_password'] = $adminPassword;
            $_SESSION['install_step'] = 'company';
            $currentStep = 'company';
        }
    } elseif (isset($_POST['company_submit'])) {
        // Handle company details
        $companyData = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'currency_symbol' => trim($_POST['currency_symbol'] ?? 'USD'),
            'tax_rate' => trim($_POST['tax_rate'] ?? '0'),
        ];
        
        // Validate company details
        $companyValidation = validateCompanyDetails($companyData);
        if ($companyValidation !== true) {
            $formErrors = $companyValidation;
        } else {
            $_SESSION['company_data'] = $companyData;
            $_SESSION['install_step'] = 'install';
            $currentStep = 'install';
        }
    } elseif (isset($_POST['back_to_admin'])) {
        // Go back to admin setup
        $_SESSION['install_step'] = 'admin';
        $currentStep = 'admin';
        $adminEmail = $_SESSION['admin_email'] ?? '';
    }
}

// Restore data from session
if ($currentStep === 'admin' && isset($_SESSION['admin_email'])) {
    $adminEmail = $_SESSION['admin_email'];
}
if ($currentStep === 'company' && isset($_SESSION['company_data'])) {
    $companyData = $_SESSION['company_data'];
}

echo "<!DOCTYPE html>\n";
echo "<html lang='en'><head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>Fresh Installation - POS System</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css'>";
echo "<style>";
echo "body{background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);min-height:100vh}";
echo ".install-container{background:rgba(255,255,255,0.95);backdrop-filter:blur(10px);border-radius:20px;box-shadow:0 20px 40px rgba(0,0,0,0.1)}";
echo ".step{border:1px solid #ddd;padding:15px;margin:10px 0;border-radius:8px;background:#f8f9fa}";
echo ".success{color:#28a745;background:#d4edda;border-color:#c3e6cb}";
echo ".error{color:#dc3545;background:#f8d7da;border-color:#f5c6cb}";
echo ".info{color:#17a2b8;background:#d1ecf1;border-color:#bee5eb}";
echo ".warning{color:#856404;background:#fff3cd;border-color:#ffeaa7}";
echo "h1{color:#333;text-align:center;margin-bottom:30px}";
echo ".progress-bar{transition:width 0.3s ease}";
echo ".form-control:focus{border-color:#667eea;box-shadow:0 0 0 0.2rem rgba(102, 126, 234, 0.25)}";
echo ".btn-primary{background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);border:none}";
echo ".btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(102, 126, 234, 0.3)}";
echo "</style></head><body>";

echo "<div class='container mt-5'>";
echo "<div class='row justify-content-center'>";
echo "<div class='col-md-10'>";
echo "<div class='install-container p-5'>";

if ($currentStep === 'admin') {
    // Show admin account setup form
    echo "<h1><i class='bi bi-person-plus-fill me-3'></i>Setup Admin Account</h1>";
    echo "<p class='lead text-center mb-4'>Create your administrator account to manage the POS system.</p>";
    
    // Progress indicator
    echo "<div class='row mb-4'>";
    echo "<div class='col-12'>";
    echo "<div class='progress' style='height: 10px;'>";
    echo "<div class='progress-bar' role='progressbar' style='width: 33%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-primary fw-bold'>1. Admin Account</small>";
    echo "<small class='text-muted'>2. Company Details</small>";
    echo "<small class='text-muted'>3. Installation</small>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Display form errors
    if (!empty($formErrors)) {
        echo "<div class='alert alert-danger'>";
        echo "<h5><i class='bi bi-exclamation-triangle-fill me-2'></i>Please fix the following errors:</h5>";
        echo "<ul class='mb-0'>";
        foreach ($formErrors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    echo "<form method='POST' action='' id='adminForm'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='admin_email' class='form-label'><i class='bi bi-envelope me-2'></i>Administrator Email <span class='text-danger'>*</span></label>";
    echo "<input type='email' class='form-control form-control-lg' id='admin_email' name='admin_email' value='" . htmlspecialchars($adminEmail) . "' required>";
    echo "<div class='form-text'>This will be your login email address</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='admin_password' class='form-label'><i class='bi bi-lock me-2'></i>Password <span class='text-danger'>*</span></label>";
    echo "<div class='input-group'>";
    echo "<input type='password' class='form-control form-control-lg' id='admin_password' name='admin_password' required>";
    echo "<button class='btn btn-outline-secondary' type='button' id='togglePassword1'>";
    echo "<i class='bi bi-eye' id='eyeIcon1'></i>";
    echo "</button>";
    echo "</div>";
    echo "<div class='form-text'>Minimum 8 characters with uppercase, lowercase, number, and special character</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='confirm_password' class='form-label'><i class='bi bi-lock-fill me-2'></i>Confirm Password <span class='text-danger'>*</span></label>";
    echo "<div class='input-group'>";
    echo "<input type='password' class='form-control form-control-lg' id='confirm_password' name='confirm_password' required>";
    echo "<button class='btn btn-outline-secondary' type='button' id='togglePassword2'>";
    echo "<i class='bi bi-eye' id='eyeIcon2'></i>";
    echo "</button>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<div class='card bg-light'>";
    echo "<div class='card-body'>";
    echo "<h6 class='card-title'><i class='bi bi-shield-check me-2'></i>Password Requirements:</h6>";
    echo "<ul class='mb-0 small'>";
    echo "<li>At least 8 characters long</li>";
    echo "<li>One uppercase letter (A-Z)</li>";
    echo "<li>One lowercase letter (a-z)</li>";
    echo "<li>One number (0-9)</li>";
    echo "<li>One special character (!@#$%^&*)</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='text-center'>";
    echo "<button type='submit' name='admin_submit' class='btn btn-primary btn-lg px-5'>";
    echo "<i class='bi bi-arrow-right me-2'></i>Next: Company Details";
    echo "</button>";
    echo "</div>";
    echo "</form>";
    
    // Add JavaScript for password visibility toggle
    echo "<script>";
    echo "document.getElementById('togglePassword1').addEventListener('click', function() {";
    echo "    const password = document.getElementById('admin_password');";
    echo "    const eyeIcon = document.getElementById('eyeIcon1');";
    echo "    if (password.type === 'password') {";
    echo "        password.type = 'text';";
    echo "        eyeIcon.className = 'bi bi-eye-slash';";
    echo "    } else {";
    echo "        password.type = 'password';";
    echo "        eyeIcon.className = 'bi bi-eye';";
    echo "    }";
    echo "});";
    echo "document.getElementById('togglePassword2').addEventListener('click', function() {";
    echo "    const password = document.getElementById('confirm_password');";
    echo "    const eyeIcon = document.getElementById('eyeIcon2');";
    echo "    if (password.type === 'password') {";
    echo "        password.type = 'text';";
    echo "        eyeIcon.className = 'bi bi-eye-slash';";
    echo "    } else {";
    echo "        password.type = 'password';";
    echo "        eyeIcon.className = 'bi bi-eye';";
    echo "    }";
    echo "});";
    echo "</script>";
    
} elseif ($currentStep === 'company') {
    // Show company details setup form
    echo "<h1><i class='bi bi-building me-3'></i>Company Information</h1>";
    echo "<p class='lead text-center mb-4'>Configure your company details for receipts and system branding.</p>";
    
    // Progress indicator
    echo "<div class='row mb-4'>";
    echo "<div class='col-12'>";
    echo "<div class='progress' style='height: 10px;'>";
    echo "<div class='progress-bar' role='progressbar' style='width: 66%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>1. Admin Account</small>";
    echo "<small class='text-primary fw-bold'>2. Company Details</small>";
    echo "<small class='text-muted'>3. Installation</small>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Display form errors
    if (!empty($formErrors)) {
        echo "<div class='alert alert-danger'>";
        echo "<h5><i class='bi bi-exclamation-triangle-fill me-2'></i>Please fix the following errors:</h5>";
        echo "<ul class='mb-0'>";
        foreach ($formErrors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    echo "<form method='POST' action='' id='companyForm'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='company_name' class='form-label'><i class='bi bi-building me-2'></i>Company Name <span class='text-danger'>*</span></label>";
    echo "<input type='text' class='form-control form-control-lg' id='company_name' name='company_name' value='" . htmlspecialchars($companyData['company_name'] ?? '') . "' required>";
    echo "<div class='form-text'>This will appear on receipts</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='company_email' class='form-label'><i class='bi bi-envelope me-2'></i>Company Email</label>";
    echo "<input type='email' class='form-control form-control-lg' id='company_email' name='company_email' value='" . htmlspecialchars($companyData['company_email'] ?? '') . "'>";
    echo "<div class='form-text'>Contact email for customers</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='company_phone' class='form-label'><i class='bi bi-telephone me-2'></i>Company Phone</label>";
    echo "<input type='tel' class='form-control form-control-lg' id='company_phone' name='company_phone' value='" . htmlspecialchars($companyData['company_phone'] ?? '') . "'>";
    echo "<div class='form-text'>Contact phone number</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='currency_symbol' class='form-label'><i class='bi bi-currency-dollar me-2'></i>Currency Symbol</label>";
    echo "<select class='form-select form-select-lg' id='currency_symbol' name='currency_symbol'>";
    $currencies = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'KES' => 'KES', 'NGN' => '₦', 'ZAR' => 'R', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹'];
    foreach ($currencies as $code => $symbol) {
        $selected = (($companyData['currency_symbol'] ?? 'USD') === $code) ? 'selected' : '';
        echo "<option value='$code' $selected>$symbol - $code</option>";
    }
    echo "</select>";
    echo "<div class='form-text'>Currency for pricing and receipts</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-4'>";
    echo "<label for='tax_rate' class='form-label'><i class='bi bi-percent me-2'></i>Tax Rate (%)</label>";
    echo "<input type='number' class='form-control form-control-lg' id='tax_rate' name='tax_rate' value='" . htmlspecialchars($companyData['tax_rate'] ?? '0') . "' min='0' max='100' step='0.01'>";
    echo "<div class='form-text'>Default tax rate for sales (0-100%)</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='card bg-light h-100'>";
    echo "<div class='card-body'>";
    echo "<h6 class='card-title'><i class='bi bi-info-circle me-2'></i>Configuration Notes:</h6>";
    echo "<ul class='mb-0 small'>";
    echo "<li>Company name is required</li>";
    echo "<li>Other fields are optional</li>";
    echo "<li>You can change these later in Settings</li>";
    echo "<li>Tax rate can be adjusted per sale</li>";
    echo "</ul>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='mb-4'>";
    echo "<label for='company_address' class='form-label'><i class='bi bi-geo-alt me-2'></i>Company Address</label>";
    echo "<textarea class='form-control' id='company_address' name='company_address' rows='3' placeholder='Enter full business address...'" . ">" . htmlspecialchars($companyData['company_address'] ?? '') . "</textarea>";
    echo "<div class='form-text'>Full address for receipts and legal documentation</div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between'>";
    echo "<button type='submit' name='back_to_admin' class='btn btn-outline-secondary btn-lg'>";
    echo "<i class='bi bi-arrow-left me-2'></i>Back";
    echo "</button>";
    echo "<button type='submit' name='company_submit' class='btn btn-primary btn-lg px-5'>";
    echo "<i class='bi bi-gear-fill me-2'></i>Start Installation";
    echo "</button>";
    echo "</div>";
    echo "</form>";
    
} else {
    // Show installation process
    $adminEmail = $_SESSION['admin_email'] ?? '';
    $adminPassword = $_SESSION['admin_password'] ?? '';
    $companyData = $_SESSION['company_data'] ?? [];
    
    echo "<h1><i class='bi bi-gear-fill me-3'></i>Installing POS System</h1>";
    echo "<p class='lead text-center mb-4'>Setting up your POS system with custom configuration...</p>";
    
    // Progress indicator
    echo "<div class='row mb-4'>";
    echo "<div class='col-12'>";
    echo "<div class='progress' style='height: 10px;'>";
    echo "<div class='progress-bar' role='progressbar' style='width: 100%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>1. Admin Account</small>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>2. Company Details</small>";
    echo "<small class='text-primary fw-bold'>3. Installation</small>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

$steps = [];
$errors = [];

// Step 1: Clear existing installation markers
echo "<div class='step info'>";
echo "<h4><i class='bi bi-1-circle me-2'></i>Step 1: Clearing Previous Installation</h4>";

$storage_dir = __DIR__ . '/storage';
$marker_file = $storage_dir . '/installed';

if (file_exists($marker_file)) {
    unlink($marker_file);
    echo "<p>✓ Removed existing installation marker</p>";
} else {
    echo "<p>ℹ No previous installation marker found</p>";
}

// Clear any sessions that might interfere
session_unset();
session_destroy();
session_start();

echo "<p>✓ Cleared session data</p>";
echo "</div>";

// Step 2: Database Connection Test
echo "<div class='step info'>";
echo "<h4><i class='bi bi-2-circle me-2'></i>Step 2: Database Connection Test</h4>";

try {
    require_once 'include/db.php';
    
    if (isset($GLOBALS['db_connected']) && $GLOBALS['db_connected'] === true) {
        echo "<p class='text-success'>✓ Database connection successful</p>";
        echo "<p>Database: pos_system</p>";
        
        // Test basic query
        $test_query = $conn->query("SELECT 1 as test");
        if ($test_query) {
            echo "<p class='text-success'>✓ Database queries working</p>";
        } else {
            throw new Exception("Cannot execute test query");
        }
        
    } else {
        throw new Exception("Database connection failed: " . ($GLOBALS['db_error'] ?? 'Unknown error'));
    }
    
} catch (Exception $e) {
    echo "<p class='text-danger'>✗ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    $errors[] = "Database connection failed";
}
echo "</div>";

// Step 3: Reset Database Tables (if connection successful)
if (empty($errors)) {
    echo "<div class='step warning'>";
    echo "<h4><i class='bi bi-3-circle me-2'></i>Step 3: Database Reset & Recreation</h4>";
    
    try {
        // Drop and recreate tables for fresh install
        $tables_to_reset = [
            'role_permissions',
            'permissions', 
            'roles',
            'sale_items',
            'sales',
            'products',
            'categories',
            'users',
            'settings'
        ];
        
        // Disable foreign key checks temporarily
        $conn->exec("SET foreign_key_checks = 0");
        
        foreach ($tables_to_reset as $table) {
            try {
                $conn->exec("DROP TABLE IF EXISTS `$table`");
                echo "<p>✓ Dropped table: $table</p>";
            } catch (Exception $e) {
                echo "<p class='text-warning'>⚠ Could not drop $table: " . $e->getMessage() . "</p>";
            }
        }
        
        // Re-enable foreign key checks
        $conn->exec("SET foreign_key_checks = 1");
        
        echo "<p class='text-success'><strong>✓ Database reset completed</strong></p>";
        
    } catch (Exception $e) {
        echo "<p class='text-danger'>✗ Database reset failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        $errors[] = "Database reset failed";
    }
    echo "</div>";
}

// Step 4: Fresh Database Creation
if (empty($errors)) {
    echo "<div class='step success'>";
    echo "<h4><i class='bi bi-4-circle me-2'></i>Step 4: Creating Fresh Database Schema</h4>";
    
    try {
        // The db.php file will automatically create all tables when included
        // Since we've reset everything, including it again will recreate all tables
        unset($conn); // Clear existing connection
        require_once 'include/db.php'; // This will recreate all tables
        
        if (isset($GLOBALS['db_connected']) && $GLOBALS['db_connected'] === true) {
            echo "<p class='text-success'>✓ All database tables created successfully</p>";
            
            // Verify key tables exist
            $key_tables = ['users', 'roles', 'permissions', 'categories', 'products', 'sales', 'sale_items', 'settings'];
            foreach ($key_tables as $table) {
                $check = $conn->query("SHOW TABLES LIKE '$table'");
                if ($check && $check->rowCount() > 0) {
                    echo "<p>✓ Table created: $table</p>";
                } else {
                    throw new Exception("Failed to create table: $table");
                }
            }
            
            // Create the admin user with custom credentials
            $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
            
            $create_admin = $conn->prepare("INSERT INTO users (username, email, password, role, role_id) VALUES ('Admin', :email, :password, 'Admin', 1)");
            $create_admin->bindParam(':email', $adminEmail);
            $create_admin->bindParam(':password', $hashedPassword);
            
            if ($create_admin->execute()) {
                echo "<p class='text-success'>✓ Admin user created successfully</p>";
            } else {
                echo "<p class='text-warning'>⚠ Could not create admin user</p>";
            }
            
            // Update company settings with provided data
            if (!empty($companyData)) {
                $settings_updated = 0;
                $setting_mappings = [
                    'company_name' => 'company_name',
                    'company_address' => 'company_address', 
                    'company_phone' => 'company_phone',
                    'company_email' => 'company_email',
                    'currency_symbol' => 'currency_symbol',
                    'tax_rate' => 'tax_rate'
                ];
                
                foreach ($setting_mappings as $form_key => $db_key) {
                    if (isset($companyData[$form_key]) && !empty(trim($companyData[$form_key]))) {
                        $update_setting = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
                        $update_setting->bindParam(':value', $companyData[$form_key]);
                        $update_setting->bindParam(':key', $db_key);
                        if ($update_setting->execute()) {
                            $settings_updated++;
                        }
                    }
                }
                
                // Update receipt contact with company email if provided
                if (!empty($companyData['company_email'])) {
                    $receipt_contact = "Contact: " . $companyData['company_email'];
                    $update_receipt = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = 'receipt_contact'");
                    $update_receipt->bindParam(':value', $receipt_contact);
                    $update_receipt->execute();
                }
                
                echo "<p class='text-success'>✓ Updated $settings_updated company settings</p>";
            }
            
            // Check default categories
            $cat_check = $conn->query("SELECT COUNT(*) as count FROM categories");
            $cat_count = $cat_check->fetch(PDO::FETCH_ASSOC)['count'];
            echo "<p>✓ Created $cat_count default categories</p>";
            
        } else {
            throw new Exception("Database recreation failed");
        }
        
    } catch (Exception $e) {
        echo "<p class='text-danger'>✗ Schema creation failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        $errors[] = "Schema creation failed";
    }
    echo "</div>";
}

// Step 5: Create Installation Marker
if (empty($errors)) {
    echo "<div class='step success'>";
    echo "<h4><i class='bi bi-5-circle me-2'></i>Step 5: Finalizing Installation</h4>";
    
    try {
        // Create storage directory if it doesn't exist
        if (!is_dir($storage_dir)) {
            mkdir($storage_dir, 0775, true);
            echo "<p>✓ Created storage directory</p>";
        }
        
        // Create installation marker
        file_put_contents($marker_file, date('Y-m-d H:i:s'));
        echo "<p>✓ Created installation marker</p>";
        
        // Set success session
        $_SESSION['login_success'] = true;
        $_SESSION['installation_complete'] = true;
        
        // Clear installation session data
        unset($_SESSION['install_step'], $_SESSION['admin_email'], $_SESSION['admin_password'], $_SESSION['company_data']);
        
        echo "<p class='text-success'><strong>✓ Installation completed successfully!</strong></p>";
        
    } catch (Exception $e) {
        echo "<p class='text-danger'>✗ Finalization failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        $errors[] = "Installation finalization failed";
    }
    echo "</div>";
}

// Final Results
echo "<div class='step " . (empty($errors) ? 'success' : 'error') . "'>";
echo "<h4><i class='bi bi-check-circle me-2'></i>Installation Results</h4>";

if (empty($errors)) {
    echo "<div class='alert alert-success'>";
    echo "<h5><i class='bi bi-check-circle-fill me-2'></i>Installation Successful!</h5>";
    echo "<p>Your POS system has been freshly installed and configured.</p>";
    
    // Show configured details
    echo "<div class='row mt-3'>";
    echo "<div class='col-md-6'>";
    echo "<h6><i class='bi bi-person-check me-2'></i>Administrator Account:</h6>";
    echo "<ul class='mb-0'>";
    echo "<li>Email: " . htmlspecialchars($adminEmail) . "</li>";
    echo "<li>Password: [Your secure password]</li>";
    echo "</ul>";
    echo "</div>";
    if (!empty($companyData['company_name'])) {
        echo "<div class='col-md-6'>";
        echo "<h6><i class='bi bi-building me-2'></i>Company Information:</h6>";
        echo "<ul class='mb-0'>";
        echo "<li>Name: " . htmlspecialchars($companyData['company_name']) . "</li>";
        if (!empty($companyData['currency_symbol'])) {
            echo "<li>Currency: " . htmlspecialchars($companyData['currency_symbol']) . "</li>";
        }
        if (!empty($companyData['tax_rate'])) {
            echo "<li>Tax Rate: " . htmlspecialchars($companyData['tax_rate']) . "%</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<hr class='my-3'>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li>Login with your administrator credentials</li>";
    echo "<li>Add your products and categories</li>";
    echo "<li>Configure additional settings if needed</li>";
    echo "<li>Start processing sales</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='text-center mt-4'>";
    echo "<a href='auth/login.php' class='btn btn-primary btn-lg me-3'>";
    echo "<i class='bi bi-box-arrow-in-right me-2'></i>Go to Login";
    echo "</a>";
    echo "<a href='index.php' class='btn btn-success btn-lg'>";
    echo "<i class='bi bi-speedometer2 me-2'></i>Go to Dashboard";
    echo "</a>";
    echo "</div>";
    
} else {
    echo "<div class='alert alert-danger'>";
    echo "<h5><i class='bi bi-exclamation-triangle-fill me-2'></i>Installation Failed!</h5>";
    echo "<p>The following errors occurred during installation:</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='text-center mt-4'>";
    echo "<a href='fresh_install.php' class='btn btn-warning btn-lg me-3'>";
    echo "<i class='bi bi-arrow-clockwise me-2'></i>Retry Installation";
    echo "</a>";
    echo "<a href='check_database.php' class='btn btn-info btn-lg'>";
    echo "<i class='bi bi-clipboard-data me-2'></i>Check Database";
    echo "</a>";
    echo "</div>";
}

echo "</div>";

echo "</div></div></div></div></div>";
echo "<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>";
echo "</body></html>";

} // End of if ($showForm) else block
?>