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

// Check if user has permission to view taxes
if (!hasPermission('view_finance', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
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
    <link rel="stylesheet" href="../assets/css/tax-management.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
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
                    <p class="text-muted">View and manage tax categories and rates</p>
                </div>
                <div>
                    <a href="../admin/tax_management.php" class="btn btn-primary">
                        <i class="bi bi-gear me-2"></i>Advanced Tax Settings
                    </a>
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
                                    <small class="text-muted">Configured in <a href="../admin/settings/adminsetting.php" target="_blank">Admin Settings</a></small>
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
                            <div class="empty-state">
                                <i class="bi bi-tags"></i>
                                <h5>No Tax Categories</h5>
                                <p>Tax categories haven't been configured yet.</p>
                                <a href="../admin/tax_management.php" class="btn btn-primary">
                                    <i class="bi bi-gear me-2"></i>Configure Tax Settings
                                </a>
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
                            <div class="empty-state">
                                <i class="bi bi-percent"></i>
                                <h5>No Tax Rates</h5>
                                <p>Tax rates haven't been configured yet.</p>
                                <a href="../admin/tax_management.php" class="btn btn-primary">
                                    <i class="bi bi-gear me-2"></i>Configure Tax Settings
                                </a>
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
                                            <th>Type</th>
                                            <th>Status</th>
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

            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-lightning me-2"></i>Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="d-grid">
                                        <a href="../admin/tax_management.php" class="btn btn-outline-primary">
                                            <i class="bi bi-gear me-2"></i>Manage Tax Categories
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-grid">
                                        <a href="../admin/tax_reports.php" class="btn btn-outline-info">
                                            <i class="bi bi-graph-up me-2"></i>View Tax Reports
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-grid">
                                        <a href="../admin/settings/adminsetting.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-sliders me-2"></i>System Settings
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
