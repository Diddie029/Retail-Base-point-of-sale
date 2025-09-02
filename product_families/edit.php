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

// Get family ID from URL
$family_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$family_id) {
    header("Location: families.php");
    exit();
}

// Get family data
$stmt = $conn->prepare("SELECT * FROM product_families WHERE id = :id");
$stmt->bindParam(':id', $family_id);
$stmt->execute();
$family = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$family) {
    $_SESSION['error'] = 'Product family not found.';
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

    // Check for duplicate family name (excluding current family)
    if (!empty($family_name)) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM product_families WHERE name = :name AND id != :id");
        $stmt->bindParam(':name', $family_name);
        $stmt->bindParam(':id', $family_id);
        $stmt->execute();
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $errors[] = 'A family with this name already exists.';
        }
    }

    // If no errors, update the family
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                UPDATE product_families SET
                    name = :name,
                    description = :description,
                    base_unit = :base_unit,
                    default_pricing_strategy = :pricing_strategy,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $stmt->bindParam(':name', $family_name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':base_unit', $base_unit);
            $stmt->bindParam(':pricing_strategy', $pricing_strategy);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $family_id);

            if ($stmt->execute()) {
                // Log the activity
                logActivity($conn, $user_id, 'update_product_family', "Updated product family: $family_name (ID: $family_id)");

                $conn->commit();
                $success = true;

                // Redirect to families list with success message
                $_SESSION['success'] = "Product family '$family_name' has been updated successfully.";
                header("Location: families.php");
                exit();
            } else {
                $errors[] = 'Failed to update product family. Please try again.';
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = 'Database error occurred. Please try again.';
            error_log("Family update error: " . $e->getMessage());
        }
    }

    // If there are errors, store them in session for display
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: edit.php?id=$family_id");
        exit();
    }
}

// Get any errors or form data from session
$errors = $_SESSION['errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['errors'], $_SESSION['form_data']);

// Use form data if available, otherwise use family data
$display_data = $form_data ?: $family;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product Family - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Edit Product Family</h1>
                    <div class="header-subtitle">Update family information for "<?php echo htmlspecialchars($family['name']); ?>"</div>
                </div>
                <div class="header-actions">
                    <a href="families.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Families
                    </a>
                    <a href="view.php?id=<?php echo $family_id; ?>" class="btn btn-outline-info">
                        <i class="bi bi-eye"></i>
                        View Family
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

            <!-- Edit Family Form -->
            <div class="form-section">
                <div class="form-card">
                    <div class="form-header">
                        <h3><i class="bi bi-pencil me-2"></i>Family Information</h3>
                        <p class="text-muted mb-0">Update the details for this product family</p>
                    </div>

                    <form method="POST" action="edit.php?id=<?php echo $family_id; ?>" id="editFamilyForm">
                        <div class="row g-3">
                            <!-- Family Name -->
                            <div class="col-md-6">
                                <label for="family_name" class="form-label required">
                                    Family Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="family_name" name="family_name"
                                       value="<?php echo htmlspecialchars($display_data['family_name'] ?? $display_data['name']); ?>"
                                       maxlength="255" required>
                                <div class="form-text">A unique name for the product family</div>
                            </div>

                            <!-- Base Unit -->
                            <div class="col-md-6">
                                <label for="base_unit" class="form-label required">
                                    Base Unit <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="base_unit" name="base_unit"
                                       value="<?php echo htmlspecialchars($display_data['base_unit']); ?>"
                                       maxlength="50" required>
                                <div class="form-text">The standard unit of measurement (e.g., each, kg, liter)</div>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description"
                                          rows="4" maxlength="1000"
                                          placeholder="Describe this product family..."><?php echo htmlspecialchars($display_data['description'] ?? ''); ?></textarea>
                                <div class="form-text">Optional description of the product family</div>
                            </div>

                            <!-- Default Pricing Strategy -->
                            <div class="col-md-6">
                                <label for="pricing_strategy" class="form-label required">
                                    Default Pricing Strategy <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" id="pricing_strategy" name="pricing_strategy" required>
                                    <option value="fixed" <?php echo ($display_data['pricing_strategy'] ?? $display_data['default_pricing_strategy']) === 'fixed' ? 'selected' : ''; ?>>Fixed Price</option>
                                    <option value="cost_based" <?php echo ($display_data['pricing_strategy'] ?? $display_data['default_pricing_strategy']) === 'cost_based' ? 'selected' : ''; ?>>Cost-Based</option>
                                    <option value="market_based" <?php echo ($display_data['pricing_strategy'] ?? $display_data['default_pricing_strategy']) === 'market_based' ? 'selected' : ''; ?>>Market-Based</option>
                                    <option value="dynamic" <?php echo ($display_data['pricing_strategy'] ?? $display_data['default_pricing_strategy']) === 'dynamic' ? 'selected' : ''; ?>>Dynamic Pricing</option>
                                    <option value="hybrid" <?php echo ($display_data['pricing_strategy'] ?? $display_data['default_pricing_strategy']) === 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                </select>
                                <div class="form-text">Default pricing method for products in this family</div>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6">
                                <label for="status" class="form-label required">
                                    Status <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" id="status" name="status" required>
                                    <option value="active" <?php echo ($display_data['status']) === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($display_data['status']) === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Whether the family is active for use</div>
                            </div>
                        </div>

                        <!-- Metadata -->
                        <div class="metadata-section">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="metadata-item">
                                        <label class="metadata-label">Created</label>
                                        <div class="metadata-value"><?php echo date('M j, Y g:i A', strtotime($family['created_at'])); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="metadata-item">
                                        <label class="metadata-label">Last Updated</label>
                                        <div class="metadata-value"><?php echo date('M j, Y g:i A', strtotime($family['updated_at'])); ?></div>
                                    </div>
                                </div>
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
                                Update Family
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Family Statistics -->
            <div class="stats-section">
                <div class="stats-card">
                    <h4><i class="bi bi-bar-chart me-2"></i>Family Statistics</h4>
                    <div class="stats-grid">
                        <?php
                        // Get product count and other stats for this family
                        $stmt = $conn->prepare("
                            SELECT
                                COUNT(p.id) as product_count,
                                COALESCE(SUM(p.quantity), 0) as total_inventory,
                                COALESCE(SUM(p.price * p.quantity), 0) as total_value,
                                COALESCE(AVG(p.price), 0) as avg_price
                            FROM products p
                            WHERE p.product_family_id = :family_id
                        ");
                        $stmt->bindParam(':family_id', $family_id);
                        $stmt->execute();
                        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['product_count']); ?></div>
                            <div class="stat-label">Products</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['total_inventory']); ?></div>
                            <div class="stat-label">Total Inventory</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['total_value'], 2); ?></div>
                            <div class="stat-label">Inventory Value</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['avg_price'], 2); ?></div>
                            <div class="stat-label">Avg Product Price</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('editFamilyForm');

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
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Updating...';
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

        .metadata-section {
            background: #f9fafb;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .metadata-item {
            margin-bottom: 1rem;
        }

        .metadata-item:last-child {
            margin-bottom: 0;
        }

        .metadata-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            display: block;
            margin-bottom: 0.25rem;
        }

        .metadata-value {
            font-size: 0.875rem;
            color: #374151;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .stats-section {
            margin-top: 2rem;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .stats-card h4 {
            margin: 0 0 1.5rem 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1.5rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
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

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>
