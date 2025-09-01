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

// Get supplier ID from URL
$supplier_id = (int)($_GET['id'] ?? 0);
if (!$supplier_id) {
    header("Location: suppliers.php");
    exit();
}

// Get supplier data
$stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = :id");
$stmt->bindParam(':id', $supplier_id);
$stmt->execute();
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    $_SESSION['error'] = 'Supplier not found.';
    header("Location: suppliers.php");
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

// Function for sanitizing supplier input
function sanitizeSupplierInput($input, $type = 'string') {
    $input = trim($input);

    switch ($type) {
        case 'text':
            $input = filter_var($input, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
            break;
        case 'email':
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            break;
        default:
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    return $input;
}

// Enhanced validation function
function validateSupplierUpdate($supplier_id, $expected_name) {
    global $conn;
    
    // Double-check that we're updating the right supplier
    $stmt = $conn->prepare("SELECT id, name FROM suppliers WHERE id = :id");
    $stmt->bindParam(':id', $supplier_id);
    $stmt->execute();
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current) {
        throw new Exception("Supplier with ID $supplier_id not found");
    }
    
    // Log the validation
    error_log("Validating update for supplier ID: $supplier_id, Current name: '" . $current['name'] . "', Expected: '" . $expected_name . "'");
    
    return $current;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging
    error_log("=== SUPPLIER UPDATE DEBUG ===");
    error_log("Supplier ID from URL: " . $supplier_id);
    error_log("Original supplier name: " . $supplier['name']);
    
    // Basic supplier information
    $name = sanitizeSupplierInput($_POST['name'] ?? '');
    $contact_person = sanitizeSupplierInput($_POST['contact_person'] ?? '');
    $email = sanitizeSupplierInput($_POST['email'] ?? '', 'email');
    $phone = sanitizeSupplierInput($_POST['phone'] ?? '', 'phone');
    $address = sanitizeSupplierInput($_POST['address'] ?? '', 'text');
    $payment_terms = sanitizeSupplierInput($_POST['payment_terms'] ?? '');
    $notes = sanitizeSupplierInput($_POST['notes'] ?? '', 'text');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($name)) {
        $errors['name'] = 'Supplier name is required';
    } elseif (strlen($name) < 2) {
        $errors['name'] = 'Supplier name must be at least 2 characters long';
    } elseif (strlen($name) > 255) {
        $errors['name'] = 'Supplier name cannot exceed 255 characters';
    }

    // Check if supplier name already exists (excluding current supplier)
    if (!empty($name)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM suppliers WHERE name = :name AND id != :id");
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':id', $supplier_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors['name'] = 'This supplier name already exists';
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

    // If no errors, update the supplier with enhanced safety checks
    if (empty($errors)) {
        try {
            // Start transaction for safety
            $conn->beginTransaction();
            
            // Validate we're updating the correct supplier
            $current_supplier = validateSupplierUpdate($supplier_id, $supplier['name']);
            
            // Prepare update statement with explicit parameter types
            $update_stmt = $conn->prepare("
                UPDATE suppliers
                SET name = :name,
                    contact_person = :contact_person,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    payment_terms = :payment_terms,
                    notes = :notes,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");

            // Bind parameters with explicit types where needed
            $update_stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $update_stmt->bindParam(':contact_person', $contact_person, PDO::PARAM_STR);
            $update_stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $update_stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
            $update_stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $update_stmt->bindParam(':payment_terms', $payment_terms, PDO::PARAM_STR);
            $update_stmt->bindParam(':notes', $notes, PDO::PARAM_STR);
            $update_stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
            $update_stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);

            if ($update_stmt->execute()) {
                $affected_rows = $update_stmt->rowCount();
                
                // Safety check: ensure only one row was affected
                if ($affected_rows !== 1) {
                    throw new Exception("Unexpected number of rows affected: $affected_rows (expected 1)");
                }
                
                // Log the successful update
                error_log("Successfully updated supplier ID: $supplier_id, affected rows: $affected_rows");
                error_log("Updated to name: '$name'");
                
                // Log the activity
                logActivity($conn, $user_id, 'supplier_updated', "Updated supplier: $name (ID: $supplier_id)");
                
                // Commit transaction
                $conn->commit();

                $_SESSION['success'] = "Supplier '$name' has been updated successfully!";
                header("Location: view.php?id=$supplier_id");
                exit();
            } else {
                throw new Exception("Update statement failed to execute");
            }
        } catch (Exception $e) {
            // Rollback transaction on any error
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            
            $errors['general'] = 'An error occurred while updating the supplier. Please try again.';
            error_log("Supplier update error for ID $supplier_id: " . $e->getMessage());
            
            // Additional logging for debugging
            error_log("POST data during error: " . print_r($_POST, true));
            error_log("Current supplier data: " . print_r($supplier, true));
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Supplier - <?php echo htmlspecialchars($supplier['name']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Edit Supplier</h1>
                    <div class="header-subtitle">Update supplier information for <?php echo htmlspecialchars($supplier['name']); ?> (ID: <?php echo $supplier_id; ?>)</div>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-primary">
                        <i class="bi bi-eye"></i>
                        View Supplier
                    </a>
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

            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <!-- Debug Info (remove in production) -->
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Debug Info:</strong> Editing supplier ID: <?php echo $supplier_id; ?>, Current name: "<?php echo htmlspecialchars($supplier['name']); ?>"
            </div>

            <div class="product-form">
                <form method="POST" id="supplierForm">
                    <!-- Hidden field to double-check supplier ID -->
                    <input type="hidden" name="supplier_id_check" value="<?php echo $supplier_id; ?>">
                    
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
                                       id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? $supplier['name']); ?>"
                                       required placeholder="Enter supplier name" maxlength="255">
                                <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control <?php echo isset($errors['contact_person']) ? 'is-invalid' : ''; ?>"
                                       id="contact_person" name="contact_person" value="<?php echo htmlspecialchars($_POST['contact_person'] ?? $supplier['contact_person']); ?>"
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
                                       id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? $supplier['email']); ?>"
                                       placeholder="supplier@example.com">
                                <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control <?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>"
                                       id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? $supplier['phone']); ?>"
                                       placeholder="+1 (555) 123-4567">
                                <?php if (isset($errors['phone'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control <?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>" id="address" name="address" rows="3"
                                      placeholder="Full address including street, city, state, postal code"><?php echo htmlspecialchars($_POST['address'] ?? $supplier['address']); ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['address']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="is_active" class="form-label">Status</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                       value="1" <?php echo (($_POST['is_active'] ?? $supplier['is_active']) ? 'checked' : ''); ?>>
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
                                       id="payment_terms" name="payment_terms" value="<?php echo htmlspecialchars($_POST['payment_terms'] ?? $supplier['payment_terms']); ?>"
                                       placeholder="e.g., Net 30, COD, Immediate" maxlength="100">
                                <?php if (isset($errors['payment_terms'])): ?>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['payment_terms']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      placeholder="Additional notes about this supplier"><?php echo htmlspecialchars($_POST['notes'] ?? $supplier['notes']); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Update Supplier
                            </button>
                            <a href="view.php?id=<?php echo $supplier_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-x"></i>
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const supplierForm = document.getElementById('supplierForm');
            if (supplierForm) {
                supplierForm.addEventListener('submit', function(e) {
                    // Log form submission for debugging
                    console.log('Form submitted for supplier ID:', <?php echo $supplier_id; ?>);
                    console.log('Supplier name:', document.getElementById('name').value);
                    
                    // Basic validation
                    const nameField = document.getElementById('name');
                    if (!nameField.value.trim()) {
                        e.preventDefault();
                        alert('Supplier name is required');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>
