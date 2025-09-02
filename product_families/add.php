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

// Check if user has permission to manage product families
if (!hasPermission('manage_boms', $permissions)) {
    header("Location: families.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $success = false;

    // Sanitize and validate inputs
    $family_name = sanitizeInput($_POST['family_name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $base_unit = sanitizeInput($_POST['base_unit'] ?? '');
    $pricing_strategy = sanitizeInput($_POST['pricing_strategy'] ?? 'fixed');
    $status = sanitizeInput($_POST['status'] ?? 'active');

    // Validation
    if (empty($family_name)) {
        $errors[] = 'Family name is required.';
    } elseif (strlen($family_name) > 255) {
        $errors[] = 'Family name cannot exceed 255 characters.';
    }

    if (empty($base_unit)) {
        $errors[] = 'Base unit is required.';
    } elseif (strlen($base_unit) > 50) {
        $errors[] = 'Base unit cannot exceed 50 characters.';
    }

    if (strlen($description) > 1000) {
        $errors[] = 'Description cannot exceed 1000 characters.';
    }

    $valid_pricing_strategies = ['fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid'];
    if (!in_array($pricing_strategy, $valid_pricing_strategies)) {
        $errors[] = 'Invalid pricing strategy selected.';
    }

    $valid_statuses = ['active', 'inactive'];
    if (!in_array($status, $valid_statuses)) {
        $errors[] = 'Invalid status selected.';
    }

    // Check for duplicate family name
    if (!empty($family_name)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_families WHERE name = :name");
        $stmt->bindParam(':name', $family_name);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = 'A family with this name already exists.';
        }
    }

    // If no errors, insert the family
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO product_families (
                    name, description, base_unit, default_pricing_strategy, status, created_at, updated_at
                ) VALUES (
                    :name, :description, :base_unit, :pricing_strategy, :status, NOW(), NOW()
                )
            ");

            $stmt->bindParam(':name', $family_name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':base_unit', $base_unit);
            $stmt->bindParam(':pricing_strategy', $pricing_strategy);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $family_id = $conn->lastInsertId();

                // Log the activity
                logActivity($conn, $user_id, 'create_product_family', "Created product family: $family_name (ID: $family_id)");

                $conn->commit();
                $success = true;

                // Redirect to families list with success message
                $_SESSION['success'] = "Product family '$family_name' has been created successfully.";
                header("Location: families.php");
                exit();
            } else {
                $errors[] = 'Failed to create product family. Please try again.';
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error occurred. Please try again.';
            error_log("Family creation error: " . $e->getMessage());
        }
    }

    // If there are errors, store them in session for display
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: add.php");
        exit();
    }
}

// Get any errors or form data from session
$errors = $_SESSION['errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['errors'], $_SESSION['form_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product Family - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/families.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'families';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Add Product Family</h1>
                    <div class="header-subtitle">Create a new product family for organizing products</div>
                </div>
                <div class="header-actions">
                    <a href="families.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Families
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
            <!-- Display Errors -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <strong>Please fix the following errors:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Add Family Form -->
            <div class="form-section">
                <div class="form-card">
                    <div class="form-header">
                        <h3><i class="bi bi-diagram-3 me-2"></i>Family Information</h3>
                        <p class="text-muted mb-0">Enter the details for the new product family</p>
                    </div>

                    <form method="POST" action="add.php" id="addFamilyForm">
                        <div class="row g-3">
                            <!-- Family Name -->
                            <div class="col-md-6">
                                <label for="family_name" class="form-label required">
                                    Family Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="family_name" name="family_name"
                                       value="<?php echo htmlspecialchars($form_data['family_name'] ?? ''); ?>"
                                       maxlength="255" required>
                                <div class="form-text">A unique name for the product family</div>
                            </div>

                            <!-- Base Unit -->
                            <div class="col-md-6">
                                <label for="base_unit" class="form-label required">
                                    Base Unit <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="base_unit" name="base_unit"
                                       value="<?php echo htmlspecialchars($form_data['base_unit'] ?? 'each'); ?>"
                                       maxlength="50" required>
                                <div class="form-text">The standard unit of measurement (e.g., each, kg, liter)</div>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description"
                                          rows="4" maxlength="1000"
                                          placeholder="Describe this product family..."><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                                <div class="form-text">Optional description of the product family</div>
                            </div>

                            <!-- Default Pricing Strategy -->
                            <div class="col-md-6">
                                <label for="pricing_strategy" class="form-label required">
                                    Default Pricing Strategy <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" id="pricing_strategy" name="pricing_strategy" required>
                                    <option value="fixed" <?php echo ($form_data['pricing_strategy'] ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>>Fixed Price</option>
                                    <option value="cost_based" <?php echo ($form_data['pricing_strategy'] ?? '') === 'cost_based' ? 'selected' : ''; ?>>Cost-Based</option>
                                    <option value="market_based" <?php echo ($form_data['pricing_strategy'] ?? '') === 'market_based' ? 'selected' : ''; ?>>Market-Based</option>
                                    <option value="dynamic" <?php echo ($form_data['pricing_strategy'] ?? '') === 'dynamic' ? 'selected' : ''; ?>>Dynamic Pricing</option>
                                    <option value="hybrid" <?php echo ($form_data['pricing_strategy'] ?? '') === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                </select>
                                <div class="form-text">Default pricing method for products in this family</div>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6">
                                <label for="status" class="form-label required">
                                    Status <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" id="status" name="status" required>
                                    <option value="active" <?php echo ($form_data['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($form_data['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Whether the family is active for use</div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                                <i class="bi bi-x"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i>
                                Create Family
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Help Section -->
            <div class="help-section">
                <div class="help-card">
                    <h5><i class="bi bi-info-circle me-2"></i>Help & Tips</h5>
                    <ul class="mb-0">
                        <li><strong>Family Name:</strong> Choose a descriptive name that clearly identifies the product group</li>
                        <li><strong>Base Unit:</strong> This is the standard unit used for all products in this family</li>
                        <li><strong>Pricing Strategy:</strong> Determines how prices are calculated for products in this family</li>
                        <li><strong>Status:</strong> Inactive families won't be available for new product assignments</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addFamilyForm');

            // Form validation
            form.addEventListener('submit', function(e) {
                const familyName = document.getElementById('family_name').value.trim();
                const baseUnit = document.getElementById('base_unit').value.trim();

                if (!familyName) {
                    e.preventDefault();
                    alert('Please enter a family name.');
                    document.getElementById('family_name').focus();
                    return false;
                }

                if (!baseUnit) {
                    e.preventDefault();
                    alert('Please enter a base unit.');
                    document.getElementById('base_unit').focus();
                    return false;
                }

                // Show loading state
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creating...';
                submitBtn.disabled = true;

                // Re-enable if form doesn't submit (validation failed)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 100);
            });

            // Auto-capitalize first letter of family name
            document.getElementById('family_name').addEventListener('input', function() {
                if (this.value.length === 1) {
                    this.value = this.value.toUpperCase();
                }
            });

            // Character count for description
            const descriptionTextarea = document.getElementById('description');
            const maxLength = 1000;

            if (descriptionTextarea) {
                descriptionTextarea.addEventListener('input', function() {
                    const remaining = maxLength - this.value.length;
                    // Could add a character counter here if needed
                });
            }

            // Auto-focus first field
            document.getElementById('family_name').focus();
        });
    </script>

    <style>
        .form-section {
            margin-bottom: 2rem;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .form-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .form-header h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .form-card form {
            padding: 2rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .help-section {
            margin-top: 2rem;
        }

        .help-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid #e5e7eb;
        }

        .help-card h5 {
            color: #374151;
            margin-bottom: 1rem;
        }

        .help-card ul {
            padding-left: 1.5rem;
        }

        .help-card li {
            margin-bottom: 0.5rem;
            color: #6b7280;
        }

        .required .text-danger {
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .form-card form {
                padding: 1rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .header-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
</body>
</html>
