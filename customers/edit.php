<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
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

// Check if user has permission to edit customers
if (!hasPermission('edit_customers', $permissions)) {
    header("Location: index.php");
    exit();
}

// Get customer ID from URL
$customer_id = intval($_GET['id'] ?? 0);
if (!$customer_id) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get customer details
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = :customer_id");
$stmt->bindParam(':customer_id', $customer_id);
$stmt->execute();
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: index.php");
    exit();
}

// Prevent editing of walk-in customer
if ($customer['customer_type'] === 'walk_in') {
    header("Location: view.php?id=$customer_id");
    exit();
}

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Basic information
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');

        // Address information
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $country = trim($_POST['country'] ?? 'USA');

        // Personal information
        $date_of_birth = trim($_POST['date_of_birth'] ?? '');
        $gender = trim($_POST['gender'] ?? '');

        // Business information
        $customer_type = trim($_POST['customer_type'] ?? 'individual');
        $company_name = trim($_POST['company_name'] ?? '');
        $tax_id = trim($_POST['tax_id'] ?? '');

        // Financial information
        $credit_limit = floatval($_POST['credit_limit'] ?? 0);
        $preferred_payment_method = trim($_POST['preferred_payment_method'] ?? '');
        $loyalty_points = intval($_POST['loyalty_points'] ?? 0);
        $membership_status = trim($_POST['membership_status'] ?? 'active');
        $membership_level = trim($_POST['membership_level'] ?? 'Bronze');

        // Notes
        $notes = trim($_POST['notes'] ?? '');

        // Validation
        if (empty($first_name)) {
            $errors[] = 'First name is required';
        }

        if (empty($last_name)) {
            $errors[] = 'Last name is required';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address';
        }

        if (!empty($email)) {
            // Check if email already exists (excluding current customer)
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE email = :email AND id != :customer_id");
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->execute();
            if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                $errors[] = 'A customer with this email already exists';
            }
        }

        if (empty($phone) && empty($mobile) && empty($email)) {
            $errors[] = 'At least one contact method (phone, mobile, or email) is required';
        }

        if ($customer_type === 'business' && empty($company_name)) {
            $errors[] = 'Company name is required for business customers';
        }

        if ($credit_limit < 0) {
            $errors[] = 'Credit limit cannot be negative';
        }

        if ($loyalty_points < 0) {
            $errors[] = 'Loyalty points cannot be negative';
        }

        if (empty($errors)) {
            // Update customer
            $stmt = $conn->prepare("
                UPDATE customers SET
                    first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone = :phone,
                    mobile = :mobile,
                    address = :address,
                    city = :city,
                    state = :state,
                    zip_code = :zip_code,
                    country = :country,
                    date_of_birth = :date_of_birth,
                    gender = :gender,
                    customer_type = :customer_type,
                    company_name = :company_name,
                    tax_id = :tax_id,
                    credit_limit = :credit_limit,
                    loyalty_points = :loyalty_points,
                    membership_status = :membership_status,
                    membership_level = :membership_level,
                    preferred_payment_method = :preferred_payment_method,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :customer_id
            ");

            $stmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':phone' => $phone,
                ':mobile' => $mobile,
                ':address' => $address,
                ':city' => $city,
                ':state' => $state,
                ':zip_code' => $zip_code,
                ':country' => $country,
                ':date_of_birth' => $date_of_birth ?: null,
                ':gender' => $gender ?: null,
                ':customer_type' => $customer_type,
                ':company_name' => $company_name,
                ':tax_id' => $tax_id,
                ':credit_limit' => $credit_limit,
                ':loyalty_points' => $loyalty_points,
                ':membership_status' => $membership_status,
                ':membership_level' => $membership_level,
                ':preferred_payment_method' => $preferred_payment_method,
                ':notes' => $notes,
                ':customer_id' => $customer_id
            ]);

            // Log activity
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, details, created_at)
                VALUES (:user_id, :action, :details, NOW())
            ");
            $log_stmt->execute([
                ':user_id' => $user_id,
                ':action' => "Updated customer: $first_name $last_name ($customer[customer_number])",
                ':details' => json_encode([
                    'customer_id' => $customer_id,
                    'customer_number' => $customer['customer_number'],
                    'customer_name' => "$first_name $last_name",
                    'customer_type' => $customer_type
                ])
            ]);

            $success = "Customer updated successfully!";

            // Refresh customer data
            $stmt = $conn->prepare("SELECT * FROM customers WHERE id = :customer_id");
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->execute();
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        $errors[] = 'An error occurred while updating the customer: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer - <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            padding: 2rem;
            margin: -1rem -1rem 2rem -1rem;
        }

        .form-section {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #f8fafc;
        }

        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .section-title i {
            margin-right: 0.5rem;
        }

        .form-floating > label {
            color: #64748b;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }

        .required-field::after {
            content: ' *';
            color: #dc3545;
        }

        .customer-type-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .type-option {
            flex: 1;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .type-option:hover {
            border-color: var(--primary-color);
            background: #f8fafc;
        }

        .type-option.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
        }

        .type-option i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .business-fields {
            display: none;
        }

        .alert {
            border-radius: 8px;
            padding: 1rem 1.5rem;
        }

        .customer-info {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid var(--primary-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .customer-info h6 {
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 500;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'customers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb" class="mb-2">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="../dashboard/dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="index.php">Customers</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></a></li>
                            <li class="breadcrumb-item active">Edit</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-pencil-square me-2"></i>Edit Customer</h1>
                    <p class="header-subtitle"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?> - <?php echo htmlspecialchars($customer['customer_number']); ?></p>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-eye me-1"></i>View Customer
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Customers
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Success/Error Messages -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Customer Info Bar -->
            <div class="customer-info">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h6><i class="bi bi-person-circle me-2"></i>Customer Information</h6>
                        <p class="mb-0">
                            <strong><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></strong>
                            (<?php echo htmlspecialchars($customer['customer_number']); ?>)
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="badge bg-primary"><?php echo ucfirst($customer['customer_type']); ?> Customer</span>
                        <span class="badge bg-<?php echo $customer['membership_status'] === 'active' ? 'success' : 'secondary'; ?> ms-2">
                            <?php echo ucfirst($customer['membership_status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <form method="POST" class="form-card">
                        <!-- Customer Type Selection -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-person-badge"></i>Customer Type
                            </h5>
                            <div class="customer-type-selector">
                                <div class="type-option <?php echo ($customer['customer_type'] === 'individual') ? 'selected' : ''; ?>" data-type="individual">
                                    <i class="bi bi-person"></i>
                                    <strong>Individual</strong>
                                    <small class="text-muted">Personal customer</small>
                                </div>
                                <div class="type-option <?php echo ($customer['customer_type'] === 'business') ? 'selected' : ''; ?>" data-type="business">
                                    <i class="bi bi-building"></i>
                                    <strong>Business</strong>
                                    <small class="text-muted">Company customer</small>
                                </div>
                                <div class="type-option <?php echo ($customer['customer_type'] === 'vip') ? 'selected' : ''; ?>" data-type="vip">
                                    <i class="bi bi-star"></i>
                                    <strong>VIP</strong>
                                    <small class="text-muted">Premium customer</small>
                                </div>
                                <div class="type-option <?php echo ($customer['customer_type'] === 'wholesale') ? 'selected' : ''; ?>" data-type="wholesale">
                                    <i class="bi bi-truck"></i>
                                    <strong>Wholesale</strong>
                                    <small class="text-muted">Bulk purchaser</small>
                                </div>
                            </div>
                            <input type="hidden" name="customer_type" id="customer_type" value="<?php echo htmlspecialchars($customer['customer_type']); ?>">
                        </div>

                        <!-- Basic Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-info-circle"></i>Basic Information
                            </h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="first_name" name="first_name"
                                               placeholder="First Name" required
                                               value="<?php echo htmlspecialchars($customer['first_name']); ?>">
                                        <label for="first_name" class="required-field">First Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="last_name" name="last_name"
                                               placeholder="Last Name" required
                                               value="<?php echo htmlspecialchars($customer['last_name']); ?>">
                                        <label for="last_name" class="required-field">Last Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" class="form-control" id="email" name="email"
                                               placeholder="Email Address"
                                               value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                                        <label for="email">Email Address</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                               placeholder="Phone Number"
                                               value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                                        <label for="phone">Phone Number</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="tel" class="form-control" id="mobile" name="mobile"
                                               placeholder="Mobile Number"
                                               value="<?php echo htmlspecialchars($customer['mobile'] ?? ''); ?>">
                                        <label for="mobile">Mobile Number</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="gender" name="gender">
                                            <option value="">Select Gender</option>
                                            <option value="male" <?php echo ($customer['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                            <option value="female" <?php echo ($customer['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                            <option value="other" <?php echo ($customer['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                        <label for="gender">Gender</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                                               value="<?php echo htmlspecialchars($customer['date_of_birth'] ?? ''); ?>">
                                        <label for="date_of_birth">Date of Birth</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Business Information -->
                        <div class="form-section business-fields" style="<?php echo ($customer['customer_type'] === 'business') ? 'display: block;' : 'display: none;'; ?>">
                            <h5 class="section-title">
                                <i class="bi bi-building"></i>Business Information
                            </h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="company_name" name="company_name"
                                               placeholder="Company Name"
                                               value="<?php echo htmlspecialchars($customer['company_name'] ?? ''); ?>">
                                        <label for="company_name">Company Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="tax_id" name="tax_id"
                                               placeholder="Tax ID / Business Number"
                                               value="<?php echo htmlspecialchars($customer['tax_id'] ?? ''); ?>">
                                        <label for="tax_id">Tax ID / Business Number</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-geo-alt"></i>Address Information
                            </h5>
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="form-floating">
                                        <textarea class="form-control" id="address" name="address" rows="3"
                                                  placeholder="Street Address"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                                        <label for="address">Street Address</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="city" name="city"
                                               placeholder="City"
                                               value="<?php echo htmlspecialchars($customer['city'] ?? ''); ?>">
                                        <label for="city">City</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="state" name="state"
                                               placeholder="State/Province"
                                               value="<?php echo htmlspecialchars($customer['state'] ?? ''); ?>">
                                        <label for="state">State/Province</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="zip_code" name="zip_code"
                                               placeholder="ZIP/Postal Code"
                                               value="<?php echo htmlspecialchars($customer['zip_code'] ?? ''); ?>">
                                        <label for="zip_code">ZIP/Postal Code</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="country" name="country"
                                               placeholder="Country"
                                               value="<?php echo htmlspecialchars($customer['country'] ?? 'USA'); ?>">
                                        <label for="country">Country</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Membership & Loyalty -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-star"></i>Membership & Loyalty
                            </h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="membership_status" name="membership_status">
                                            <option value="active" <?php echo ($customer['membership_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo ($customer['membership_status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="suspended" <?php echo ($customer['membership_status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                        <label for="membership_status">Membership Status</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="membership_level" name="membership_level">
                                            <option value="Bronze" <?php echo ($customer['membership_level'] === 'Bronze') ? 'selected' : ''; ?>>Bronze</option>
                                            <option value="Silver" <?php echo ($customer['membership_level'] === 'Silver') ? 'selected' : ''; ?>>Silver</option>
                                            <option value="Gold" <?php echo ($customer['membership_level'] === 'Gold') ? 'selected' : ''; ?>>Gold</option>
                                            <option value="Platinum" <?php echo ($customer['membership_level'] === 'Platinum') ? 'selected' : ''; ?>>Platinum</option>
                                            <option value="Diamond" <?php echo ($customer['membership_level'] === 'Diamond') ? 'selected' : ''; ?>>Diamond</option>
                                        </select>
                                        <label for="membership_level">Membership Level</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="number" class="form-control" id="loyalty_points" name="loyalty_points"
                                               placeholder="0" min="0"
                                               value="<?php echo htmlspecialchars($customer['loyalty_points'] ?? 0); ?>">
                                        <label for="loyalty_points">Loyalty Points</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Financial Information -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-cash-stack"></i>Financial Information
                            </h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="number" class="form-control" id="credit_limit" name="credit_limit"
                                               placeholder="0.00" step="0.01" min="0"
                                               value="<?php echo htmlspecialchars($customer['credit_limit'] ?? 0); ?>">
                                        <label for="credit_limit">Credit Limit ($)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select class="form-select" id="preferred_payment_method" name="preferred_payment_method">
                                            <option value="">Select Payment Method</option>
                                            <option value="cash" <?php echo ($customer['preferred_payment_method'] === 'cash') ? 'selected' : ''; ?>>Cash</option>
                                            <option value="credit_card" <?php echo ($customer['preferred_payment_method'] === 'credit_card') ? 'selected' : ''; ?>>Credit Card</option>
                                            <option value="debit_card" <?php echo ($customer['preferred_payment_method'] === 'debit_card') ? 'selected' : ''; ?>>Debit Card</option>
                                            <option value="bank_transfer" <?php echo ($customer['preferred_payment_method'] === 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                            <option value="check" <?php echo ($customer['preferred_payment_method'] === 'check') ? 'selected' : ''; ?>>Check</option>
                                            <option value="paypal" <?php echo ($customer['preferred_payment_method'] === 'paypal') ? 'selected' : ''; ?>>PayPal</option>
                                        </select>
                                        <label for="preferred_payment_method">Preferred Payment Method</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="form-section">
                            <h5 class="section-title">
                                <i class="bi bi-sticky"></i>Additional Notes
                            </h5>
                            <div class="form-floating">
                                <textarea class="form-control" id="notes" name="notes" rows="4"
                                          placeholder="Any additional notes about this customer"><?php echo htmlspecialchars($customer['notes'] ?? ''); ?></textarea>
                                <label for="notes">Notes</label>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-end gap-3">
                            <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Update Customer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Customer type selection
        document.querySelectorAll('.type-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('selected'));

                // Add selected class to clicked option
                this.classList.add('selected');

                // Update hidden input
                const type = this.getAttribute('data-type');
                document.getElementById('customer_type').value = type;

                // Show/hide business fields
                const businessFields = document.querySelector('.business-fields');
                if (type === 'business') {
                    businessFields.style.display = 'block';
                    document.getElementById('company_name').setAttribute('required', 'required');
                } else {
                    businessFields.style.display = 'none';
                    document.getElementById('company_name').removeAttribute('required');
                }
            });
        });
    </script>
</body>
</html>
