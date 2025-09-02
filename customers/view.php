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

// Check if user has permission to view customer profiles
if (!hasPermission('view_customer_profiles', $permissions)) {
    header("Location: index.php");
    exit();
}

// Get customer ID from URL
$customer_id = intval($_GET['id'] ?? 0);
if (!$customer_id) {
    header("Location: index.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get customer details
$stmt = $conn->prepare("
    SELECT c.*,
           CONCAT(c.first_name, ' ', c.last_name) as full_name,
           u.username as created_by_username
    FROM customers c
    LEFT JOIN users u ON c.created_by = u.id
    WHERE c.id = :customer_id
");
$stmt->bindParam(':customer_id', $customer_id);
$stmt->execute();
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: index.php");
    exit();
}

// Handle purchase history pagination, search, and filters
$purchase_page = max(1, (int)($_GET['purchase_page'] ?? 1));
$purchase_per_page = 20;
$purchase_search = $_GET['purchase_search'] ?? '';
$purchase_payment_filter = $_GET['purchase_payment'] ?? '';
$purchase_date_from = $_GET['purchase_date_from'] ?? '';
$purchase_date_to = $_GET['purchase_date_to'] ?? '';

// Build purchase history query with filters
$purchase_where_conditions = [
    "(s.customer_id = :customer_id OR s.customer_name = :customer_name OR (s.customer_name = 'Walk-in Customer' AND :customer_number = 'WALK-IN-001'))"
];

$purchase_params = [
    ':customer_id' => $customer['id'],
    ':customer_name' => $customer['first_name'] . ' ' . $customer['last_name'],
    ':customer_number' => $customer['customer_number']
];

if (!empty($purchase_search)) {
    $purchase_where_conditions[] = "(s.id LIKE :purchase_search OR u.username LIKE :purchase_search OR s.payment_method LIKE :purchase_search)";
    $purchase_params[':purchase_search'] = '%' . $purchase_search . '%';
}

if (!empty($purchase_payment_filter)) {
    $purchase_where_conditions[] = "s.payment_method = :purchase_payment";
    $purchase_params[':purchase_payment'] = $purchase_payment_filter;
}

if (!empty($purchase_date_from)) {
    $purchase_where_conditions[] = "DATE(s.created_at) >= :purchase_date_from";
    $purchase_params[':purchase_date_from'] = $purchase_date_from;
}

if (!empty($purchase_date_to)) {
    $purchase_where_conditions[] = "DATE(s.created_at) <= :purchase_date_to";
    $purchase_params[':purchase_date_to'] = $purchase_date_to;
}

$purchase_where_clause = implode(' AND ', $purchase_where_conditions);

// Get total count for pagination
$purchase_count_sql = "
    SELECT COUNT(DISTINCT s.id) as total
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE $purchase_where_clause
";

$purchase_count_stmt = $conn->prepare($purchase_count_sql);
foreach ($purchase_params as $key => $value) {
    $purchase_count_stmt->bindValue($key, $value);
}
$purchase_count_stmt->execute();
$purchase_total_records = $purchase_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$purchase_total_pages = ceil($purchase_total_records / $purchase_per_page);

// Get purchase history with pagination
$purchase_offset = ($purchase_page - 1) * $purchase_per_page;
$purchase_sql = "
    SELECT
        s.id,
        s.total_amount,
        s.discount,
        s.final_amount,
        s.payment_method,
        s.created_at as sale_date,
        COUNT(si.id) as items_count,
        u.username as cashier_name
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN users u ON s.user_id = u.id
    WHERE $purchase_where_clause
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT $purchase_offset, $purchase_per_page
";

$purchase_stmt = $conn->prepare($purchase_sql);
foreach ($purchase_params as $key => $value) {
    $purchase_stmt->bindValue($key, $value);
}
$purchase_stmt->execute();
$purchase_history = $purchase_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate customer statistics (get total stats, not just current page)
$stats_sql = "
    SELECT
        COUNT(DISTINCT s.id) as total_purchases,
        SUM(s.final_amount) as total_spent,
        AVG(s.final_amount) as average_order,
        MAX(s.created_at) as last_purchase
    FROM sales s
    WHERE (
        s.customer_id = :customer_id OR 
        s.customer_name = :customer_name OR
        (s.customer_name = 'Walk-in Customer' AND :customer_number = 'WALK-IN-001')
    )
";

$stats_stmt = $conn->prepare($stats_sql);
$stats_customer_id = $customer['id'];
$stats_customer_name = $customer['first_name'] . ' ' . $customer['last_name'];
$stats_customer_number = $customer['customer_number'];

$stats_stmt->bindParam(':customer_id', $stats_customer_id);
$stats_stmt->bindParam(':customer_name', $stats_customer_name);
$stats_stmt->bindParam(':customer_number', $stats_customer_number);
$stats_stmt->execute();
$stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$stats = [
    'total_purchases' => $stats_result['total_purchases'] ?? 0,
    'total_spent' => $stats_result['total_spent'] ?? 0,
    'average_order' => $stats_result['average_order'] ?? 0,
    'last_purchase' => $stats_result['last_purchase']
];

// Get customer activity (if activity_logs table exists and tracks customer activities)
$activity_stmt = $conn->prepare("
    SELECT al.*, u.username as performed_by
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.details LIKE :customer_pattern
    ORDER BY al.created_at DESC
    LIMIT 20
");
$customer_pattern = '%' . $customer['customer_number'] . '%';
$activity_stmt->bindParam(':customer_pattern', $customer_pattern);
$activity_stmt->execute();
$activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($customer['full_name']); ?> - Customer Details - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .customer-profile {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            padding: 2rem;
            position: relative;
        }

        .customer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.2);
            color: #16a34a;
        }

        .status-inactive {
            background: rgba(156, 163, 175, 0.2);
            color: #6b7280;
        }

        .status-suspended {
            background: rgba(245, 101, 101, 0.2);
            color: #dc2626;
        }

        .type-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .type-individual {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
        }

        .type-business {
            background: rgba(245, 101, 101, 0.1);
            color: #dc2626;
        }

        .type-vip {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.1), rgba(245, 158, 11, 0.1));
            color: #d97706;
        }

        .type-wholesale {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
        }

        .info-section {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #f8fafc;
        }

        .section-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .section-title i {
            margin-right: 0.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: #64748b;
        }

        .info-value {
            font-weight: 600;
            color: #1e293b;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stats-label {
            color: #64748b;
            font-weight: 500;
        }

        .activity-item {
            border-left: 4px solid var(--primary-color);
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .activity-time {
            color: #64748b;
            font-size: 0.875rem;
        }

        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
        }

        .walk-in-avatar {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb) !important;
            border: 2px dashed #9ca3af !important;
        }

        .walk-in-avatar i {
            color: #6b7280 !important;
        }

        .walk-in-badge {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3);
            border: 2px solid white;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
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
                            <li class="breadcrumb-item"><a href="index.php">Customers</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($customer['full_name']); ?></li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-person-circle me-2"></i><?php echo htmlspecialchars($customer['full_name']); ?></h1>
                    <p class="header-subtitle">Customer ID: <?php echo htmlspecialchars($customer['customer_number']); ?></p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('edit_customers', $permissions)): ?>
                    <a href="edit.php?id=<?php echo $customer['id']; ?>" class="btn btn-primary me-2">
                        <i class="bi bi-pencil me-1"></i>Edit Customer
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Customers
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <div class="row">
                <!-- Customer Profile -->
                <div class="col-lg-4">
                    <div class="customer-profile">
                        <div class="profile-header" style="position: relative;">
                            <?php if ($customer['customer_type'] === 'walk_in'): ?>
                            <div class="walk-in-badge" title="Default Walk-in Customer" style="position: absolute; top: 10px; right: 10px; z-index: 10;">
                                <i class="bi bi-pin-fill"></i>
                            </div>
                            <?php endif; ?>
                            <div class="customer-avatar <?php echo ($customer['customer_type'] === 'walk_in') ? 'walk-in-avatar' : ''; ?>">
                                <?php if ($customer['customer_type'] === 'walk_in'): ?>
                                    <i class="bi bi-person-walking" style="font-size: 2.2rem; color: #6b7280;"></i>
                                <?php else: ?>
                                    <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <h4>
                                <?php echo htmlspecialchars($customer['full_name']); ?>
                                <?php if ($customer['customer_type'] === 'walk_in'): ?>
                                    <span class="badge bg-secondary ms-2" style="font-size: 0.7rem;">DEFAULT CUSTOMER</span>
                                <?php endif; ?>
                            </h4>
                        <p class="mb-2"><?php echo htmlspecialchars($customer['customer_number']); ?></p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <span class="status-badge status-<?php echo $customer['membership_status']; ?>">
                                <?php echo ucfirst($customer['membership_status']); ?>
                            </span>
                            <span class="type-badge type-<?php echo $customer['customer_type']; ?>">
                                <?php echo ($customer['customer_type'] === 'walk_in') ? 'Walk-in' : ucfirst($customer['customer_type']); ?> Customer
                            </span>
                            <?php if ($customer['customer_type'] === 'walk_in'): ?>
                            <span class="badge bg-info">
                                <i class="bi bi-shield-check me-1"></i>System Default
                            </span>
                            <?php endif; ?>
                        </div>
                        </div>

                        <div class="p-4">
                            <!-- Basic Information -->
                            <div class="info-section">
                                <h5 class="section-title">
                                    <i class="bi bi-info-circle"></i>Basic Information
                                </h5>
                                <div class="info-item">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['full_name']); ?></span>
                                </div>
                                <?php if (!empty($customer['email'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['email']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['phone'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['mobile'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Mobile</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['mobile']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['date_of_birth'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value"><?php echo date('M d, Y', strtotime($customer['date_of_birth'])); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['gender'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Gender</span>
                                    <span class="info-value"><?php echo ucfirst($customer['gender']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Business Information -->
                            <?php if ($customer['customer_type'] === 'business' || !empty($customer['company_name'])): ?>
                            <div class="info-section">
                                <h5 class="section-title">
                                    <i class="bi bi-building"></i>Business Information
                                </h5>
                                <?php if (!empty($customer['company_name'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Company</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['company_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['tax_id'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Tax ID</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['tax_id']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Address Information -->
                            <?php if (!empty($customer['address']) || !empty($customer['city'])): ?>
                            <div class="info-section">
                                <h5 class="section-title">
                                    <i class="bi bi-geo-alt"></i>Address
                                </h5>
                                <?php if (!empty($customer['address'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Street</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['address']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['city'])): ?>
                                <div class="info-item">
                                    <span class="info-label">City</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['city']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['state'])): ?>
                                <div class="info-item">
                                    <span class="info-label">State</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['state']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['zip_code'])): ?>
                                <div class="info-item">
                                    <span class="info-label">ZIP Code</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['zip_code']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($customer['country'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Country</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['country']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Financial Information -->
                            <div class="info-section">
                                <h5 class="section-title">
                                    <i class="bi bi-cash-stack"></i>Financial Information
                                </h5>
                                <div class="info-item">
                                    <span class="info-label">Credit Limit</span>
                                    <span class="info-value">$<?php echo number_format($customer['credit_limit'], 2); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Current Balance</span>
                                    <span class="info-value">$<?php echo number_format($customer['current_balance'], 2); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Loyalty Points</span>
                                    <span class="info-value"><?php echo number_format($customer['loyalty_points']); ?></span>
                                </div>
                                <?php if (!empty($customer['preferred_payment_method'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Preferred Payment</span>
                                    <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $customer['preferred_payment_method'])); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Notes -->
                            <?php if (!empty($customer['notes'])): ?>
                            <div class="info-section">
                                <h5 class="section-title">
                                    <i class="bi bi-sticky"></i>Notes
                                </h5>
                                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($customer['notes'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Customer Details -->
                <div class="col-lg-8">
                    <!-- Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="stats-number"><?php echo $stats['total_purchases']; ?></div>
                                <div class="stats-label">Total Purchases</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="stats-number">$<?php echo number_format($stats['total_spent'], 2); ?></div>
                                <div class="stats-label">Total Spent</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="stats-number">$<?php echo number_format($stats['average_order'], 2); ?></div>
                                <div class="stats-label">Average Order</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="stats-number">
                                    <?php echo $stats['last_purchase'] ? date('M d', strtotime($stats['last_purchase'])) : 'N/A'; ?>
                                </div>
                                <div class="stats-label">Last Purchase</div>
                            </div>
                        </div>
                    </div>

                    <!-- Purchase History -->
                    <div class="customer-profile mb-4">
                        <div class="p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="section-title mb-0">
                                    <i class="bi bi-receipt"></i>Purchase History
                                    <small class="text-muted">(<?php echo $purchase_total_records; ?> total)</small>
                                </h5>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#purchaseFilters" aria-expanded="false">
                                    <i class="bi bi-funnel"></i> Filters
                                </button>
                            </div>

                            <!-- Purchase History Filters -->
                            <div class="collapse mb-3" id="purchaseFilters">
                                <div class="card card-body">
                                    <form method="GET" class="row g-3">
                                        <input type="hidden" name="id" value="<?php echo $customer['id']; ?>">
                                        
                                        <div class="col-md-4">
                                            <label class="form-label">Search</label>
                                            <input type="text" class="form-control" name="purchase_search" value="<?php echo htmlspecialchars($purchase_search); ?>" placeholder="Sale ID, Cashier, Payment...">
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <label class="form-label">Payment Method</label>
                                            <select class="form-select" name="purchase_payment">
                                                <option value="">All Methods</option>
                                                <option value="cash" <?php echo $purchase_payment_filter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                                <option value="card" <?php echo $purchase_payment_filter === 'card' ? 'selected' : ''; ?>>Card</option>
                                                <option value="credit" <?php echo $purchase_payment_filter === 'credit' ? 'selected' : ''; ?>>Credit</option>
                                                <option value="other" <?php echo $purchase_payment_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <label class="form-label">From Date</label>
                                            <input type="date" class="form-control" name="purchase_date_from" value="<?php echo htmlspecialchars($purchase_date_from); ?>">
                                        </div>
                                        
                                        <div class="col-md-2">
                                            <label class="form-label">To Date</label>
                                            <input type="date" class="form-control" name="purchase_date_to" value="<?php echo htmlspecialchars($purchase_date_to); ?>">
                                        </div>
                                        
                                        <div class="col-md-1">
                                            <label class="form-label">&nbsp;</label>
                                            <div class="d-flex gap-1">
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-search"></i>
                                                </button>
                                                <a href="view.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-x"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <?php if (empty($purchase_history)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                                    <p class="text-muted mt-2">
                                        <?php if (!empty($purchase_search) || !empty($purchase_payment_filter) || !empty($purchase_date_from) || !empty($purchase_date_to)): ?>
                                            No purchases found matching your criteria.
                                        <?php else: ?>
                                            No purchase history available.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Sale ID</th>
                                                <th>Items</th>
                                                <th>Total</th>
                                                <th>Payment</th>
                                                <th>Cashier</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($purchase_history as $purchase): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($purchase['sale_date'])); ?></td>
                                                <td>#<?php echo $purchase['id']; ?></td>
                                                <td><?php echo $purchase['items_count']; ?> item(s)</td>
                                                <td>$<?php echo number_format($purchase['final_amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $purchase['payment_method'] === 'cash' ? 'success' : 'primary'; ?>">
                                                        <?php echo ucfirst($purchase['payment_method']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $purchase['cashier_name'] ?? 'Unknown'; ?></td>
                                                <td>
                                                    <a href="../pos/checkout.php?sale_id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Sale">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Purchase History Pagination -->
                                <?php if ($purchase_total_pages > 1): ?>
                                <nav aria-label="Purchase history pagination" class="mt-3">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($purchase_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?id=<?php echo $customer['id']; ?>&purchase_page=<?php echo $purchase_page - 1; ?><?php echo !empty($purchase_search) ? '&purchase_search=' . urlencode($purchase_search) : ''; ?><?php echo !empty($purchase_payment_filter) ? '&purchase_payment=' . urlencode($purchase_payment_filter) : ''; ?><?php echo !empty($purchase_date_from) ? '&purchase_date_from=' . urlencode($purchase_date_from) : ''; ?><?php echo !empty($purchase_date_to) ? '&purchase_date_to=' . urlencode($purchase_date_to) : ''; ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>

                                        <?php
                                        $start_page = max(1, $purchase_page - 2);
                                        $end_page = min($purchase_total_pages, $purchase_page + 2);
                                        
                                        for ($i = $start_page; $i <= $end_page; $i++):
                                        ?>
                                        <li class="page-item <?php echo $i === $purchase_page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?id=<?php echo $customer['id']; ?>&purchase_page=<?php echo $i; ?><?php echo !empty($purchase_search) ? '&purchase_search=' . urlencode($purchase_search) : ''; ?><?php echo !empty($purchase_payment_filter) ? '&purchase_payment=' . urlencode($purchase_payment_filter) : ''; ?><?php echo !empty($purchase_date_from) ? '&purchase_date_from=' . urlencode($purchase_date_from) : ''; ?><?php echo !empty($purchase_date_to) ? '&purchase_date_to=' . urlencode($purchase_date_to) : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>

                                        <?php if ($purchase_page < $purchase_total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?id=<?php echo $customer['id']; ?>&purchase_page=<?php echo $purchase_page + 1; ?><?php echo !empty($purchase_search) ? '&purchase_search=' . urlencode($purchase_search) : ''; ?><?php echo !empty($purchase_payment_filter) ? '&purchase_payment=' . urlencode($purchase_payment_filter) : ''; ?><?php echo !empty($purchase_date_from) ? '&purchase_date_from=' . urlencode($purchase_date_from) : ''; ?><?php echo !empty($purchase_date_to) ? '&purchase_date_to=' . urlencode($purchase_date_to) : ''; ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                    
                                    <div class="text-center text-muted">
                                        <small>
                                            Showing <?php echo (($purchase_page - 1) * $purchase_per_page) + 1; ?>-<?php echo min($purchase_page * $purchase_per_page, $purchase_total_records); ?> 
                                            of <?php echo $purchase_total_records; ?> purchases
                                        </small>
                                    </div>
                                </nav>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activity Log -->
                    <?php if (!empty($activities)): ?>
                    <div class="customer-profile">
                        <div class="p-4">
                            <h5 class="section-title mb-3">
                                <i class="bi bi-activity"></i>Recent Activity
                            </h5>

                            <?php foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <p class="mb-1"><?php echo htmlspecialchars($activity['action']); ?></p>
                                        <small class="activity-time">
                                            <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                            <?php if (!empty($activity['performed_by'])): ?>
                                                by <?php echo htmlspecialchars($activity['performed_by']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
