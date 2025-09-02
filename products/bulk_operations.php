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

// Check if user has permission to perform bulk operations on products
if (!hasPermission('bulk_edit_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get categories and other reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get product statistics
$stats = [];
$stats['total_products'] = $conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
$stats['active_products'] = $conn->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$stats['inactive_products'] = $conn->query("SELECT COUNT(*) FROM products WHERE status = 'inactive'")->fetchColumn();
$stats['low_stock'] = $conn->query("SELECT COUNT(*) FROM products WHERE quantity <= 10")->fetchColumn();
$stats['out_of_stock'] = $conn->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Operations - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Main content layout */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .content {
            padding: 2rem;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Enhanced card styles */
        .bulk-operation-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            overflow: hidden;
            position: relative;
        }
        .bulk-operation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .bulk-operation-card:hover::before {
            opacity: 1;
        }
        .bulk-operation-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        /* Gradient cards for different operations */
        .card-update {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .card-import {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .card-export {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        .card-pricing {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #333;
        }
        .card-status {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
        }
        
        /* Stats card enhancement */
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        /* Operation icons */
        .operation-icon {
            font-size: 4rem;
            opacity: 0.15;
            position: absolute;
            top: 15px;
            right: 20px;
            transition: all 0.3s ease;
        }
        .bulk-operation-card:hover .operation-icon {
            opacity: 0.25;
            transform: scale(1.1) rotate(5deg);
        }
        
        /* Card body enhancements */
        .card-body {
            position: relative;
            padding: 1.5rem;
        }
        
        /* Button enhancements */
        .btn-modern {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            text-transform: none;
            transition: all 0.3s ease;
            border: none;
        }
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        
        /* Quick actions section */
        .quick-actions-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        /* Recent operations table */
        .table-modern {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 20px rgba(0,0,0,0.05);
        }
        
        /* Animated counter */
        .counter {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        /* Pulse animation for stats */
        .stat-item {
            transition: transform 0.3s ease;
        }
        .stat-item:hover {
            transform: scale(1.05);
        }
        
        /* Header styling */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body class="bg-light">
    <!-- Sidebar -->
    <?php
    $current_page = 'bulk_operations';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-tasks me-2"></i>Bulk Operations</h2>
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Products
                    </a>
                </div>

                <!-- Product Statistics -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card stats-card">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-chart-bar me-2"></i>Product Overview</h5>
                                <div class="row text-center">
                                    <div class="col-md-2">
                                        <h3><?php echo number_format($stats['total_products']); ?></h3>
                                        <small>Total Products</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h3><?php echo number_format($stats['active_products']); ?></h3>
                                        <small>Active</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h3><?php echo number_format($stats['inactive_products']); ?></h3>
                                        <small>Inactive</small>
                                    </div>
                                    <div class="col-md-3">
                                        <h3><?php echo number_format($stats['low_stock']); ?></h3>
                                        <small>Low Stock (≤10)</small>
                                    </div>
                                    <div class="col-md-3">
                                        <h3><?php echo number_format($stats['out_of_stock']); ?></h3>
                                        <small>Out of Stock</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Operations Cards -->
                <div class="row">
                    <!-- Mass Product Updates -->
                    <div class="col-md-6 mb-4">
                        <div class="card bulk-operation-card h-100">
                            <div class="card-body">
                                <i class="fas fa-edit operation-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-edit text-primary me-2"></i>Mass Product Updates
                                </h5>
                                <p class="card-text">Update multiple products simultaneously. Change prices, categories, descriptions, and other product details in bulk.</p>
                                <ul class="list-unstyled mb-3">
                                    <li><i class="fas fa-check text-success me-2"></i>Update prices and descriptions</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Change categories and brands</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Modify tax rates</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Update supplier information</li>
                                </ul>
                                <a href="bulk_update.php" class="btn btn-primary w-100">
                                    <i class="fas fa-arrow-right me-2"></i>Start Mass Updates
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Batch Import/Export -->
                    <div class="col-md-6 mb-4">
                        <div class="card bulk-operation-card h-100">
                            <div class="card-body">
                                <i class="fas fa-file-csv operation-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-file-csv text-success me-2"></i>Batch Import/Export
                                </h5>
                                <p class="card-text">Import products from CSV files or export your entire catalog. Advanced mapping and validation options available.</p>
                                <ul class="list-unstyled mb-3">
                                    <li><i class="fas fa-check text-success me-2"></i>CSV import with validation</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Export with custom fields</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Download import templates</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Error reporting</li>
                                </ul>
                                <div class="d-grid gap-2">
                                    <a href="bulk_import.php" class="btn btn-success">
                                        <i class="fas fa-upload me-2"></i>Import Products
                                    </a>
                                    <a href="bulk_export.php" class="btn btn-outline-success">
                                        <i class="fas fa-download me-2"></i>Export Products
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Pricing Changes -->
                    <div class="col-md-6 mb-4">
                        <div class="card bulk-operation-card h-100">
                            <div class="card-body">
                                <i class="fas fa-dollar-sign operation-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-dollar-sign text-warning me-2"></i>Bulk Pricing Changes
                                </h5>
                                <p class="card-text">Apply pricing rules to multiple products at once. Set percentage increases, fixed amounts, or promotional pricing.</p>
                                <ul class="list-unstyled mb-3">
                                    <li><i class="fas fa-check text-success me-2"></i>Percentage-based pricing</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Fixed amount adjustments</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Sale price management</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Category-based pricing</li>
                                </ul>
                                <a href="bulk_pricing.php" class="btn btn-warning w-100">
                                    <i class="fas fa-arrow-right me-2"></i>Manage Pricing
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mass Status Updates -->
                    <div class="col-md-6 mb-4">
                        <div class="card bulk-operation-card h-100">
                            <div class="card-body">
                                <i class="fas fa-toggle-on operation-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-toggle-on text-info me-2"></i>Mass Status Updates
                                </h5>
                                <p class="card-text">Activate or deactivate multiple products based on various criteria. Perfect for seasonal inventory management.</p>
                                <ul class="list-unstyled mb-3">
                                    <li><i class="fas fa-check text-success me-2"></i>Bulk activate/deactivate</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Filter by category/brand</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Stock-based updates</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Date-based criteria</li>
                                </ul>
                                <a href="bulk_status.php" class="btn btn-info w-100">
                                    <i class="fas fa-arrow-right me-2"></i>Update Status
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-primary w-100" onclick="window.location.href='bulk_import.php?action=template'">
                                            <i class="fas fa-download me-2"></i>Download CSV Template
                                        </button>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-warning w-100" onclick="activateLowStock()">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Activate Low Stock Items
                                        </button>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-danger w-100" onclick="deactivateOutOfStock()">
                                            <i class="fas fa-times-circle me-2"></i>Deactivate Out of Stock
                                        </button>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-outline-success w-100" onclick="window.location.href='products.php'">
                                            <i class="fas fa-list me-2"></i>View All Products
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Operations -->
                <?php
                // Get recent bulk operations from logs (if log table exists)
                try {
                    $recent_ops = $conn->query("
                        SELECT * FROM activity_logs 
                        WHERE action LIKE '%bulk%' OR action LIKE '%mass%' 
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $recent_ops = [];
                }
                ?>

                <?php if (!empty($recent_ops)): ?>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Bulk Operations</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Operation</th>
                                                <th>User</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_ops as $op): ?>
                                            <tr>
                                                <td><?php echo date('M j, Y H:i', strtotime($op['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($op['action']); ?></td>
                                                <td><?php echo htmlspecialchars($op['username'] ?? 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($op['details'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function activateLowStock() {
            if (confirm('This will activate all products with stock <= 10. Continue?')) {
                window.location.href = 'bulk_status.php?quick_action=activate_low_stock';
            }
        }

        function deactivateOutOfStock() {
            if (confirm('This will deactivate all products with 0 stock. Continue?')) {
                window.location.href = 'bulk_status.php?quick_action=deactivate_out_of_stock';
            }
        }
    </script>
</body>
</html>
