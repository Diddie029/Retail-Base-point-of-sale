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
    
    // Required fields
    if (empty(trim($companyData['company_name']))) {
        $errors[] = "Company name is required";
    } elseif (strlen(trim($companyData['company_name'])) > 100) {
        $errors[] = "Company name must be less than 100 characters";
    }
    
    // Email validation
    if (!empty($companyData['company_email']) && !filter_var($companyData['company_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid company email format";
    }
    
    // Website validation
    if (!empty($companyData['company_website']) && !filter_var($companyData['company_website'], FILTER_VALIDATE_URL)) {
        $errors[] = "Invalid website URL format (include http:// or https://)";
    }
    
    // Phone number validation
    if (!empty($companyData['company_phone']) && !preg_match('/^[+]?[0-9\s\-\(\)]{10,15}$/', $companyData['company_phone'])) {
        $errors[] = "Invalid phone number format";
    }
    
    // Mobile number validation
    if (!empty($companyData['company_mobile']) && !preg_match('/^[+]?[0-9\s\-\(\)]{10,15}$/', $companyData['company_mobile'])) {
        $errors[] = "Invalid mobile number format";
    }
    
    // Tax rate validation
    if (!empty($companyData['tax_rate']) && (!is_numeric($companyData['tax_rate']) || $companyData['tax_rate'] < 0 || $companyData['tax_rate'] > 100)) {
        $errors[] = "Tax rate must be a number between 0 and 100";
    }
    
    // Tax name validation
    if (!empty($companyData['tax_name']) && strlen($companyData['tax_name']) > 50) {
        $errors[] = "Tax name must be less than 50 characters";
    }
    
    // Postal code validation
    if (!empty($companyData['postal_code']) && !preg_match('/^[0-9A-Za-z\s\-]{3,12}$/', $companyData['postal_code'])) {
        $errors[] = "Invalid postal code format";
    }
    
    // Tax ID validation (basic format check)
    if (!empty($companyData['tax_id_number']) && !preg_match('/^[A-Za-z0-9\-\/]{5,20}$/', $companyData['tax_id_number'])) {
        $errors[] = "Tax ID must be 5-20 characters (letters, numbers, hyphens, slashes only)";
    }
    
    return empty($errors) ? true : $errors;
}

// Check if form is submitted
$currentStep = $_SESSION['install_step'] ?? 'legal';
$formErrors = [];
$adminEmail = '';
$adminPassword = '';
$companyData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['legal_submit'])) {
        // Handle legal agreement acceptance
        $termsAccepted = isset($_POST['accept_terms']);
        $privacyAccepted = isset($_POST['accept_privacy']);
        $eulaAccepted = isset($_POST['accept_eula']);
        
        if (!$termsAccepted || !$privacyAccepted || !$eulaAccepted) {
            $formErrors[] = "You must accept all agreements to continue with the installation.";
        } else {
            $_SESSION['legal_agreements_accepted'] = true;
            $_SESSION['agreements_timestamp'] = date('Y-m-d H:i:s');
            $_SESSION['agreements_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $_SESSION['install_step'] = 'admin';
            $currentStep = 'admin';
        }
    } elseif (isset($_POST['admin_submit'])) {
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
            'business_type' => trim($_POST['business_type'] ?? ''),
            'registration_number' => trim($_POST['registration_number'] ?? ''),
            'tax_id_number' => trim($_POST['tax_id_number'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'state_province' => trim($_POST['state_province'] ?? ''),
            'postal_code' => trim($_POST['postal_code'] ?? ''),
            'country' => trim($_POST['country'] ?? 'Kenya'),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_mobile' => trim($_POST['company_mobile'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'company_website' => trim($_POST['company_website'] ?? ''),
            'currency_symbol' => trim($_POST['currency_symbol'] ?? 'KES'),
            'tax_rate' => trim($_POST['tax_rate'] ?? '16'),
            'tax_name' => trim($_POST['tax_name'] ?? '16'),
            'business_hours' => trim($_POST['business_hours'] ?? ''),
            'timezone' => trim($_POST['timezone'] ?? 'Africa/Nairobi'),
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
    } elseif (isset($_POST['back_to_legal'])) {
        // Go back to legal agreements
        $_SESSION['install_step'] = 'legal';
        $currentStep = 'legal';
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

if ($currentStep === 'legal') {
    // Show legal agreements form
    echo "<h1><i class='bi bi-shield-check me-3'></i>Legal Agreements</h1>";
    echo "<p class='lead text-center mb-4'>Please read and accept all agreements before proceeding with the installation.</p>";
    
    // Progress indicator
    echo "<div class='row mb-4'>";
    echo "<div class='col-12'>";
    echo "<div class='progress' style='height: 10px;'>";
    echo "<div class='progress-bar' role='progressbar' style='width: 25%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-primary fw-bold'>1. Legal Agreements</small>";
    echo "<small class='text-muted'>2. Admin Account</small>";
    echo "<small class='text-muted'>3. Company Details</small>";
    echo "<small class='text-muted'>4. Installation</small>";
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
    
    echo "<form method='POST' action='' id='legalForm'>";
    
    // Terms and Conditions
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'>";
    echo "<h5 class='mb-0'><i class='bi bi-file-text me-2'></i>Terms and Conditions</h5>";
    echo "</div>";
    echo "<div class='card-body'>";
    echo "<div style='max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; background: #f8f9fa;'>";
    echo "<h6>1. Acceptance of Terms</h6>";
    echo "<p>By installing and using this Point of Sale (POS) system, you agree to be bound by these Terms and Conditions.</p>";
    echo "<h6>2. License Grant</h6>";
    echo "<p>You are granted a non-exclusive, non-transferable license to use this software for your business operations.</p>";
    echo "<h6>3. Data Security</h6>";
    echo "<p>You are responsible for maintaining the security of your data and ensuring regular backups are performed.</p>";
    echo "<h6>4. Compliance</h6>";
    echo "<p>You agree to use this software in compliance with all applicable local, national, and international laws and regulations.</p>";
    echo "<h6>5. Support and Updates</h6>";
    echo "<p>Software updates and support are provided as-is. For technical support, please contact <strong>support@thiarara.co.ke</strong>. We reserve the right to modify or discontinue support at any time.</p>";
    echo "<h6>6. Limitation of Liability</h6>";
    echo "<p>The software is provided 'as-is' without warranties. We are not liable for any business losses or damages arising from the use of this software.</p>";
    echo "</div>";
    echo "<div class='form-check mt-3'>";
    echo "<input class='form-check-input' type='checkbox' id='accept_terms' name='accept_terms' required>";
    echo "<label class='form-check-label' for='accept_terms'>";
    echo "I have read and agree to the <strong>Terms and Conditions</strong> <span class='text-danger'>*</span>";
    echo "</label>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Privacy Policy
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'>";
    echo "<h5 class='mb-0'><i class='bi bi-shield-lock me-2'></i>Privacy Policy</h5>";
    echo "</div>";
    echo "<div class='card-body'>";
    echo "<div style='max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; background: #f8f9fa;'>";
    echo "<h6>1. Information Collection</h6>";
    echo "<p>This POS system may collect business transaction data, customer information, and system usage analytics for operational purposes.</p>";
    echo "<h6>2. Data Usage</h6>";
    echo "<p>Collected data is used solely for business operations, reporting, and system functionality. We do not sell or share your data with third parties.</p>";
    echo "<h6>3. Data Storage</h6>";
    echo "<p>All data is stored locally on your systems. You maintain full control over your business data and customer information.</p>";
    echo "<h6>4. Security Measures</h6>";
    echo "<p>We implement industry-standard security measures to protect your data, including encrypted storage and secure access controls.</p>";
    echo "<h6>5. Data Retention</h6>";
    echo "<p>Data is retained according to your business needs and local legal requirements. You can delete data at any time through the system administration panel.</p>";
    echo "<h6>6. Your Rights</h6>";
    echo "<p>You have the right to access, modify, and delete your data at any time. Contact our support team at <strong>support@thiarara.co.ke</strong> for assistance with data management.</p>";
    echo "</div>";
    echo "<div class='form-check mt-3'>";
    echo "<input class='form-check-input' type='checkbox' id='accept_privacy' name='accept_privacy' required>";
    echo "<label class='form-check-label' for='accept_privacy'>";
    echo "I have read and agree to the <strong>Privacy Policy</strong> <span class='text-danger'>*</span>";
    echo "</label>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // End User License Agreement
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'>";
    echo "<h5 class='mb-0'><i class='bi bi-file-earmark-code me-2'></i>End User License Agreement (EULA)</h5>";
    echo "</div>";
    echo "<div class='card-body'>";
    echo "<div style='max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; background: #f8f9fa;'>";
    echo "<h6>1. Software License</h6>";
    echo "<p>This EULA grants you a limited, non-exclusive license to use the POS system software on your premises for legitimate business purposes.</p>";
    echo "<h6>2. Restrictions</h6>";
    echo "<p>You may not: (a) reverse engineer or decompile the software, (b) distribute or resell the software, (c) remove copyright notices, or (d) use the software for illegal activities.</p>";
    echo "<h6>3. Intellectual Property</h6>";
    echo "<p>All intellectual property rights in the software remain with the original developers. This license does not transfer ownership.</p>";
    echo "<h6>4. Updates and Modifications</h6>";
    echo "<p>We may provide updates and modifications to the software. Continued use constitutes acceptance of updated terms.</p>";
    echo "<h6>5. Termination</h6>";
    echo "<p>This license terminates automatically if you breach any terms. Upon termination, you must cease using the software and delete all copies.</p>";
    echo "<h6>6. Disclaimer</h6>";
    echo "<p>The software is provided 'AS-IS' without warranty of any kind. Use at your own risk.</p>";
    echo "</div>";
    echo "<div class='form-check mt-3'>";
    echo "<input class='form-check-input' type='checkbox' id='accept_eula' name='accept_eula' required>";
    echo "<label class='form-check-label' for='accept_eula'>";
    echo "I have read and agree to the <strong>End User License Agreement</strong> <span class='text-danger'>*</span>";
    echo "</label>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Agreement Summary and Consent
    echo "<div class='alert alert-info mb-4'>";
    echo "<h6 class='alert-heading'><i class='bi bi-info-circle me-2'></i>Important Notice</h6>";
    echo "<p class='mb-0'>By accepting these agreements, you acknowledge that:</p>";
    echo "<ul class='mb-0 mt-2'>";
    echo "<li>You have read and understood all terms</li>";
    echo "<li>You agree to use the software responsibly and legally</li>";
    echo "<li>You understand your data security responsibilities</li>";
    echo "<li>Your acceptance is recorded with timestamp and IP address</li>";
    echo "</ul>";
    echo "<p class='mt-2 mb-0'><strong>Need Help?</strong> Contact our support team at <a href='mailto:support@thiarara.co.ke' class='text-decoration-none'>support@thiarara.co.ke</a></p>";
    echo "</div>";
    
    echo "<div class='text-center'>";
    echo "<button type='submit' name='legal_submit' class='btn btn-primary btn-lg px-5'>";
    echo "<i class='bi bi-check-circle me-2'></i>Accept All Agreements & Continue";
    echo "</button>";
    echo "</div>";
    echo "</form>";
    
} elseif ($currentStep === 'admin') {
    // Show admin account setup form
    echo "<h1><i class='bi bi-person-plus-fill me-3'></i>Setup Admin Account</h1>";
    echo "<p class='lead text-center mb-4'>Create your administrator account to manage the POS system.</p>";
    
    // Progress indicator
    echo "<div class='row mb-4'>";
    echo "<div class='col-12'>";
    echo "<div class='progress' style='height: 10px;'>";
    echo "<div class='progress-bar' role='progressbar' style='width: 50%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>1. Legal Agreements</small>";
    echo "<small class='text-primary fw-bold'>2. Admin Account</small>";
    echo "<small class='text-muted'>3. Company Details</small>";
    echo "<small class='text-muted'>4. Installation</small>";
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
    echo "<div class='d-flex justify-content-between'>";
    echo "<button type='submit' name='back_to_legal' class='btn btn-outline-secondary btn-lg'>";
    echo "<i class='bi bi-arrow-left me-2'></i>Back to Agreements";
    echo "</button>";
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
    echo "<div class='progress-bar' role='progressbar' style='width: 75%'></div>";
    echo "</div>";
    echo "<div class='d-flex justify-content-between mt-2'>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>1. Legal Agreements</small>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>2. Admin Account</small>";
    echo "<small class='text-primary fw-bold'>3. Company Details</small>";
    echo "<small class='text-muted'>4. Installation</small>";
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
    
    // Basic Company Information Section
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'><h5><i class='bi bi-building me-2'></i>Basic Information</h5></div>";
    echo "<div class='card-body'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_name' class='form-label'>Company Name <span class='text-danger'>*</span></label>";
    echo "<input type='text' class='form-control' id='company_name' name='company_name' value='" . htmlspecialchars($companyData['company_name'] ?? '') . "' required>";
    echo "<div class='form-text'>This will appear on receipts</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='business_type' class='form-label'>Business Type</label>";
    echo "<select class='form-select' id='business_type' name='business_type'>";
    $businessTypes = ['', 'Retail Store', 'Restaurant', 'Grocery Store', 'Pharmacy', 'Electronics Store', 'Clothing Store', 'Service Provider', 'Other'];
    foreach ($businessTypes as $type) {
        $selected = (($companyData['business_type'] ?? '') === $type) ? 'selected' : '';
        echo "<option value='$type' $selected>$type</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='registration_number' class='form-label'>Registration Number</label>";
    echo "<input type='text' class='form-control' id='registration_number' name='registration_number' value='" . htmlspecialchars($companyData['registration_number'] ?? '') . "'>";
    echo "<div class='form-text'>Business registration number</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='tax_id_number' class='form-label'>Tax ID Number</label>";
    echo "<input type='text' class='form-control' id='tax_id_number' name='tax_id_number' value='" . htmlspecialchars($companyData['tax_id_number'] ?? '') . "'>";
    echo "<div class='form-text'>Tax identification number</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Contact Information Section
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'><h5><i class='bi bi-telephone me-2'></i>Contact Information</h5></div>";
    echo "<div class='card-body'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_email' class='form-label'>Company Email</label>";
    echo "<input type='email' class='form-control' id='company_email' name='company_email' value='" . htmlspecialchars($companyData['company_email'] ?? '') . "'>";
    echo "<div class='form-text'>Contact email for customers</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_website' class='form-label'>Company Website</label>";
    echo "<input type='url' class='form-control' id='company_website' name='company_website' value='" . htmlspecialchars($companyData['company_website'] ?? '') . "' placeholder='https://'>";
    echo "<div class='form-text'>Company website URL</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_phone' class='form-label'>Company Phone</label>";
    echo "<input type='tel' class='form-control' id='company_phone' name='company_phone' value='" . htmlspecialchars($companyData['company_phone'] ?? '') . "'>";
    echo "<div class='form-text'>Main contact phone number</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_mobile' class='form-label'>Company Mobile</label>";
    echo "<input type='tel' class='form-control' id='company_mobile' name='company_mobile' value='" . htmlspecialchars($companyData['company_mobile'] ?? '') . "'>";
    echo "<div class='form-text'>Mobile contact number</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Address Information Section
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'><h5><i class='bi bi-geo-alt me-2'></i>Address Information</h5></div>";
    echo "<div class='card-body'>";
    echo "<div class='mb-3'>";
    echo "<label for='company_address' class='form-label'>Street Address</label>";
    echo "<textarea class='form-control' id='company_address' name='company_address' rows='2' placeholder='Enter street address...'" . ">" . htmlspecialchars($companyData['company_address'] ?? '') . "</textarea>";
    echo "</div>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-4'>";
    echo "<div class='mb-3'>";
    echo "<label for='city' class='form-label'>City</label>";
    echo "<input type='text' class='form-control' id='city' name='city' value='" . htmlspecialchars($companyData['city'] ?? '') . "'>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<div class='mb-3'>";
    echo "<label for='state_province' class='form-label'>State/Province</label>";
    echo "<input type='text' class='form-control' id='state_province' name='state_province' value='" . htmlspecialchars($companyData['state_province'] ?? '') . "'>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-4'>";
    echo "<div class='mb-3'>";
    echo "<label for='postal_code' class='form-label'>Postal Code</label>";
    echo "<input type='text' class='form-control' id='postal_code' name='postal_code' value='" . htmlspecialchars($companyData['postal_code'] ?? '') . "'>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='mb-3'>";
    echo "<label for='country' class='form-label'>Country</label>";
    echo "<select class='form-select' id='country' name='country'>";
    $countries = ['Kenya', 'Nigeria', 'South Africa', 'Ghana', 'Uganda', 'Tanzania', 'Ethiopia', 'United States', 'United Kingdom', 'Canada', 'Australia', 'Other'];
    foreach ($countries as $country) {
        $selected = (($companyData['country'] ?? 'Kenya') === $country) ? 'selected' : '';
        echo "<option value='$country' $selected>$country</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    // Business Configuration Section
    echo "<div class='card mb-4'>";
    echo "<div class='card-header'><h5><i class='bi bi-gear me-2'></i>Business Configuration</h5></div>";
    echo "<div class='card-body'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='currency_symbol' class='form-label'>Currency</label>";
    echo "<select class='form-select' id='currency_symbol' name='currency_symbol'>";
    $currencies = ['KES' => 'KES - Kenyan Shilling', 'USD' => 'USD - US Dollar', 'EUR' => 'EUR - Euro', 'GBP' => 'GBP - British Pound', 'NGN' => 'NGN - Nigerian Naira', 'ZAR' => 'ZAR - South African Rand', 'UGX' => 'UGX - Ugandan Shilling', 'TZS' => 'TZS - Tanzanian Shilling'];
    foreach ($currencies as $code => $name) {
        $selected = (($companyData['currency_symbol'] ?? 'KES') === $code) ? 'selected' : '';
        echo "<option value='$code' $selected>$name</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='tax_rate' class='form-label'>Default Tax Rate (%)</label>";
    echo "<input type='number' class='form-control' id='tax_rate' name='tax_rate' value='" . htmlspecialchars($companyData['tax_rate'] ?? '16') . "' min='0' max='100' step='0.01'>";
    echo "<div class='form-text'>Default tax rate for sales (0-100%)</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='tax_name' class='form-label'>Default Tax Name</label>";
    echo "<input type='text' class='form-control' id='tax_name' name='tax_name' value='" . htmlspecialchars($companyData['tax_name'] ?? '16') . "' maxlength='50'>";
    echo "<div class='form-text'>Name for tax (e.g., VAT, GST, 16)</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='business_hours' class='form-label'>Business Hours</label>";
    echo "<input type='text' class='form-control' id='business_hours' name='business_hours' value='" . htmlspecialchars($companyData['business_hours'] ?? '') . "' placeholder='e.g., Mon-Fri 8AM-6PM'>";
    echo "<div class='form-text'>Operating hours for customer reference</div>";
    echo "</div>";
    echo "</div>";
    echo "<div class='col-md-6'>";
    echo "<div class='mb-3'>";
    echo "<label for='timezone' class='form-label'>Timezone</label>";
    echo "<select class='form-select' id='timezone' name='timezone'>";
    $timezones = ['Africa/Nairobi' => 'Africa/Nairobi (EAT)', 'Africa/Lagos' => 'Africa/Lagos (WAT)', 'Africa/Johannesburg' => 'Africa/Johannesburg (SAST)', 'UTC' => 'UTC', 'America/New_York' => 'America/New_York (EST)', 'Europe/London' => 'Europe/London (GMT)'];
    foreach ($timezones as $tz => $name) {
        $selected = (($companyData['timezone'] ?? 'Africa/Nairobi') === $tz) ? 'selected' : '';
        echo "<option value='$tz' $selected>$name</option>";
    }
    echo "</select>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
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
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>1. Legal Agreements</small>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>2. Admin Account</small>";
    echo "<small class='text-success fw-bold'><i class='bi bi-check-circle-fill me-1'></i>3. Company Details</small>";
    echo "<small class='text-primary fw-bold'>4. Installation</small>";
    echo "</div>";
    echo "</div>";
    echo "</div>";

$steps = [];
$errors = [];

// Global connection validation
function ensureConnection() {
    global $conn;
    if (!isset($conn) || $conn === null) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
            $conn = $GLOBALS['conn'];
            return $conn;
        } else {
            throw new Exception("Database connection is not available");
        }
    }
    return $conn;
}

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
    
    // Ensure $conn is properly initialized
    if (!isset($conn) && isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
    
    if (isset($GLOBALS['db_connected']) && $GLOBALS['db_connected'] === true) {
        echo "<p class='text-success'>✓ Database connection successful</p>";
        echo "<p>Database: pos_system</p>";
        
        // Test basic query with connection validation
        $conn = ensureConnection();
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
        // Ensure $conn is available for reset operations
        $conn = ensureConnection();
        
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
        // Manually recreate all essential tables after reset
        $conn = ensureConnection();
        
        echo "<p>Creating database tables...</p>";
        
        // Disable foreign key checks for safe table creation
        $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Create users table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('Admin','Manager','Cashier','User') NOT NULL,
                role_id INT DEFAULT NULL,
                employment_id VARCHAR(50) DEFAULT NULL UNIQUE,
                status ENUM('active', 'inactive') DEFAULT 'active',
                email VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "<p>✓ Created users table</p>";
        
        // Create categories table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        echo "<p>✓ Created categories table</p>";
        
        // Create products table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                category_id INT NOT NULL,
                sku VARCHAR(100) UNIQUE,
                price DECIMAL(10, 2) NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id)
            )
        ");
        echo "<p>✓ Created products table</p>";
        
        // Create sales table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                customer_name VARCHAR(255) DEFAULT 'Walking Customer',
                total_amount DECIMAL(10, 2) NOT NULL,
                tax_amount DECIMAL(10, 2) DEFAULT 0,
                discount_amount DECIMAL(10, 2) DEFAULT 0,
                final_amount DECIMAL(10, 2) NOT NULL,
                payment_method VARCHAR(50) DEFAULT 'cash',
                sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
        echo "<p>✓ Created sales table</p>";
        
        // Create sale_items table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS sale_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(10, 2) NOT NULL,
                total_price DECIMAL(10, 2) NOT NULL,
                FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )
        ");
        echo "<p>✓ Created sale_items table</p>";
        
        // Create roles table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "<p>✓ Created roles table</p>";
        
        // Create permissions table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "<p>✓ Created permissions table</p>";
        
        // Create role_permissions table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS role_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                role_id INT NOT NULL,
                permission_id INT NOT NULL,
                UNIQUE KEY role_permission (role_id, permission_id),
                FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )
        ");
        echo "<p>✓ Created role_permissions table</p>";
        
        // Create settings table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(50) NOT NULL,
                setting_value TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY setting_key (setting_key)
            )
        ");
        echo "<p>✓ Created settings table</p>";
        
        // Insert default categories
        $default_categories = [
            ['General', 'General products category'],
            ['Electronics', 'Electronic devices and accessories'],
            ['Food & Beverages', 'Food items and drinks'],
            ['Clothing', 'Apparel and fashion items'],
            ['Books', 'Books and educational materials']
        ];
        
        $cat_stmt = $conn->prepare("INSERT IGNORE INTO categories (name, description) VALUES (?, ?)");
        foreach ($default_categories as $category) {
            $cat_stmt->execute($category);
        }
        echo "<p>✓ Inserted default categories</p>";
        
        // Insert default roles
        $default_roles = [
            ['Admin', 'System administrator with full access'],
            ['Manager', 'Manager with business management access'],
            ['Cashier', 'Cashier with sales access'],
            ['User', 'Basic user with limited access']
        ];
        
        $role_stmt = $conn->prepare("INSERT IGNORE INTO roles (name, description) VALUES (?, ?)");
        foreach ($default_roles as $role) {
            $role_stmt->execute($role);
        }
        echo "<p>✓ Inserted default roles</p>";
        
        // Re-enable foreign key checks
        $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        echo "<p class='text-success'><strong>✓ All essential tables created successfully with default data</strong></p>";
        
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
            
            // Store legal agreement acceptance data
            $agreementsTimestamp = $_SESSION['agreements_timestamp'] ?? date('Y-m-d H:i:s');
            $agreementsIp = $_SESSION['agreements_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // Add legal agreement acceptance to settings
            $legal_settings = [
                'legal_agreements_accepted' => '1',
                'legal_agreements_timestamp' => $agreementsTimestamp,
                'legal_agreements_ip' => $agreementsIp,
                'legal_agreements_version' => '1.0',
                'admin_email_at_installation' => $adminEmail,
                'support_email' => 'support@thiarara.co.ke',
                'system_version' => '2.5.0'
            ];
            
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
                    'business_type' => 'business_type',
                    'registration_number' => 'registration_number',
                    'tax_id_number' => 'tax_id_number',
                    'company_address' => 'company_address',
                    'city' => 'city',
                    'state_province' => 'state_province',
                    'postal_code' => 'postal_code',
                    'country' => 'country',
                    'company_phone' => 'company_phone',
                    'company_mobile' => 'company_mobile',
                    'company_email' => 'company_email',
                    'company_website' => 'company_website',
                    'business_hours' => 'business_hours',
                    'currency_symbol' => 'currency_symbol',
                    'tax_rate' => 'tax_rate',
                    'tax_name' => 'tax_name',
                    'timezone' => 'timezone'
                ];
                
                foreach ($setting_mappings as $form_key => $db_key) {
                    if (isset($companyData[$form_key])) {
                        $value = trim($companyData[$form_key]);
                        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing settings
                        $upsert_setting = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = :value2");
                        $upsert_setting->bindParam(':key', $db_key);
                        $upsert_setting->bindParam(':value', $value);
                        $upsert_setting->bindParam(':value2', $value);
                        if ($upsert_setting->execute()) {
                            $settings_updated++;
                        }
                    }
                }
                
                // Add legal agreement settings
                foreach ($legal_settings as $key => $value) {
                    $insert_legal_setting = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = :value2");
                    $insert_legal_setting->bindParam(':key', $key);
                    $insert_legal_setting->bindParam(':value', $value);
                    $insert_legal_setting->bindParam(':value2', $value);
                    if ($insert_legal_setting->execute()) {
                        $settings_updated++;
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
            echo "<li>Tax Rate: " . htmlspecialchars($companyData['tax_rate']) . "% (" . htmlspecialchars($companyData['tax_name'] ?? '16') . ")</li>";
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
    echo "<div class='text-center mt-3'>";
    echo "<small class='text-muted'>";
    echo "Need assistance? Contact our support team at <a href='mailto:support@thiarara.co.ke' class='text-decoration-none'><strong>support@thiarara.co.ke</strong></a>";
    echo "</small>";
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