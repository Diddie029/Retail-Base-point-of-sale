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

// Check if user has permission to manage products (includes suppliers)
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$errors = [];
$success = '';

// Input sanitization function for supplier data
function sanitizeSupplierInput($input, $type = 'string') {
    $input = trim($input);

    switch ($type) {
        case 'text':
            $input = filter_var($input, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
            break;
        case 'email':
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            break;
        case 'phone':
            $input = filter_var($input, FILTER_SANITIZE_STRING);
            $input = preg_replace('/[^0-9+\-\s\(\)\.]/', '', $input); // Allow only phone characters
            break;
        default:
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    return $input;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic supplier information
    $name = sanitizeSupplierInput($_POST['name'] ?? '');
    $contact_person = sanitizeSupplierInput($_POST['contact_person'] ?? '');
    $email = sanitizeSupplierInput($_POST['email'] ?? '', 'email');
    $phone = sanitizeSupplierInput($_POST['phone'] ?? '', 'phone');
    $address = sanitizeSupplierInput($_POST['address'] ?? '', 'text');
    $payment_terms = sanitizeSupplierInput($_POST['payment_terms'] ?? '');
    $notes = sanitizeSupplierInput($_POST['notes'] ?? '', 'text');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // In-store pickup information
    $pickup_available = isset($_POST['pickup_available']) ? 1 : 0;
    $pickup_address = sanitizeSupplierInput($_POST['pickup_address'] ?? '', 'text');
    $pickup_hours = sanitizeSupplierInput($_POST['pickup_hours'] ?? '');
    $pickup_instructions = sanitizeSupplierInput($_POST['pickup_instructions'] ?? '', 'text');
    $pickup_contact_person = sanitizeSupplierInput($_POST['pickup_contact_person'] ?? '');
    $pickup_contact_phone = sanitizeSupplierInput($_POST['pickup_contact_phone'] ?? '', 'phone');

    // Validation
    if (empty($name)) {
        $errors['name'] = 'Supplier name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Supplier name must be at least 2 characters long';
    } elseif (strlen($name) > 255) {
        $errors['name'] = 'Supplier name cannot exceed 255 characters';
    }

    // Check if supplier name already exists (case-insensitive)
    if (!empty($name)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count, GROUP_CONCAT(id) as existing_ids FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result['count'] > 0) {
            $errors['name'] = 'This supplier name already exists (ID: ' . $result['existing_ids'] . '). Please use a different name.';
            error_log("Duplicate supplier name attempted: '$name', existing IDs: " . $result['existing_ids']);
        }
    }

    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address';
    }

    // Validate phone if provided
    if (!empty($phone) && !preg_match('/^[\+]?[\d\s\-\(\)\.]{10,}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid phone number';
    }

    // Validate contact person
    if (!empty($contact_person) && strlen($contact_person) > 100) {
        $errors['contact_person'] = 'Contact person name cannot exceed 100 characters';
    }

    // Validate address
    if (!empty($address) && strlen($address) > 1000) {
        $errors['address'] = 'Address cannot exceed 1000 characters';
    }

    // Validate payment terms
    if (!empty($payment_terms) && strlen($payment_terms) > 100) {
        $errors['payment_terms'] = 'Payment terms cannot exceed 100 characters';
    }

    // Validate pickup fields if pickup is available
    if ($pickup_available) {
        if (empty($pickup_address)) {
            $errors['pickup_address'] = 'Pickup address is required when in-store pickup is available';
        } elseif (strlen($pickup_address) > 1000) {
            $errors['pickup_address'] = 'Pickup address cannot exceed 1000 characters';
        }

        if (!empty($pickup_contact_person) && strlen($pickup_contact_person) > 100) {
            $errors['pickup_contact_person'] = 'Pickup contact person name cannot exceed 100 characters';
        }

        if (!empty($pickup_contact_phone) && !preg_match('/^[\+]?[\d\s\-\(\)\.]{10,}$/', $pickup_contact_phone)) {
            $errors['pickup_contact_phone'] = 'Please enter a valid pickup contact phone number';
        }
    }

    // If no errors, save the supplier with additional safety checks
    if (empty($errors)) {
        try {
            // Start transaction for safety
            $conn->beginTransaction();
            
            // Final duplicate check before insert (race condition protection)
            $final_check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))");
            $final_check_stmt->bindParam(':name', $name);
            $final_check_stmt->execute();
            if ($final_check_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                throw new Exception("Supplier name already exists (race condition detected)");
            }
            
            // Debug logging
            error_log("=== SUPPLIER ADD DEBUG ===");
            error_log("Creating supplier: '$name'");
            error_log("POST data: " . print_r($_POST, true));
            
            $insert_stmt = $conn->prepare("
                INSERT INTO suppliers (
                    name, contact_person, email, phone, address, payment_terms, notes, is_active,
                    pickup_available, pickup_address, pickup_hours, pickup_instructions,
                    pickup_contact_person, pickup_contact_phone, created_at, updated_at
                ) VALUES (
                    :name, :contact_person, :email, :phone, :address, :payment_terms, :notes, :is_active,
                    :pickup_available, :pickup_address, :pickup_hours, :pickup_instructions,
                    :pickup_contact_person, :pickup_contact_phone, NOW(), NOW()
                )
            ");

            // Bind parameters with explicit types
            $insert_stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $insert_stmt->bindParam(':contact_person', $contact_person, PDO::PARAM_STR);
            $insert_stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $insert_stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
            $insert_stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $insert_stmt->bindParam(':payment_terms', $payment_terms, PDO::PARAM_STR);
            $insert_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $insert_stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
            $insert_stmt->bindParam(':pickup_available', $pickup_available, PDO::PARAM_INT);
            $insert_stmt->bindParam(':pickup_address', $pickup_address, PDO::PARAM_STR);
            $insert_stmt->bindParam(':pickup_hours', $pickup_hours, PDO::PARAM_STR);
            $insert_stmt->bindParam(':pickup_instructions', $pickup_instructions, PDO::PARAM_STR);
            $insert_stmt->bindParam(':pickup_contact_person', $pickup_contact_person, PDO::PARAM_STR);
            $insert_stmt->bindParam(':pickup_contact_phone', $pickup_contact_phone, PDO::PARAM_STR);

            if ($insert_stmt->execute()) {
                $supplier_id = $conn->lastInsertId();
                
                error_log("Successfully created supplier ID: $supplier_id, name: '$name'");

                // Log the activity
                logActivity($conn, $user_id, 'supplier_created', "Created supplier: $name (ID: $supplier_id)");
                
                // Commit transaction
                $conn->commit();

                $_SESSION['success'] = "Supplier '$name' has been added successfully!";
                header("Location: view.php?id=$supplier_id");
                exit();
            } else {
                throw new Exception("Insert statement failed to execute");
            }
        } catch (Exception $e) {
            // Rollback transaction on any error
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            
            $errors['general'] = 'An error occurred while saving the supplier. Please try again.';
            error_log("Supplier creation error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Supplier - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Add Supplier</h1>
                    <div class="header-subtitle">Create a new supplier for your products</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($errors['general']); ?>
            </div>
            <?php endif; ?>

            <div class="product-form">
                <form method="POST" id="supplierForm">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-info-circle me-2"></i>
                            Basic Information
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name" class="form-label">Supplier Name *</label>
                                <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>"
                                       id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                       required placeholder="Enter supplier name" maxlength="255">
                                <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    The name of the company or supplier
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control <?php echo isset($errors['contact_person']) ? 'is-invalid' : ''; ?>"
                                       id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>"
                                       placeholder="Primary contact person" maxlength="100">
                                <?php if (isset($errors['contact_person'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['contact_person']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>"
                                       id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="supplier@example.com">
                                <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                                       id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       placeholder="+1 (555) 123-4567">
                                <?php if (isset($errors['phone'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" id="address" name="address" rows="3"
                                      placeholder="Full address including street, city, state, postal code"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="is_active" class="form-label">Status</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                       value="1" <?php echo (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Active Supplier
                                </label>
                            </div>
                            <div class="form-text">
                                Only active suppliers can be assigned to products
                            </div>
                        </div>
                    </div>

                    <!-- Business Details -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-building me-2"></i>
                            Business Details
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="payment_terms" class="form-label">Payment Terms</label>
                                <input type="text" class="form-control <?php echo isset($errors['payment_terms']) ? 'is-invalid' : ''; ?>"
                                       id="payment_terms" name="payment_terms" value="<?php echo htmlspecialchars($_POST['payment_terms'] ?? ''); ?>"
                                       placeholder="e.g., Net 30, COD, Immediate" maxlength="100">
                                <?php if (isset($errors['payment_terms'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['payment_terms']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">
                                    Payment terms and conditions
                                </div>
                            </div>

                            <div class="form-group">
                                <!-- Placeholder for future expansion -->
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      placeholder="Additional notes about this supplier"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            <div class="form-text">
                                Any additional information about this supplier
                            </div>
                        </div>
                    </div>

                    <!-- In-Store Pickup -->
                    <div class="form-section">
                        <h4 class="section-title">
                            <i class="bi bi-shop me-2"></i>
                            In-Store Pickup
                        </h4>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>In-Store Pickup:</strong> Enable this if customers can pick up their orders directly from this supplier's physical location instead of waiting for delivery.
                        </div>

                        <div class="form-group">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pickup_available" name="pickup_available"
                                       value="1" <?php echo (isset($_POST['pickup_available']) && $_POST['pickup_available']) ? 'checked' : ''; ?>
                                       onclick="togglePickupFields()">
                                <label class="form-check-label" for="pickup_available">
                                    <strong>In-Store Pickup Available</strong>
                                </label>
                            </div>
                            <div class="form-text">
                                Check this if customers can pick up orders from this supplier's store
                            </div>
                        </div>

                        <div id="pickup-fields" style="display: <?php echo (isset($_POST['pickup_available']) && $_POST['pickup_available']) ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="pickup_address" class="form-label">
                                    Pickup Address *
                                    <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                       title="The physical address where customers can pick up their orders"></i>
                                </label>
                                <textarea class="form-control <?php echo isset($errors['pickup_address']) ? 'is-invalid' : ''; ?>"
                                          id="pickup_address" name="pickup_address" rows="3"
                                          placeholder="Full pickup address including street, city, state, postal code"><?php echo htmlspecialchars($_POST['pickup_address'] ?? ''); ?></textarea>
                                <?php if (isset($errors['pickup_address'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['pickup_address']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Complete address for customer pickup location</div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pickup_hours" class="form-label">
                                        Store Hours
                                        <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                           title="Business hours when customers can pick up their orders"></i>
                                    </label>
                                    <input type="text" class="form-control" id="pickup_hours" name="pickup_hours"
                                           value="<?php echo htmlspecialchars($_POST['pickup_hours'] ?? ''); ?>"
                                           placeholder="e.g., Mon-Fri 9AM-6PM, Sat 10AM-4PM">
                                    <div class="form-text">When customers can pick up orders</div>
                                </div>

                                <div class="form-group">
                                    <label for="pickup_contact_person" class="form-label">
                                        Pickup Contact Person
                                        <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                           title="Person customers should ask for when picking up orders"></i>
                                    </label>
                                    <input type="text" class="form-control <?php echo isset($errors['pickup_contact_person']) ? 'is-invalid' : ''; ?>"
                                           id="pickup_contact_person" name="pickup_contact_person"
                                           value="<?php echo htmlspecialchars($_POST['pickup_contact_person'] ?? ''); ?>"
                                           placeholder="Person to contact for pickup" maxlength="100">
                                    <?php if (isset($errors['pickup_contact_person'])): ?>
                                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['pickup_contact_person']); ?></div>
                                    <?php endif; ?>
                                    <div class="form-text">Staff member for pickup inquiries</div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="pickup_contact_phone" class="form-label">
                                    Pickup Contact Phone
                                    <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                       title="Phone number for customers to call about pickup orders"></i>
                                </label>
                                <input type="tel" class="form-control <?php echo isset($errors['pickup_contact_phone']) ? 'is-invalid' : ''; ?>"
                                       id="pickup_contact_phone" name="pickup_contact_phone"
                                       value="<?php echo htmlspecialchars($_POST['pickup_contact_phone'] ?? ''); ?>"
                                       placeholder="+1 (555) 123-4567">
                                <?php if (isset($errors['pickup_contact_phone'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['pickup_contact_phone']); ?></div>
                                <?php endif; ?>
                                <div class="form-text">Phone number for pickup coordination</div>
                            </div>

                            <div class="form-group">
                                <label for="pickup_instructions" class="form-label">
                                    Pickup Instructions
                                    <i class="bi bi-info-circle text-muted" data-bs-toggle="tooltip" data-bs-placement="top"
                                       title="Special instructions for customers picking up their orders"></i>
                                </label>
                                <textarea class="form-control" id="pickup_instructions" name="pickup_instructions" rows="3"
                                          placeholder="Special pickup instructions, parking info, ID requirements, etc."><?php echo htmlspecialchars($_POST['pickup_instructions'] ?? ''); ?></textarea>
                                <div class="form-text">Instructions and requirements for customers</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Add Supplier
                            </button>
                            <a href="suppliers.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Help Section -->
            <div class="data-section mt-4">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="bi bi-question-circle me-2"></i>
                        Need Help?
                    </h3>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h5><i class="bi bi-truck me-2"></i>Supplier Management</h5>
                        <p class="text-muted">Suppliers help you track where your products come from and manage your procurement relationships. This is essential for inventory management and supplier performance tracking.</p>
                    </div>
                    <div class="col-md-6">
                        <h5><i class="bi bi-info-circle me-2"></i>Business Information</h5>
                        <p class="text-muted">Complete supplier information helps you maintain accurate records for accounting, procurement, and quality assurance purposes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const supplierForm = document.getElementById('supplierForm');
            if (supplierForm) {
                supplierForm.addEventListener('submit', function(e) {
                    let isValid = true;
                    const requiredFields = supplierForm.querySelectorAll('[required]');

                    requiredFields.forEach(field => {
                        const feedback = field.parentNode.querySelector('.invalid-feedback');
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            if (feedback) {
                                feedback.textContent = 'This field is required';
                            }
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                            if (feedback) {
                                feedback.textContent = '';
                            }
                        }
                    });

                    // Validate email
                    const emailField = document.getElementById('email');
                    if (emailField && emailField.value && !emailField.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                        emailField.classList.add('is-invalid');
                        const feedback = emailField.parentNode.querySelector('.invalid-feedback');
                        if (feedback) {
                            feedback.textContent = 'Please enter a valid email address';
                        }
                        isValid = false;
                    }

                    // Validate name length
                    const nameField = document.getElementById('name');
                    if (nameField && nameField.value) {
                        if (nameField.value.length < 2) {
                            nameField.classList.add('is-invalid');
                            const feedback = nameField.parentNode.querySelector('.invalid-feedback');
                            if (feedback) {
                                feedback.textContent = 'Supplier name must be at least 2 characters';
                            }
                            isValid = false;
                        } else if (nameField.value.length > 255) {
                            nameField.classList.add('is-invalid');
                            const feedback = nameField.parentNode.querySelector('.invalid-feedback');
                            if (feedback) {
                                feedback.textContent = 'Supplier name cannot exceed 255 characters';
                            }
                            isValid = false;
                        }
                    }

                    if (!isValid) {
                        e.preventDefault();
                        showAlert('Please fix the errors below', 'danger');
                    }
                });

                // Real-time validation
                const inputs = supplierForm.querySelectorAll('input, textarea');
                inputs.forEach(input => {
                    input.addEventListener('blur', function() {
                        validateField(this);
                    });

                    input.addEventListener('input', function() {
                        if (this.classList.contains('is-invalid')) {
                            validateField(this);
                        }
                    });
                });
            }

            function validateField(field) {
                const feedback = field.parentNode.querySelector('.invalid-feedback');

                if (field.hasAttribute('required') && !field.value.trim()) {
                    field.classList.add('is-invalid');
                    if (feedback) {
                        feedback.textContent = 'This field is required';
                    }
                    return false;
                }

                if (field.type === 'email' && field.value && !field.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                    field.classList.add('is-invalid');
                    if (feedback) {
                        feedback.textContent = 'Please enter a valid email address';
                    }
                    return false;
                }

                if (field.id === 'name' && field.value) {
                    if (field.value.length < 2) {
                        field.classList.add('is-invalid');
                        if (feedback) {
                            feedback.textContent = 'Supplier name must be at least 2 characters';
                        }
                        return false;
                    } else if (field.value.length > 255) {
                        field.classList.add('is-invalid');
                        if (feedback) {
                            feedback.textContent = 'Supplier name cannot exceed 255 characters';
                        }
                        return false;
                    }
                }

                field.classList.remove('is-invalid');
                if (feedback) {
                    feedback.textContent = '';
                }
                return true;
            }

            // Function to toggle pickup fields visibility
            function togglePickupFields() {
                const pickupCheckbox = document.getElementById('pickup_available');
                const pickupFields = document.getElementById('pickup-fields');

                if (pickupCheckbox.checked) {
                    pickupFields.style.display = 'block';
                } else {
                    pickupFields.style.display = 'none';
                    // Clear pickup fields when disabled
                    const fields = ['pickup_address', 'pickup_hours', 'pickup_contact_person', 'pickup_contact_phone', 'pickup_instructions'];
                    fields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.value = '';
                            field.classList.remove('is-invalid');
                        }
                    });
                }
            }

            // Initialize pickup fields on page load
            document.addEventListener('DOMContentLoaded', function() {
                togglePickupFields();
            });
        });
    </script>
</body>
</html>
