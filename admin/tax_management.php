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

// Check if user has permission to manage taxes
if (!hasPermission('manage_taxes', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create_tax_category') {
            $name = sanitizeInput($_POST['name']);
            $description = sanitizeInput($_POST['description']);
            
            if (empty($name)) {
                throw new Exception('Tax category name is required');
            }
            
            $stmt = $conn->prepare("
                INSERT INTO tax_categories (name, description, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$name, $description]);
            
            $success_message = "Tax category '$name' created successfully!";
            
        } elseif ($action === 'create_tax_rate') {
            $tax_category_id = (int)$_POST['tax_category_id'];
            $name = sanitizeInput($_POST['name']);
            $rate_percentage = (float)$_POST['rate_percentage'];
            $description = sanitizeInput($_POST['description']);
            $effective_date = $_POST['effective_date'];
            $is_compound = isset($_POST['is_compound']) ? 1 : 0;
            
            if (empty($name) || $rate_percentage < 0 || $rate_percentage > 100) {
                throw new Exception('Invalid tax rate data');
            }
            
            $rate = $rate_percentage / 100; // Convert percentage to decimal
            
            $stmt = $conn->prepare("
                INSERT INTO tax_rates (tax_category_id, name, rate, rate_percentage, description, effective_date, is_compound, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$tax_category_id, $name, $rate, $rate_percentage, $description, $effective_date, $is_compound, $user_id]);
            
            $success_message = "Tax rate '$name' created successfully!";
            
        } elseif ($action === 'update_tax_category') {
            $id = (int)$_POST['id'];
            $name = sanitizeInput($_POST['name']);
            $description = sanitizeInput($_POST['description']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE tax_categories 
                SET name = ?, description = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $is_active, $id]);
            
            $success_message = "Tax category updated successfully!";
            
        } elseif ($action === 'update_tax_rate') {
            $id = (int)$_POST['id'];
            $name = sanitizeInput($_POST['name']);
            $rate_percentage = (float)$_POST['rate_percentage'];
            $description = sanitizeInput($_POST['description']);
            $effective_date = $_POST['effective_date'];
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $is_compound = isset($_POST['is_compound']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $rate = $rate_percentage / 100; // Convert percentage to decimal
            
            $stmt = $conn->prepare("
                UPDATE tax_rates 
                SET name = ?, rate = ?, rate_percentage = ?, description = ?, effective_date = ?, end_date = ?, is_compound = ?, is_active = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$name, $rate, $rate_percentage, $description, $effective_date, $end_date, $is_compound, $is_active, $id]);
            
            $success_message = "Tax rate updated successfully!";
            
        } elseif ($action === 'delete_tax_category') {
            $id = (int)$_POST['id'];
            
            // Check if category has active tax rates
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tax_rates WHERE tax_category_id = ? AND is_active = 1");
            $stmt->execute([$id]);
            $active_rates = $stmt->fetchColumn();
            
            if ($active_rates > 0) {
                throw new Exception('Cannot delete tax category with active tax rates');
            }
            
            $stmt = $conn->prepare("DELETE FROM tax_categories WHERE id = ?");
            $stmt->execute([$id]);
            
            $success_message = "Tax category deleted successfully!";
            
        } elseif ($action === 'delete_tax_rate') {
            $id = (int)$_POST['id'];
            
            $stmt = $conn->prepare("DELETE FROM tax_rates WHERE id = ?");
            $stmt->execute([$id]);
            
            $success_message = "Tax rate deleted successfully!";
            
        } elseif ($action === 'update_default_tax_settings') {
            $default_tax_rate = (float)$_POST['default_tax_rate'];
            $default_tax_name = sanitizeInput($_POST['default_tax_name']);

            if ($default_tax_rate < 0 || $default_tax_rate > 100) {
                throw new Exception('Tax rate must be between 0 and 100');
            }

            if (empty($default_tax_name)) {
                throw new Exception('Tax name is required');
            }

            // Update settings
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at)
                VALUES ('tax_rate', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$default_tax_rate, $default_tax_rate]);

            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value, updated_at)
                VALUES ('tax_name', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
            ");
            $stmt->execute([$default_tax_name, $default_tax_name]);

            $success_message = "Default tax settings updated successfully! Default rate: {$default_tax_rate}% ({$default_tax_name})";

        } elseif ($action === 'create_default_setup') {
            // Create default tax category and rate based on admin settings
            $default_tax_name = $settings['tax_name'] ?? 'VAT';
            $default_tax_rate = $settings['tax_rate'] ?? 0;
            
            if ($default_tax_rate > 0) {
                // Create default tax category
                $stmt = $conn->prepare("
                    INSERT INTO tax_categories (name, description, created_at) 
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$default_tax_name, "Default tax category based on system settings"]);
                $category_id = $conn->lastInsertId();
                
                // Create default tax rate
                $stmt = $conn->prepare("
                    INSERT INTO tax_rates (tax_category_id, name, rate, rate_percentage, description, effective_date, is_compound, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, CURDATE(), 0, ?, NOW())
                ");
                $rate = $default_tax_rate / 100;
                $stmt->execute([
                    $category_id, 
                    "Default {$default_tax_name} Rate", 
                    $rate, 
                    $default_tax_rate, 
                    "Default tax rate from system settings", 
                    $user_id
                ]);
                
                $success_message = "Default tax setup created successfully! A '{$default_tax_name}' category with {$default_tax_rate}% rate has been created.";
            } else {
                $error_message = "Cannot create default setup: Tax rate is not set in admin settings.";
            }
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Get tax categories
$stmt = $conn->query("
    SELECT tc.*, 
           COUNT(tr.id) as tax_rates_count,
           COUNT(CASE WHEN tr.is_active = 1 THEN 1 END) as active_rates_count
    FROM tax_categories tc
    LEFT JOIN tax_rates tr ON tc.id = tr.tax_category_id
    GROUP BY tc.id
    ORDER BY tc.name
");
$tax_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tax rates with category names
$stmt = $conn->query("
    SELECT tr.*, tc.name as category_name, u.username as created_by_name
    FROM tax_rates tr
    JOIN tax_categories tc ON tr.tax_category_id = tc.id
    LEFT JOIN users u ON tr.created_by = u.id
    ORDER BY tc.name, tr.effective_date DESC, tr.name
");
$tax_rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current date for form defaults
$current_date = date('Y-m-d');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/tax-management.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .tax-category-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }

        .tax-category-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }

        .tax-rate-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: #f9fafb;
        }

        .tax-rate-item.active {
            background: #ecfdf5;
            border-color: #10b981;
        }

        .tax-rate-item.inactive {
            background: #fef2f2;
            border-color: #ef4444;
        }

        .rate-badge {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .compound-badge {
            background: #f59e0b;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background: #4f46e5;
            border-color: #4f46e5;
        }

        /* Enhanced Empty State Styles */
        .empty-state-container {
            text-align: center;
            padding: 3rem 2rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px;
            border: 2px dashed #cbd5e1;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, var(--primary-color), #4f46e5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .empty-state-icon i {
            font-size: 2rem;
            color: white;
        }

        .empty-state-title {
            color: #1f2937;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .empty-state-description {
            color: #6b7280;
            margin-bottom: 1.5rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .empty-state-list {
            text-align: left;
            max-width: 400px;
            margin: 0 auto 2rem;
            color: #6b7280;
        }

        .empty-state-list li {
            margin-bottom: 0.5rem;
            position: relative;
            padding-left: 1.5rem;
        }

        .empty-state-list li::before {
            content: "•";
            color: var(--primary-color);
            font-weight: bold;
            position: absolute;
            left: 0;
        }

        .empty-state-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .empty-state-actions .btn {
            min-width: 160px;
        }

        /* Enhanced Error Notices */
        .alert-enhanced {
            border: none;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .alert-enhanced .alert-icon {
            font-size: 1.5rem;
            margin-right: 0.75rem;
        }

        .alert-enhanced .alert-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .alert-enhanced .alert-message {
            margin-bottom: 0;
            line-height: 1.5;
        }

        .alert-success.alert-enhanced {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-danger.alert-enhanced {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-warning.alert-enhanced {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-info.alert-enhanced {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .empty-state-actions {
                flex-direction: column;
                align-items: center;
            }

            .empty-state-actions .btn {
                width: 100%;
                max-width: 280px;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">Tax Management</h1>
                    <p class="text-muted">Manage tax categories, rates, and exemptions</p>
                </div>
                <div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Tax Category
                    </button>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createRateModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Tax Rate
                    </button>
                </div>
            </div>

            <!-- Current Settings Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-gear me-2"></i>Current Tax Settings
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <i class="bi bi-percent-circle text-primary" style="font-size: 2rem;"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1">Default Tax Rate</h6>
                                    <p class="text-muted mb-0"><?php echo number_format($settings['tax_rate'] ?? 0, 2); ?>% (<?php echo htmlspecialchars($settings['tax_name'] ?? 'VAT'); ?>)</p>
                                    <small class="text-muted">Configured in <a href="adminsetting.php" target="_blank">Admin Settings</a></small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <i class="bi bi-tags text-info" style="font-size: 2rem;"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1">Tax Categories</h6>
                                    <p class="text-muted mb-0"><?php echo count($tax_categories); ?> active categories</p>
                                    <small class="text-muted"><?php echo count($tax_rates); ?> total tax rates</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Default Tax Settings Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-gear-fill me-2"></i>Default Tax Settings
                        <button class="btn btn-sm btn-outline-primary float-end" data-bs-toggle="modal" data-bs-target="#editDefaultTaxModal">
                            <i class="bi bi-pencil me-1"></i>Edit Defaults
                        </button>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <i class="bi bi-percent-circle text-success" style="font-size: 2rem;"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1">Default Tax Rate</h6>
                                    <p class="mb-0">
                                        <span class="badge bg-success fs-6 px-3 py-2"><?php echo number_format($settings['tax_rate'] ?? 0, 2); ?>%</span>
                                        <small class="text-muted d-block">Applied to new products</small>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3">
                                    <i class="bi bi-tag text-info" style="font-size: 2rem;"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1">Default Tax Name</h6>
                                    <p class="mb-0">
                                        <span class="badge bg-info fs-6 px-3 py-2"><?php echo htmlspecialchars($settings['tax_name'] ?? 'VAT'); ?></span>
                                        <small class="text-muted d-block">Tax label displayed in receipts</small>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            These settings are used when creating new products and for the quick setup feature.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Enhanced Alerts -->
            <?php if ($success_message): ?>
            <div class="alert alert-success alert-enhanced alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-check-circle alert-icon"></i>
                    <div>
                        <div class="alert-title">Success!</div>
                        <div class="alert-message"><?php echo htmlspecialchars($success_message); ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-enhanced alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-exclamation-triangle alert-icon"></i>
                    <div>
                        <div class="alert-title">Error!</div>
                        <div class="alert-message"><?php echo htmlspecialchars($error_message); ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Tax Categories Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-tags me-2"></i>Tax Categories
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($tax_categories)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-tags text-muted" style="font-size: 3rem;"></i>
                                <h5 class="text-muted mt-3">No Tax Categories</h5>
                                <p class="text-muted">Create your first tax category to get started.</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <?php if (($settings['tax_rate'] ?? 0) > 0): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="create_default_setup">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-magic me-2"></i>Quick Setup from Admin Settings
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                                        <i class="bi bi-plus-circle me-2"></i>Add Tax Category
                                    </button>
                                </div>
                                <?php if (($settings['tax_rate'] ?? 0) == 0): ?>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Set a default tax rate in <a href="adminsetting.php" target="_blank">Admin Settings</a> to enable quick setup
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach ($tax_categories as $category): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="tax-category-card">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($category['name']); ?></h6>
                                                <p class="text-muted small mb-0"><?php echo htmlspecialchars($category['description'] ?? ''); ?></p>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)">
                                                        <i class="bi bi-pencil me-2"></i>Edit
                                                    </a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['name']); ?>')">
                                                        <i class="bi bi-trash me-2"></i>Delete
                                                    </a></li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="status-badge <?php echo $category['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo $category['active_rates_count']; ?> active rates
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tax Rates Section -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-percent me-2"></i>Tax Rates
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($tax_rates)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-percent text-muted" style="font-size: 3rem;"></i>
                                <h5 class="text-muted mt-3">No Tax Rates</h5>
                                <p class="text-muted">Create your first tax rate to get started.</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <?php if (($settings['tax_rate'] ?? 0) > 0 && !empty($tax_categories)): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="create_default_setup">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-magic me-2"></i>Quick Setup from Admin Settings
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRateModal">
                                        <i class="bi bi-plus-circle me-2"></i>Add Tax Rate
                                    </button>
                                </div>
                                <?php if (($settings['tax_rate'] ?? 0) == 0): ?>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Set a default tax rate in <a href="adminsetting.php" target="_blank">Admin Settings</a> to enable quick setup
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Name</th>
                                            <th>Rate</th>
                                            <th>Effective Date</th>
                                            <th>End Date</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tax_rates as $rate): ?>
                                        <tr class="<?php echo $rate['is_active'] ? 'table-success' : 'table-danger'; ?>">
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($rate['category_name']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($rate['name']); ?></td>
                                            <td>
                                                <span class="rate-badge"><?php echo number_format($rate['rate_percentage'], 2); ?>%</span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($rate['effective_date'])); ?></td>
                                            <td>
                                                <?php echo $rate['end_date'] ? date('M d, Y', strtotime($rate['end_date'])) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <?php if ($rate['is_compound']): ?>
                                                <span class="compound-badge">Compound</span>
                                                <?php else: ?>
                                                <span class="badge bg-light text-dark">Simple</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $rate['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $rate['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="#" onclick="editRate(<?php echo htmlspecialchars(json_encode($rate)); ?>)">
                                                            <i class="bi bi-pencil me-2"></i>Edit
                                                        </a></li>
                                                        <li><a class="dropdown-item" href="#" onclick="deleteRate(<?php echo $rate['id']; ?>, '<?php echo htmlspecialchars($rate['name']); ?>')">
                                                            <i class="bi bi-trash me-2"></i>Delete
                                                        </a></li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Tax Category Modal -->
    <div class="modal fade" id="createCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Tax Category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_tax_category">
                        <div class="mb-3">
                            <label for="name" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Tax Category Modal -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tax Category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_tax_category">
                        <input type="hidden" name="id" id="edit_category_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" checked>
                                <label class="form-check-label" for="edit_is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Tax Rate Modal -->
    <div class="modal fade" id="createRateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Tax Rate</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_tax_rate">
                        <div class="mb-3">
                            <label for="tax_category_id" class="form-label">Tax Category *</label>
                            <select class="form-select" id="tax_category_id" name="tax_category_id" required>
                                <option value="">Select a category</option>
                                <?php foreach ($tax_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="rate_name" class="form-label">Rate Name *</label>
                            <input type="text" class="form-control" id="rate_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="rate_percentage" class="form-label">Tax Rate (%) *</label>
                            <input type="number" class="form-control" id="rate_percentage" name="rate_percentage" 
                                   min="0" max="100" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="effective_date" class="form-label">Effective Date *</label>
                            <input type="date" class="form-control" id="effective_date" name="effective_date" 
                                   value="<?php echo $current_date; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="rate_description" class="form-label">Description</label>
                            <textarea class="form-control" id="rate_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_compound" name="is_compound">
                                <label class="form-check-label" for="is_compound">
                                    Compound Tax (calculated on top of other taxes)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Rate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Tax Rate Modal -->
    <div class="modal fade" id="editRateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tax Rate</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_tax_rate">
                        <input type="hidden" name="id" id="edit_rate_id">
                        <div class="mb-3">
                            <label for="edit_tax_category_id" class="form-label">Tax Category *</label>
                            <select class="form-select" id="edit_tax_category_id" name="tax_category_id" required>
                                <option value="">Select a category</option>
                                <?php foreach ($tax_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_rate_name" class="form-label">Rate Name *</label>
                            <input type="text" class="form-control" id="edit_rate_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_rate_percentage" class="form-label">Tax Rate (%) *</label>
                            <input type="number" class="form-control" id="edit_rate_percentage" name="rate_percentage" 
                                   min="0" max="100" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_effective_date" class="form-label">Effective Date *</label>
                            <input type="date" class="form-control" id="edit_effective_date" name="effective_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date">
                        </div>
                        <div class="mb-3">
                            <label for="edit_rate_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_rate_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_compound" name="is_compound">
                                <label class="form-check-label" for="edit_is_compound">
                                    Compound Tax (calculated on top of other taxes)
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" checked>
                                <label class="form-check-label" for="edit_is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Rate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="delete_message">Are you sure you want to delete this item?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" id="delete_form" style="display: inline;">
                        <input type="hidden" name="action" id="delete_action">
                        <input type="hidden" name="id" id="delete_id">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Default Tax Settings Modal -->
    <div class="modal fade" id="editDefaultTaxModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Default Tax Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_default_tax_settings">
                        <div class="mb-3">
                            <label for="default_tax_name" class="form-label">Default Tax Name *</label>
                            <input type="text" class="form-control" id="default_tax_name" name="default_tax_name"
                                   value="<?php echo htmlspecialchars($settings['tax_name'] ?? 'VAT'); ?>" required>
                            <div class="form-text">This will be the default name for tax categories (e.g., VAT, GST, Sales Tax)</div>
                        </div>
                        <div class="mb-3">
                            <label for="default_tax_rate" class="form-label">Default Tax Rate (%) *</label>
                            <input type="number" class="form-control" id="default_tax_rate" name="default_tax_rate"
                                   min="0" max="100" step="0.01" value="<?php echo $settings['tax_rate'] ?? 0; ?>" required>
                            <div class="form-text">Default tax rate percentage (0-100%). This will be applied to new products automatically.</div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Changing these settings will affect:
                            <ul class="mb-0 mt-2">
                                <li>New products created without a specific tax category</li>
                                <li>The "Quick Setup" feature in tax management</li>
                                <li>Default tax calculations in the POS system</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Update Default Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editCategory(category) {
            document.getElementById('edit_category_id').value = category.id;
            document.getElementById('edit_name').value = category.name;
            document.getElementById('edit_description').value = category.description || '';
            document.getElementById('edit_is_active').checked = category.is_active == 1;
            
            const modal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
            modal.show();
        }

        function editRate(rate) {
            document.getElementById('edit_rate_id').value = rate.id;
            document.getElementById('edit_tax_category_id').value = rate.tax_category_id;
            document.getElementById('edit_rate_name').value = rate.name;
            document.getElementById('edit_rate_percentage').value = rate.rate_percentage;
            document.getElementById('edit_effective_date').value = rate.effective_date;
            document.getElementById('edit_end_date').value = rate.end_date || '';
            document.getElementById('edit_rate_description').value = rate.description || '';
            document.getElementById('edit_is_compound').checked = rate.is_compound == 1;
            document.getElementById('edit_is_active').checked = rate.is_active == 1;
            
            const modal = new bootstrap.Modal(document.getElementById('editRateModal'));
            modal.show();
        }

        function deleteCategory(id, name) {
            document.getElementById('delete_message').textContent = `Are you sure you want to delete the tax category "${name}"? This action cannot be undone.`;
            document.getElementById('delete_action').value = 'delete_tax_category';
            document.getElementById('delete_id').value = id;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        function deleteRate(id, name) {
            document.getElementById('delete_message').textContent = `Are you sure you want to delete the tax rate "${name}"? This action cannot be undone.`;
            document.getElementById('delete_action').value = 'delete_tax_rate';
            document.getElementById('delete_id').value = id;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }
    </script>
</body>
</html>
