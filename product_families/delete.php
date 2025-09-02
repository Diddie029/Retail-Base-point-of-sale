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

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reassignment_family_id = isset($_POST['reassignment_family_id']) ? (int)$_POST['reassignment_family_id'] : null;
    $confirm_delete = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes';

    if (!$confirm_delete) {
        $_SESSION['error'] = 'Please confirm the deletion.';
        header("Location: delete.php?id=$family_id");
        exit();
    }

    try {
        $conn->beginTransaction();

        // Get product count before deletion
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_family_id = :family_id");
        $stmt->bindParam(':family_id', $family_id);
        $stmt->execute();
        $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // If reassignment is selected, update products
        if ($reassignment_family_id && $reassignment_family_id !== $family_id) {
            // Verify the target family exists
            $stmt = $conn->prepare("SELECT id FROM product_families WHERE id = :id");
            $stmt->bindParam(':id', $reassignment_family_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $stmt = $conn->prepare("UPDATE products SET product_family_id = :new_family_id WHERE product_family_id = :old_family_id");
                $stmt->bindParam(':new_family_id', $reassignment_family_id);
                $stmt->bindParam(':old_family_id', $family_id);
                $stmt->execute();

                $reassigned_count = $stmt->rowCount();
                $_SESSION['success'] = "Family '$family[name]' has been deleted and $reassigned_count products have been reassigned to another family.";
            } else {
                throw new Exception('Selected reassignment family does not exist.');
            }
        } else {
            // Remove family association from products
            $stmt = $conn->prepare("UPDATE products SET product_family_id = NULL WHERE product_family_id = :family_id");
            $stmt->bindParam(':family_id', $family_id);
            $stmt->execute();

            $updated_count = $stmt->rowCount();
            $_SESSION['success'] = "Family '$family[name]' has been deleted. $updated_count products have been unassigned from this family.";
        }

        // Delete the family
        $stmt = $conn->prepare("DELETE FROM product_families WHERE id = :id");
        $stmt->bindParam(':id', $family_id);
        $stmt->execute();

        // Log the activity
        logActivity($conn, $user_id, 'delete_product_family', "Deleted product family: $family[name] (ID: $family_id) with $product_count products");

        $conn->commit();

        header("Location: families.php");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Failed to delete product family: ' . $e->getMessage();
        error_log("Family deletion error: " . $e->getMessage());
        header("Location: delete.php?id=$family_id");
        exit();
    }
}

// Get all other families for reassignment option
$stmt = $conn->prepare("SELECT id, name FROM product_families WHERE id != :id AND status = 'active' ORDER BY name");
$stmt->bindParam(':id', $family_id);
$stmt->execute();
$other_families = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get products that will be affected
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE product_family_id = :family_id");
$stmt->bindParam(':family_id', $family_id);
$stmt->execute();
$product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Product Family - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Delete Product Family</h1>
                    <div class="header-subtitle">Confirm deletion of "<?php echo htmlspecialchars($family['name']); ?>"</div>
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
            <!-- Warning Alert -->
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Warning:</strong> This action cannot be undone. Deleting this product family will permanently remove it from the system.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <!-- Family Information -->
            <div class="family-info-section">
                <div class="info-card">
                    <div class="info-header">
                        <h3><i class="bi bi-diagram-3 me-2"></i>Family to Delete</h3>
                    </div>
                    <div class="info-content">
                        <div class="info-grid">
                            <div class="info-item">
                                <label>Family Name</label>
                                <div><?php echo htmlspecialchars($family['name']); ?></div>
                            </div>
                            <div class="info-item">
                                <label>Base Unit</label>
                                <div><?php echo htmlspecialchars($family['base_unit']); ?></div>
                            </div>
                            <div class="info-item">
                                <label>Pricing Strategy</label>
                                <div><?php echo ucwords(str_replace('_', ' ', $family['default_pricing_strategy'])); ?></div>
                            </div>
                            <div class="info-item">
                                <label>Status</label>
                                <div>
                                    <span class="badge <?php echo $family['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo ucfirst($family['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <label>Products in Family</label>
                                <div>
                                    <span class="badge badge-info"><?php echo number_format($product_count); ?> products</span>
                                </div>
                            </div>
                            <div class="info-item">
                                <label>Created</label>
                                <div><?php echo date('M j, Y g:i A', strtotime($family['created_at'])); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($family['description'])): ?>
                        <div class="description-section">
                            <label>Description</label>
                            <div><?php echo nl2br(htmlspecialchars($family['description'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Impact Assessment -->
            <div class="impact-section">
                <div class="impact-card">
                    <div class="impact-header">
                        <h3><i class="bi bi-exclamation-circle me-2"></i>Impact Assessment</h3>
                    </div>
                    <div class="impact-content">
                        <div class="impact-item">
                            <div class="impact-icon warning">
                                <i class="bi bi-box"></i>
                            </div>
                            <div class="impact-details">
                                <h4><?php echo number_format($product_count); ?> Products Affected</h4>
                                <p>All products currently assigned to this family will need to be handled.</p>
                            </div>
                        </div>

                        <?php if ($product_count > 0): ?>
                        <div class="impact-item">
                            <div class="impact-icon info">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div class="impact-details">
                                <h4>Product Reassignment Required</h4>
                                <p>You must choose how to handle the <?php echo number_format($product_count); ?> products in this family.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Deletion Options -->
            <div class="options-section">
                <div class="options-card">
                    <div class="options-header">
                        <h3><i class="bi bi-gear me-2"></i>Deletion Options</h3>
                        <p class="text-muted mb-0">Choose how to handle products in this family</p>
                    </div>

                    <form method="POST" action="delete.php?id=<?php echo $family_id; ?>" id="deleteForm">
                        <!-- Reassignment Option -->
                        <?php if ($product_count > 0 && !empty($other_families)): ?>
                        <div class="option-group">
                            <div class="option-header">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reassignment_option" id="reassign" value="reassign" checked>
                                    <label class="form-check-label" for="reassign">
                                        <strong>Reassign to Another Family</strong>
                                    </label>
                                </div>
                            </div>
                            <div class="option-content" id="reassignContent">
                                <p>Move all products to another existing product family:</p>
                                <select class="form-control" name="reassignment_family_id" id="reassignmentFamily">
                                    <option value="">Select Family...</option>
                                    <?php foreach ($other_families as $other_family): ?>
                                    <option value="<?php echo $other_family['id']; ?>"><?php echo htmlspecialchars($other_family['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="option-separator">
                            <span>OR</span>
                        </div>
                        <?php endif; ?>

                        <!-- Unassign Option -->
                        <div class="option-group">
                            <div class="option-header">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="reassignment_option"
                                           id="unassign" value="unassign"
                                           <?php echo ($product_count === 0 || empty($other_families)) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="unassign">
                                        <strong><?php echo $product_count > 0 ? 'Unassign Products' : 'Delete Family'; ?></strong>
                                    </label>
                                </div>
                            </div>
                            <div class="option-content" id="unassignContent">
                                <p><?php echo $product_count > 0 ? 'Remove family association from all products (they will become uncategorized by family).' : 'Delete this empty family.'; ?></p>
                            </div>
                        </div>

                        <!-- Confirmation -->
                        <div class="confirmation-section">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmDelete" name="confirm_delete" value="yes">
                                <label class="form-check-label" for="confirmDelete">
                                    <strong>I understand that this action cannot be undone.</strong>
                                </label>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                                <i class="bi bi-x"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                                <i class="bi bi-trash"></i>
                                Delete Family
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('deleteForm');
            const confirmCheckbox = document.getElementById('confirmDelete');
            const deleteBtn = document.getElementById('deleteBtn');
            const reassignRadio = document.getElementById('reassign');
            const unassignRadio = document.getElementById('unassign');
            const reassignContent = document.getElementById('reassignContent');
            const unassignContent = document.getElementById('unassignContent');
            const reassignmentFamily = document.getElementById('reassignmentFamily');

            // Handle option switching
            function updateOptions() {
                if (reassignRadio && reassignRadio.checked) {
                    if (reassignContent) reassignContent.style.display = 'block';
                    if (unassignContent) unassignContent.style.display = 'none';
                } else {
                    if (reassignContent) reassignContent.style.display = 'none';
                    if (unassignContent) unassignContent.style.display = 'block';
                }
                validateForm();
            }

            if (reassignRadio) reassignRadio.addEventListener('change', updateOptions);
            if (unassignRadio) unassignRadio.addEventListener('change', updateOptions);

            // Handle confirmation checkbox
            confirmCheckbox.addEventListener('change', function() {
                validateForm();
            });

            // Handle reassignment family selection
            if (reassignmentFamily) {
                reassignmentFamily.addEventListener('change', function() {
                    validateForm();
                });
            }

            // Form validation
            function validateForm() {
                let isValid = confirmCheckbox.checked;

                // If reassign is selected, make sure a family is chosen
                if (reassignRadio && reassignRadio.checked && reassignmentFamily) {
                    isValid = isValid && reassignmentFamily.value !== '';
                }

                deleteBtn.disabled = !isValid;
            }

            // Form submission
            form.addEventListener('submit', function(e) {
                if (!confirmCheckbox.checked) {
                    e.preventDefault();
                    alert('Please confirm that you understand this action cannot be undone.');
                    return false;
                }

                if (reassignRadio && reassignRadio.checked && reassignmentFamily && reassignmentFamily.value === '') {
                    e.preventDefault();
                    alert('Please select a family to reassign products to.');
                    return false;
                }

                // Show loading state
                const originalText = deleteBtn.innerHTML;
                deleteBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Deleting...';
                deleteBtn.disabled = true;

                // Re-enable if form doesn't submit
                setTimeout(() => {
                    deleteBtn.innerHTML = originalText;
                    validateForm();
                }, 100);
            });

            // Initialize
            updateOptions();
        });
    </script>

    <style>
        .family-info-section, .impact-section, .options-section {
            margin-bottom: 2rem;
        }

        .info-card, .impact-card, .options-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .info-header, .impact-header, .options-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-header h3, .impact-header h3, .options-header h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .info-content, .impact-content {
            padding: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            display: block;
            margin-bottom: 0.25rem;
        }

        .info-item div {
            font-size: 0.875rem;
            color: #374151;
        }

        .description-section {
            margin-top: 1.5rem;
        }

        .description-section label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            display: block;
            margin-bottom: 0.5rem;
        }

        .description-section div {
            color: #374151;
            line-height: 1.6;
        }

        .impact-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .impact-item:last-child {
            margin-bottom: 0;
        }

        .impact-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .impact-icon.warning { background: #fef3c7; color: #f59e0b; }
        .impact-icon.info { background: #dbeafe; color: #3b82f6; }

        .impact-details h4 {
            margin: 0 0 0.25rem 0;
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
        }

        .impact-details p {
            margin: 0;
            font-size: 0.875rem;
            color: #6b7280;
        }

        .options-card form {
            padding: 2rem;
        }

        .option-group {
            margin-bottom: 2rem;
        }

        .option-header {
            margin-bottom: 1rem;
        }

        .option-content {
            margin-left: 1.5rem;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
        }

        .option-separator {
            text-align: center;
            margin: 1.5rem 0;
            position: relative;
        }

        .option-separator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e5e7eb;
        }

        .option-separator span {
            background: white;
            padding: 0 1rem;
            color: #6b7280;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .confirmation-section {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .impact-item {
                flex-direction: column;
                text-align: center;
            }

            .form-actions {
                flex-direction: column;
            }

            .option-content {
                margin-left: 0;
            }
        }
    </style>
</body>
</html>
