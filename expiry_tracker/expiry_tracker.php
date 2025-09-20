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

// Check if user has permission to view expiry tracker
if (!hasPermission('view_expiry_alerts', $permissions) && !hasPermission('manage_expiry_tracker', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$days_filter = $_GET['days'] ?? '30';
$search = $_GET['search'] ?? '';

// Build query for expiry data
$where_conditions = ["1=1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "ped.status = ?";
    $params[] = $status_filter;
}

if ($category_filter !== 'all') {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
}

if ($search) {
    $where_conditions[] = "(p.name LIKE ? OR p.sku LIKE ? OR ped.batch_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

// Add expiry date filter based on days
if ($days_filter !== 'all') {
    $where_conditions[] = "ped.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
    $params[] = $days_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Get expiry data
$query = "
    SELECT 
        ped.*,
        p.name as product_name,
        p.sku,
        p.image_url,
        c.name as category_name,
        s.name as supplier_name,
        ec.category_name as expiry_category_name,
        ec.color_code as expiry_color,
        ec.alert_threshold_days
    FROM product_expiry_dates ped
    JOIN products p ON ped.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON ped.supplier_id = s.id
    LEFT JOIN expiry_categories ec ON p.expiry_category_id = ec.id
    WHERE $where_clause
    ORDER BY ped.expiry_date ASC, ped.remaining_quantity DESC
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$expiry_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get expiry categories for filter
$expiry_categories = $conn->query("SELECT id, category_name FROM expiry_categories WHERE is_active = 1 ORDER BY alert_threshold_days")->fetchAll(PDO::FETCH_ASSOC);

// Count alerts by status
$alert_counts = [
    'active' => 0,
    'expired' => 0,
    'disposed' => 0,
    'returned' => 0
];

foreach ($expiry_data as $item) {
    $alert_counts[$item['status']]++;
}

// Get critical alerts (expiring within 7 days)
$critical_alerts = array_filter($expiry_data, function($item) {
    $days_until_expiry = (strtotime($item['expiry_date']) - time()) / (60 * 60 * 24);
    return $days_until_expiry <= 7 && $item['status'] === 'active';
});

$page_title = "Expiry Tracker";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --primary-rgb: <?php echo implode(',', sscanf($settings['theme_color'] ?? '#6366f1', '#%02x%02x%02x')); ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --sidebar-rgb: <?php echo implode(',', sscanf($settings['sidebar_color'] ?? '#1e293b', '#%02x%02x%02x')); ?>;
        }
        
        .page-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
        }
        
        .page-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.15);
            flex-shrink: 0;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .stat-card.critical {
            border-left: 4px solid #f59e0b;
        }
        
        .stat-card.expired {
            border-left: 4px solid #dc2626;
        }
        
        .stat-card.disposed {
            border-left: 4px solid #64748b;
        }
        
        .stat-card.returned {
            border-left: 4px solid #3b82f6;
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }
        
        .stat-card.critical .stat-icon {
            background: #f59e0b;
        }
        
        .stat-card.expired .stat-icon {
            background: #dc2626;
        }
        
        .stat-card.disposed .stat-icon {
            background: #64748b;
        }
        
        .stat-card.returned .stat-icon {
            background: #3b82f6;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0;
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 2rem;
        }
        
        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        .table-responsive {
            border-radius: 12px;
        }
        
        .table th {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .table tbody tr.table-warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
        }
        
        .table tbody tr.table-danger {
            background-color: #fee2e2;
            border-left: 4px solid #dc2626;
        }
        
        .product-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .product-image {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .product-name {
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .product-sku {
            font-size: 0.75rem;
            color: #64748b;
            margin: 0;
        }
        
        .btn {
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
            box-shadow: 0 2px 8px rgba(var(--primary-rgb), 0.3);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.4);
        }
        
        .btn-outline-primary {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.25);
        }
        
        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e2e8f0;
            color: #64748b;
        }
        
        .btn-outline-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #475569;
            transform: translateY(-1px);
        }
        
        .btn-outline-warning {
            background: transparent;
            border: 2px solid #f59e0b;
            color: #f59e0b;
        }
        
        .btn-outline-warning:hover {
            background: #f59e0b;
            border-color: #f59e0b;
            color: white;
            transform: translateY(-1px);
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1rem 0;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
            
            .d-flex.justify-content-between {
                flex-direction: column !important;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'expiry_tracker';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div class="container-fluid">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="../dashboard/dashboard.php" class="text-decoration-none">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Expiry Tracker</li>
                    </ol>
                </nav>
                
                <!-- Page Title and Actions -->
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div class="page-title-section">
                        <div class="d-flex align-items-center mb-2">
                            <div class="page-icon me-3">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div>
                                <h1 class="page-title mb-1"><?php echo $page_title; ?></h1>
                                <p class="page-subtitle mb-0">Monitor and manage product expiry dates</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="page-actions d-flex flex-wrap gap-2">
                        <?php if (hasPermission('manage_expiry_tracker', $permissions)): ?>
                            <a href="add_expiry_date.php" class="btn btn-primary">
                                <i class="bi bi-plus me-2"></i>Add Expiry Date
                            </a>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer me-2"></i>Print Report
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card critical">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <h3 class="stat-number"><?php echo count($critical_alerts); ?></h3>
                    <p class="stat-label">Critical Alerts</p>
                </div>
                
                <div class="stat-card expired">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <h3 class="stat-number"><?php echo $alert_counts['expired']; ?></h3>
                    <p class="stat-label">Expired Items</p>
                </div>
                
                <div class="stat-card disposed">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="bi bi-trash"></i>
                        </div>
                    </div>
                    <h3 class="stat-number"><?php echo $alert_counts['disposed']; ?></h3>
                    <p class="stat-label">Disposed Items</p>
                </div>
                
                <div class="stat-card returned">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="bi bi-arrow-return-left"></i>
                        </div>
                    </div>
                    <h3 class="stat-number"><?php echo $alert_counts['returned']; ?></h3>
                    <p class="stat-label">Returned Items</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>Filters</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status:</label>
                        <select name="status" id="status" class="form-select">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            <option value="disposed" <?php echo $status_filter === 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                            <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="category" class="form-label">Category:</label>
                        <select name="category" id="category" class="form-select">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="days" class="form-label">Expires Within:</label>
                        <select name="days" id="days" class="form-select">
                            <option value="all" <?php echo $days_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="7" <?php echo $days_filter === '7' ? 'selected' : ''; ?>>7 Days</option>
                            <option value="30" <?php echo $days_filter === '30' ? 'selected' : ''; ?>>30 Days</option>
                            <option value="60" <?php echo $days_filter === '60' ? 'selected' : ''; ?>>60 Days</option>
                            <option value="90" <?php echo $days_filter === '90' ? 'selected' : ''; ?>>90 Days</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search:</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" 
                               class="form-control" placeholder="Product name, SKU, or batch number">
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>Filter
                        </button>
                        <a href="expiry_tracker.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-lg me-1"></i>Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Data Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Tracking #</th>
                                <th>Batch Number</th>
                                <th>Expiry Date</th>
                                <th>Days Left</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Category</th>
                                <th>Supplier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expiry_data)): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                        No expiry data found matching the current filters.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expiry_data as $item): ?>
                                    <?php
                                    $days_until_expiry = (strtotime($item['expiry_date']) - time()) / (60 * 60 * 24);
                                    $is_critical = $days_until_expiry <= 7 && $item['status'] === 'active';
                                    $is_expired = $days_until_expiry < 0;
                                    $row_class = $is_critical ? 'table-warning' : ($is_expired ? 'table-danger' : '');
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <div class="product-info">
                                                <?php if ($item['image_url']): ?>
                                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($item['product_name']); ?>" 
                                                         class="product-image">
                                                <?php endif; ?>
                                                <div>
                                                    <div class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                                    <div class="product-sku"><?php echo htmlspecialchars($item['sku']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary text-white">
                                                <?php echo htmlspecialchars($item['expiry_tracking_number'] ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($item['batch_number'] ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo $is_critical ? 'text-warning fw-bold' : ($is_expired ? 'text-danger fw-bold' : ''); ?>">
                                                <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($item['status'] === 'disposed' || $item['status'] === 'returned'): ?>
                                                <span class="text-success fw-bold">
                                                    <i class="bi bi-check-circle"></i> Action Taken
                                                </span>
                                            <?php else: ?>
                                                <span class="<?php echo $is_critical ? 'text-warning fw-bold' : ($is_expired ? 'text-danger fw-bold' : ''); ?>">
                                                    <?php 
                                                    if ($is_expired) {
                                                        echo 'Expired ' . abs(round($days_until_expiry)) . ' days ago';
                                                    } else {
                                                        echo round($days_until_expiry) . ' days';
                                                    }
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?php echo number_format($item['remaining_quantity']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($item['status']) {
                                                    'active' => 'success',
                                                    'expired' => 'danger',
                                                    'disposed' => 'secondary',
                                                    'returned' => 'info',
                                                    default => 'light'
                                                };
                                            ?>">
                                                <?php 
                                                echo match($item['status']) {
                                                    'active' => 'Active',
                                                    'expired' => 'Expired',
                                                    'disposed' => 'Disposed',
                                                    'returned' => 'Returned',
                                                    default => ucfirst($item['status'])
                                                };
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($item['expiry_category_name']): ?>
                                                <span class="badge" style="background-color: <?php echo $item['expiry_color']; ?>; color: white;">
                                                    <?php echo htmlspecialchars($item['expiry_category_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($item['category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['supplier_name'] ?: 'N/A'); ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="view_expiry_item.php?id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-outline-primary btn-sm" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (hasPermission('handle_expired_items', $permissions) && $item['status'] === 'active'): ?>
                                                    <a href="handle_expiry.php?id=<?php echo $item['id']; ?>" 
                                                       class="btn btn-outline-warning btn-sm" title="Handle Expiry">
                                                        <i class="bi bi-tools"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (hasPermission('manage_expiry_tracker', $permissions) && $item['status'] === 'active'): ?>
                                                    <a href="edit_expiry_date.php?id=<?php echo $item['id']; ?>" 
                                                       class="btn btn-outline-secondary btn-sm" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Table Footer -->
                <div class="px-3 py-2 border-top bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Showing <?php echo count($expiry_data); ?> items
                        </small>
                        <small class="text-muted">
                            Last updated: <?php echo date('M d, Y H:i'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
