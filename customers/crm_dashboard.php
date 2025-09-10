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

// Get customer statistics
$stats = [
    'total_customers' => 0,
    'active_customers' => 0,
    'vip_customers' => 0,
    'business_customers' => 0,
    'walk_in_customers' => 0,
    'total_orders' => 0,
    'total_revenue' => 0,
    'membership_levels' => 0
];

// Customer counts
$customer_stats_stmt = $conn->query("
    SELECT
        COUNT(*) as total_customers,
        SUM(CASE WHEN membership_status = 'active' THEN 1 ELSE 0 END) as active_customers,
        SUM(CASE WHEN customer_type = 'vip' THEN 1 ELSE 0 END) as vip_customers,
        SUM(CASE WHEN customer_type = 'business' THEN 1 ELSE 0 END) as business_customers,
        SUM(CASE WHEN customer_type = 'walk_in' THEN 1 ELSE 0 END) as walk_in_customers
    FROM customers
");
$customer_stats_result = $customer_stats_stmt->fetch(PDO::FETCH_ASSOC);
if ($customer_stats_result) {
    $stats = array_merge($stats, $customer_stats_result);
}

// Order statistics
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


// Membership levels count - Check if table exists first
$membership_levels = 0;
try {
    $membership_stats_stmt = $conn->query("
        SELECT COUNT(*) as membership_levels FROM membership_levels WHERE is_active = 1
    ");
    $membership_stats_result = $membership_stats_stmt->fetch(PDO::FETCH_ASSOC);
    if ($membership_stats_result) {
        $membership_levels = $membership_stats_result['membership_levels'];
    }
} catch (PDOException $e) {
    // Table doesn't exist, set to 0
    $membership_levels = 0;
}
$stats['membership_levels'] = $membership_levels;

// Get recent customers
$recent_customers_stmt = $conn->query("
    SELECT c.*, 
           COALESCE(sales_count.total_orders, 0) as total_orders,
           COALESCE(sales_count.total_spent, 0) as total_spent
    FROM customers c 
    LEFT JOIN (
        SELECT 
            customer_id,
            customer_name,
            COUNT(*) as total_orders,
            SUM(final_amount) as total_spent
        FROM sales 
        WHERE customer_id IS NOT NULL OR customer_name IS NOT NULL
        GROUP BY customer_id, customer_name
    ) sales_count ON (
        c.id = sales_count.customer_id OR 
        (c.first_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', 1) AND 
         c.last_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', -1))
    )
    WHERE c.customer_type != 'walk_in'
    ORDER BY c.created_at DESC 
    LIMIT 5
");
$recent_customers = $recent_customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top customers by spending
$top_customers_stmt = $conn->query("
    SELECT c.*, 
           COALESCE(sales_count.total_orders, 0) as total_orders,
           COALESCE(sales_count.total_spent, 0) as total_spent
    FROM customers c 
    LEFT JOIN (
        SELECT 
            customer_id,
            customer_name,
            COUNT(*) as total_orders,
            SUM(final_amount) as total_spent
        FROM sales 
        WHERE customer_id IS NOT NULL OR customer_name IS NOT NULL
        GROUP BY customer_id, customer_name
    ) sales_count ON (
        c.id = sales_count.customer_id OR 
        (c.first_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', 1) AND 
         c.last_name = SUBSTRING_INDEX(sales_count.customer_name, ' ', -1))
    )
    WHERE c.customer_type != 'walk_in' AND sales_count.total_spent > 0
    ORDER BY sales_count.total_spent DESC 
    LIMIT 5
");
$top_customers = $top_customers_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer CRM Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .crm-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .crm-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            transform: translateY(-4px);
        }

        .crm-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
        }

        .crm-card-header {
            padding: 1.5rem 1.5rem 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .crm-card-body {
            padding: 1.5rem;
        }

        .crm-card-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }

        .crm-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .crm-card-description {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .crm-card-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }

        .crm-stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .crm-stat-label {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
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
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }

        .stat-primary { background: linear-gradient(135deg, var(--primary-color), #8b5cf6); }
        .stat-success { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-info { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .stat-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: #64748b;
            font-weight: 500;
        }

        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .customer-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .customer-item:last-child {
            border-bottom: none;
        }

        .customer-info {
            flex: 1;
            margin-left: 0.75rem;
        }

        .customer-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .customer-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .customer-spent {
            font-weight: 600;
            color: #059669;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .gradient-bg {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
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
                            <li class="breadcrumb-item active">Customer CRM</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-people me-2"></i>Customer CRM Dashboard</h1>
                    <p class="header-subtitle">Manage customer relationships and customer data</p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('create_customers', $permissions)): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Add Customer
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-list me-1"></i>View All Customers
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['active_customers']); ?></div>
                    <div class="stat-label">Active Customers</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-star"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['vip_customers']); ?></div>
                    <div class="stat-label">VIP Customers</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="stat-value">$<?php echo number_format($stats['total_revenue'], 0); ?></div>
                    <div class="stat-label">Total Revenue</div>
                </div>
            </div>

            <!-- CRM Management Cards -->
            <div class="row mb-4">
                <div class="col-12">
                    <h2 class="section-title">
                        <i class="bi bi-grid-3x3-gap"></i>
                        Customer Management
                    </h2>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <!-- Customer Management -->
                <div class="col-lg-4 col-md-6">
                    <div class="crm-card" onclick="window.location.href='index.php'">
                        <div class="crm-card-header">
                            <div class="crm-card-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                                <i class="bi bi-people"></i>
                            </div>
                            <h3 class="crm-card-title">Customer Management</h3>
                            <p class="crm-card-description">View, edit, and manage all customer records and information</p>
                        </div>
                        <div class="crm-card-body">
                            <div class="crm-card-stats">
                                <div>
                                    <div class="crm-stat-number"><?php echo number_format($stats['total_customers']); ?></div>
                                    <div class="crm-stat-label">Total Customers</div>
                                </div>
                                <i class="bi bi-arrow-right text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Customer -->
                <div class="col-lg-4 col-md-6">
                    <div class="crm-card" onclick="window.location.href='add.php'">
                        <div class="crm-card-header">
                            <div class="crm-card-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                                <i class="bi bi-person-plus"></i>
                            </div>
                            <h3 class="crm-card-title">Add New Customer</h3>
                            <p class="crm-card-description">Register new customers and set up their profiles</p>
                        </div>
                        <div class="crm-card-body">
                            <div class="crm-card-stats">
                                <div>
                                    <div class="crm-stat-number">+</div>
                                    <div class="crm-stat-label">New Customer</div>
                                </div>
                                <i class="bi bi-arrow-right text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export Customers -->
                <div class="col-lg-4 col-md-6">
                    <div class="crm-card" onclick="window.location.href='export.php'">
                        <div class="crm-card-header">
                            <div class="crm-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                <i class="bi bi-download"></i>
                            </div>
                            <h3 class="crm-card-title">Export Data</h3>
                            <p class="crm-card-description">Export customer data to CSV for analysis and backup</p>
                        </div>
                        <div class="crm-card-body">
                            <div class="crm-card-stats">
                                <div>
                                    <div class="crm-stat-number">CSV</div>
                                    <div class="crm-stat-label">Export Format</div>
                                </div>
                                <i class="bi bi-arrow-right text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loyalty Management Cards -->
            <div class="row mb-4">
                <div class="col-12">
                    <h2 class="section-title">
                        <i class="bi bi-gift"></i>
                        Loyalty & Membership Management
                    </h2>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <!-- Membership Levels -->
                <div class="col-lg-4 col-md-6">
                    <div class="crm-card" onclick="window.location.href='../admin/membership_levels.php'">
                        <div class="crm-card-header">
                            <div class="crm-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                <i class="bi bi-star"></i>
                            </div>
                            <h3 class="crm-card-title">Membership Levels</h3>
                            <p class="crm-card-description">Manage customer membership tiers and benefits</p>
                        </div>
                        <div class="crm-card-body">
                            <div class="crm-card-stats">
                                <div>
                                    <div class="crm-stat-number"><?php echo number_format($stats['membership_levels']); ?></div>
                                    <div class="crm-stat-label">Active Levels</div>
                                </div>
                                <i class="bi bi-arrow-right text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Customer Insights -->
            <div class="row">
                <!-- Recent Customers -->
                <div class="col-lg-6 mb-4">
                    <div class="crm-card">
                        <div class="crm-card-header">
                            <h3 class="crm-card-title">
                                <i class="bi bi-clock-history me-2"></i>
                                Recent Customers
                            </h3>
                            <p class="crm-card-description">Latest customer registrations</p>
                        </div>
                        <div class="crm-card-body">
                            <?php if (empty($recent_customers)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-people"></i>
                                    <p>No recent customers</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_customers as $customer): ?>
                                    <div class="customer-item">
                                        <div class="customer-avatar">
                                            <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                        </div>
                                        <div class="customer-info">
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                            </div>
                                            <div class="customer-details">
                                                <?php echo htmlspecialchars($customer['email'] ?? 'No email'); ?> • 
                                                <?php echo ucfirst($customer['customer_type']); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="customer-spent">$<?php echo number_format($customer['total_spent'], 0); ?></div>
                                            <small class="text-muted"><?php echo $customer['total_orders']; ?> orders</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Customers -->
                <div class="col-lg-6 mb-4">
                    <div class="crm-card">
                        <div class="crm-card-header">
                            <h3 class="crm-card-title">
                                <i class="bi bi-trophy me-2"></i>
                                Top Customers
                            </h3>
                            <p class="crm-card-description">Highest spending customers</p>
                        </div>
                        <div class="crm-card-body">
                            <?php if (empty($top_customers)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-trophy"></i>
                                    <p>No customer data available</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($top_customers as $index => $customer): ?>
                                    <div class="customer-item">
                                        <div class="customer-avatar" style="background: linear-gradient(135deg, <?php echo $index < 3 ? '#f59e0b' : '#6b7280'; ?>, <?php echo $index < 3 ? '#d97706' : '#4b5563'; ?>);">
                                            <?php echo $index + 1; ?>
                                        </div>
                                        <div class="customer-info">
                                            <div class="customer-name">
                                                <?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>
                                            </div>
                                            <div class="customer-details">
                                                <?php echo htmlspecialchars($customer['email'] ?? 'No email'); ?> • 
                                                <?php echo ucfirst($customer['customer_type']); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="customer-spent">$<?php echo number_format($customer['total_spent'], 0); ?></div>
                                            <small class="text-muted"><?php echo $customer['total_orders']; ?> orders</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add click animation to cards
        document.querySelectorAll('.crm-card').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'scale(0.98)';
                setTimeout(() => {
                    this.style.transform = '';
                }, 150);
            });
        });

        // Add hover effects
        document.querySelectorAll('.crm-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>
