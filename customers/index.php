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

// Check if user has permission to view customers
if (!hasPermission('view_customers', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle search and filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));

// Get user preference for items per page, default to 50
$per_page_options = [10, 20, 50, 100];
$user_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

// Validate per_page value
if (!in_array($user_per_page, $per_page_options)) {
    $user_per_page = 50;
}

$per_page = $user_per_page;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(c.first_name LIKE :search OR c.last_name LIKE :search OR c.email LIKE :search OR c.phone LIKE :search OR c.customer_number LIKE :search OR c.company_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "c.membership_status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "c.customer_type = :type";
    $params[':type'] = $type_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM customers c $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Get customers with order counts
$sql = "SELECT c.*, 
        COALESCE(sales_count.total_orders, 0) as total_orders,
        COALESCE(sales_count.total_spent, 0) as total_spent,
        COALESCE(sales_count.last_order_date, NULL) as last_order_date
        FROM customers c 
        LEFT JOIN (
            SELECT 
                customer_id,
                customer_name,
                COUNT(*) as total_orders,
                SUM(final_amount) as total_spent,
                MAX(created_at) as last_order_date
            FROM sales 
            WHERE customer_id IS NOT NULL OR customer_name IS NOT NULL
            GROUP BY customer_id, customer_name
        ) sales_count ON (
            c.id = sales_count.customer_id OR 
            (c.first_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', 1) AND 
             c.last_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', -1)) OR
            (c.customer_number = 'WALK-IN-001' AND sales_count.customer_name = 'Walk-in Customer')
        )
        $where_clause 
        ORDER BY c.created_at DESC 
        LIMIT $offset, $per_page";
$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total_customers' => 0,
    'active_customers' => 0,
    'inactive_customers' => 0,
    'vip_customers' => 0,
    'business_customers' => 0,
    'walk_in_customers' => 0,
    'total_orders' => 0,
    'total_revenue' => 0
];

$stats_stmt = $conn->query("
    SELECT
        COUNT(*) as total_customers,
        SUM(CASE WHEN membership_status = 'active' THEN 1 ELSE 0 END) as active_customers,
        SUM(CASE WHEN membership_status = 'inactive' THEN 1 ELSE 0 END) as inactive_customers,
        SUM(CASE WHEN customer_type = 'vip' THEN 1 ELSE 0 END) as vip_customers,
        SUM(CASE WHEN customer_type = 'business' THEN 1 ELSE 0 END) as business_customers,
        SUM(CASE WHEN customer_type = 'walk_in' THEN 1 ELSE 0 END) as walk_in_customers
    FROM customers
");
$stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
$stats = $stats_result ?: $stats;

// Get order statistics
$order_stats_stmt = $conn->query("
    SELECT 
        COUNT(*) as total_orders,
        SUM(final_amount) as total_revenue
    FROM sales
");
$order_stats_result = $order_stats_stmt->fetch(PDO::FETCH_ASSOC);
if ($order_stats_result) {
    $stats['total_orders'] = $order_stats_result['total_orders'];
    $stats['total_revenue'] = $order_stats_result['total_revenue'] ?? 0;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .customer-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .customer-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        .customer-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-suspended {
            background: #fef3c7;
            color: #92400e;
        }

        .type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .type-individual {
            background: #dbeafe;
            color: #1e40af;
        }

        .type-business {
            background: #fef3c7;
            color: #92400e;
        }

        .type-vip {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }

        .type-wholesale {
            background: #ecfdf5;
            color: #065f46;
        }

        .type-walk_in {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
            border: 2px dashed #6b7280;
        }

        .walk-in-customer {
            border: 2px dashed #e5e7eb !important;
            background: linear-gradient(135deg, #f9fafb, #f3f4f6);
        }

        .walk-in-customer .customer-avatar {
            background: linear-gradient(135deg, #6b7280, #9ca3af);
        }

        .walk-in-avatar {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb) !important;
            border: 2px dashed #9ca3af !important;
            color: #6b7280 !important;
        }

        .walk-in-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: white;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3);
            border: 2px solid white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .order-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
        }

        .order-stat {
            text-align: center;
        }

        .order-number, .order-amount, .order-date {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .order-amount {
            color: #059669;
        }

        .order-date {
            color: #6b7280;
            font-size: 0.9rem;
        }

        .stats-card {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
        }

        .stats-label {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
        }

        .search-container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .customer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .pagination-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-top: 2rem;
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
                            <li class="breadcrumb-item active">Customers</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-people me-2"></i>Customer Management</h1>
                    <p class="header-subtitle">Manage your customer database and relationships</p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('create_customers', $permissions)): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add Customer
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('export_customer_data', $permissions)): ?>
                    <button class="btn btn-outline-secondary ms-2" onclick="exportCustomers()">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="customer-card p-4" style="background: linear-gradient(135deg, var(--primary-color), #8b5cf6); color: white;">
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['total_customers']; ?></div>
                                    <div class="stats-label">Total</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['active_customers']; ?></div>
                                    <div class="stats-label">Active</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['vip_customers']; ?></div>
                                    <div class="stats-label">VIP</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['business_customers']; ?></div>
                                    <div class="stats-label">Business</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['walk_in_customers']; ?></div>
                                    <div class="stats-label">Walk-in</div>
                                </div>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['inactive_customers']; ?></div>
                                    <div class="stats-label">Inactive</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Statistics -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="customer-card p-4" style="background: linear-gradient(135deg, #059669, #10b981); color: white;">
                        <div class="row text-center">
                            <div class="col-md-6 col-12 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $stats['total_orders']; ?></div>
                                    <div class="stats-label">Total Orders</div>
                                </div>
                            </div>
                            <div class="col-md-6 col-12 mb-3">
                                <div class="stats-card">
                                    <div class="stats-number">$<?php echo number_format($stats['total_revenue'], 0); ?></div>
                                    <div class="stats-label">Total Revenue</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="search-container">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search customers by name, email, phone, or customer number..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="individual" <?php echo $type_filter === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="business" <?php echo $type_filter === 'business' ? 'selected' : ''; ?>>Business</option>
                            <option value="vip" <?php echo $type_filter === 'vip' ? 'selected' : ''; ?>>VIP</option>
                            <option value="wholesale" <?php echo $type_filter === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                            <option value="walk_in" <?php echo $type_filter === 'walk_in' ? 'selected' : ''; ?>>Walk-in</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="applyFilters()">
                            <i class="bi bi-funnel me-1"></i>Filter
                        </button>
                    </div>
                </div>
            </div>

            <!-- Customers Grid -->
            <?php if (empty($customers)): ?>
                <div class="customer-card empty-state">
                    <i class="bi bi-people"></i>
                    <h3><?php echo !empty($search) || !empty($status_filter) || !empty($type_filter) ? 'No customers found' : 'Welcome to Customer Management'; ?></h3>
                    <p>
                        <?php if (!empty($search) || !empty($status_filter) || !empty($type_filter)): ?>
                            Try adjusting your search criteria or filters.
                        <?php else: ?>
                            The system includes a default "Walk-in Customer" for transactions without customer details.
                            <?php if (hasPermission('create_customers', $permissions)): ?>
                                Add your first registered customer to get started.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php if (hasPermission('create_customers', $permissions) && (empty($search) && empty($status_filter) && empty($type_filter))): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add First Customer
                    </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="customer-grid">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-card p-4 <?php echo ($customer['customer_type'] === 'walk_in') ? 'walk-in-customer' : ''; ?>" style="position: relative;">
                            <?php if ($customer['customer_type'] === 'walk_in'): ?>
                            <div class="walk-in-badge" title="Default Walk-in Customer">
                                <i class="bi bi-pin-fill"></i>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex align-items-start mb-3">
                                <div class="customer-avatar me-3 <?php echo ($customer['customer_type'] === 'walk_in') ? 'walk-in-avatar' : ''; ?>">
                                    <?php if ($customer['customer_type'] === 'walk_in'): ?>
                                        <i class="bi bi-person-walking" style="font-size: 1.3rem; color: #6b7280;"></i>
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1">
                                        <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                        <?php if ($customer['customer_type'] === 'walk_in'): ?>
                                            <span class="badge bg-secondary ms-2" style="font-size: 0.65rem;">DEFAULT</span>
                                        <?php endif; ?>
                                    </h5>
                                    <small class="text-muted"><?php echo htmlspecialchars($customer['customer_number']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge status-<?php echo $customer['membership_status']; ?>">
                                        <?php echo ucfirst($customer['membership_status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <?php if (!empty($customer['email'])): ?>
                                <div class="col-12">
                                    <small class="text-muted">
                                        <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($customer['email']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($customer['phone'])): ?>
                                <div class="col-12">
                                    <small class="text-muted">
                                        <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($customer['phone']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($customer['company_name'])): ?>
                                <div class="col-12">
                                    <small class="text-muted">
                                        <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($customer['company_name']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Order Information -->
                            <div class="order-info mb-3">
                                <div class="row g-2 text-center">
                                    <div class="col-4">
                                        <div class="order-stat">
                                            <div class="order-number"><?php echo $customer['total_orders']; ?></div>
                                            <small class="text-muted">Orders</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="order-stat">
                                            <div class="order-amount">$<?php echo number_format($customer['total_spent'], 0); ?></div>
                                            <small class="text-muted">Spent</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="order-stat">
                                            <div class="order-date">
                                                <?php 
                                                if ($customer['last_order_date']) {
                                                    echo date('M j', strtotime($customer['last_order_date']));
                                                } else {
                                                    echo 'Never';
                                                }
                                                ?>
                                            </div>
                                            <small class="text-muted">Last Order</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <span class="type-badge type-<?php echo $customer['customer_type']; ?>">
                                    <?php echo ($customer['customer_type'] === 'walk_in') ? 'Walk-in' : ucfirst($customer['customer_type']); ?>
                                </span>

                                <div class="btn-group" role="group">
                                    <?php if (hasPermission('view_customer_profiles', $permissions)): ?>
                                    <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php endif; ?>

                                    <?php if (hasPermission('edit_customers', $permissions) && $customer['customer_type'] !== 'walk_in'): ?>
                                    <a href="edit.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit Customer">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php elseif ($customer['customer_type'] === 'walk_in'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Walk-in customer cannot be edited">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if (hasPermission('delete_customers', $permissions) && $customer['customer_type'] !== 'walk_in'): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>')" title="Delete Customer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php elseif ($customer['customer_type'] === 'walk_in'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Walk-in customer cannot be deleted">
                                        <i class="bi bi-shield-lock"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <small class="text-muted">
                                Showing <?php echo ($offset + 1) . ' - ' . min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> customers
                            </small>
                        </div>
                        <div class="col-md-6">
                            <nav aria-label="Customer pagination">
                                <ul class="pagination justify-content-end mb-0">
                                    <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&per_page=<?php echo $per_page; ?>">
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>

                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&per_page=<?php echo $per_page; ?>">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&per_page=<?php echo $per_page; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                    <?php endfor; ?>

                                    <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&per_page=<?php echo $per_page; ?>"><?php echo $total_pages; ?></a>
                                    </li>
                                    <?php endif; ?>

                                    <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>&per_page=<?php echo $per_page; ?>">
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteCustomerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete customer <strong id="deleteCustomerName"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. All customer data will be permanently removed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="bi bi-trash me-1"></i>Delete Customer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;

            const params = new URLSearchParams(window.location.search);
            params.set('page', '1'); // Reset to first page when filtering

            if (search) params.set('search', search);
            else params.delete('search');

            if (status) params.set('status', status);
            else params.delete('status');

            if (type) params.set('type', type);
            else params.delete('type');

            window.location.href = '?' + params.toString();
        }

        function deleteCustomer(customerId, customerName) {
            document.getElementById('deleteCustomerName').textContent = customerName;
            document.getElementById('confirmDeleteBtn').onclick = function() {
                window.location.href = 'delete.php?id=' + customerId;
            };

            new bootstrap.Modal(document.getElementById('deleteCustomerModal')).show();
        }

        function exportCustomers() {
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'export.php?' + params.toString();
        }
    </script>
</body>
</html>
