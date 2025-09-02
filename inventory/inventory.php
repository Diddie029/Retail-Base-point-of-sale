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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check BOM permissions
$can_manage_boms = hasPermission('manage_boms', $permissions);
$can_view_boms = hasPermission('view_boms', $permissions);

// Get BOM statistics if user has BOM permissions
$bom_stats = [];
if ($can_manage_boms || $can_view_boms) {
    $bom_stats = getBOMStatistics($conn);
}

// Get inventory statistics
$stats = [];

// Total Products in Inventory
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity > 0");
$stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Low Stock Products (quantity < 10)
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity < 10 AND quantity > 0");
$stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Out of Stock Products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity = 0");
$stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total Inventory Value
$stmt = $conn->query("SELECT COALESCE(SUM(quantity * cost_price), 0) as total FROM products");
$stats['total_inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Enhanced Order Statistics
// Orders Pending Reception
$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status IN ('sent', 'waiting_for_delivery')");
$stats['orders_pending_reception'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Orders Received Today
$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status = 'received' AND DATE(updated_at) = CURDATE()");
$stats['orders_received_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending Orders
$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status = 'pending'");
$stats['pending_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// All Orders by Status
$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status = 'sent'");
$stats['sent_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status = 'waiting_for_delivery'");
$stats['waiting_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Received Orders/Invoices
$stmt = $conn->query("SELECT COUNT(*) as count FROM inventory_orders WHERE status = 'received'");
$stats['received_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total Invoice Value (sum of all received orders)
$stmt = $conn->query("
    SELECT COALESCE(SUM(ioi.received_quantity * ioi.cost_price), 0) as total
    FROM inventory_order_items ioi
    INNER JOIN inventory_orders io ON ioi.order_id = io.id
    WHERE io.status = 'received'
");
$stats['total_invoice_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Orders Value Today (received today)
$stmt = $conn->query("
    SELECT COALESCE(SUM(ioi.received_quantity * ioi.cost_price), 0) as total
    FROM inventory_order_items ioi
    INNER JOIN inventory_orders io ON ioi.order_id = io.id
    WHERE io.status = 'received' AND DATE(io.updated_at) = CURDATE()
");
$stats['orders_value_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Return Statistics
try {
    $stmt = $conn->query("SELECT COUNT(*) as count FROM returns");
    $stats['total_returns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COUNT(*) as count FROM returns WHERE status = 'pending'");
    $stats['pending_returns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COUNT(*) as count FROM returns WHERE status = 'completed'");
    $stats['completed_returns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM returns WHERE status = 'completed'");
    $stats['total_return_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $conn->query("SELECT COUNT(*) as count FROM returns WHERE status IN ('approved', 'shipped', 'received')");
    $stats['active_returns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    // Return tables might not exist yet, set defaults
    $stats['total_returns'] = 0;
    $stats['pending_returns'] = 0;
    $stats['completed_returns'] = 0;
    $stats['total_return_value'] = 0;
    $stats['active_returns'] = 0;
}

// Recent Inventory Activities (placeholder for now)
$recent_activities = [];

// Low Stock Alert Products
$low_stock_products = [];
if (hasPermission('manage_inventory', $permissions)) {
    $stmt = $conn->prepare("
        SELECT id, name, quantity, minimum_stock, cost_price
        FROM products
        WHERE quantity <= minimum_stock AND quantity > 0
        ORDER BY quantity ASC
        LIMIT 5
    ");
    $stmt->execute();
    $low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top Suppliers section removed as requested
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-right: 1rem;
        }

        .stat-primary { background: #dbeafe; color: #2563eb; }
        .stat-warning { background: #fef3c7; color: #d97706; }
        .stat-danger { background: #fee2e2; color: #dc2626; }
        .stat-success { background: #d1fae5; color: #059669; }
        .stat-info { background: #dbeafe; color: #2563eb; }
        .stat-secondary { background: #f3f4f6; color: #374151; }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .stat-value.currency {
            font-size: 1.5rem;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .stat-change {
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-change.positive { color: #059669; }
        .stat-change.negative { color: #dc2626; }

        /* Clickable stat card styling */
        .stat-card[onclick] {
            transition: all 0.3s ease;
        }

        .stat-card[onclick]:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-decoration: none;
            color: #1e293b;
            transition: all 0.3s ease;
            font-weight: 600;
            text-align: center;
        }

        .action-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
        }

        .action-btn i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .action-btn {
                padding: 1rem;
            }

            .action-btn i {
                font-size: 1.5rem;
                margin-bottom: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Inventory Management</h1>
                    <p class="header-subtitle">Monitor and manage your inventory efficiently</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-<?php echo $_GET['error'] === 'order_not_found' ? 'warning' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $_GET['error'] === 'order_not_found' ? 'exclamation-triangle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php
                switch ($_GET['error']) {
                    case 'order_not_found':
                        echo "The order you're looking for could not be found. ";
                        echo "<strong>Possible causes:</strong><br>";
                        echo "• The order may have been deleted<br>";
                        echo "• You may not have permission to view this order<br>";
                        echo "• The order ID or number may be incorrect<br>";
                        echo "• <strong>If the order is RECEIVED</strong>: Try accessing it through the invoice view<br>";
                        echo "<br><a href='view_orders.php' class='btn btn-sm btn-primary me-2'>View All Orders</a>";
                        echo "<a href='view_invoices.php' class='btn btn-sm btn-success me-2'>View Invoices</a>";
                        echo "<a href='create_order.php' class='btn btn-sm btn-outline-primary me-2'>Create New Order</a>";
                        echo "<a href='debug_orders.php' class='btn btn-sm btn-outline-info'>Debug Orders</a>";
                        echo "<br><br><small class='text-muted'>If you know the order number, try searching for it directly in the orders list or invoice list.</small>";
                        break;
                    case 'invalid_order':
                        echo "Invalid order ID provided.";
                        break;
                    case 'permission_denied':
                        echo "You don't have permission to access this resource.";
                        break;
                    case 'db_error':
                        echo "Database error occurred. Please try again later.";
                        break;
                    default:
                        echo "An error occurred: " . htmlspecialchars($_GET['error']);
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <h3 class="section-title mb-3">Quick Actions</h3>
                    
                    <!-- Quick Actions Row 1: Product & Order Management -->
                    <div class="quick-actions mb-3">
                        <?php if (hasPermission('manage_inventory', $permissions)): ?>
                        <a href="../products/add.php" class="action-btn">
                            <i class="bi bi-box-seam"></i>
                            Add Product
                        </a>
                        <?php endif; ?>
                        <a href="place_order.php" class="action-btn">
                            <i class="bi bi-plus-circle"></i>
                            Place Order
                        </a>
                        <a href="view_orders.php?filter=receivable" class="action-btn">
                            <i class="bi bi-box-arrow-in-down"></i>
                            Receive Order
                        </a>
                        <a href="view_invoices.php" class="action-btn">
                            <i class="bi bi-receipt"></i>
                            View Invoices
                        </a>
                    </div>

                    <!-- Quick Actions Row 2: Monitoring & Returns -->
                    <div class="quick-actions">
                        <a href="view_orders.php" class="action-btn">
                            <i class="bi bi-list-check"></i>
                            View Orders
                        </a>
                        <a href="create_return.php" class="action-btn">
                            <i class="bi bi-arrow-return-left"></i>
                            Create Return
                        </a>
                        <div class="action-btn" onclick="window.location.href='returns_list.php'" style="cursor: pointer;">
                            <i class="bi bi-arrow-return-left"></i>
                            Returns Management
                        </div>
                        <a href="../shelf_label/shelf_labels.php" class="action-btn">
                            <i class="bi bi-tags"></i>
                            Shelf Labels
                        </a>
                    </div>

                    <!-- Quick Actions Row 3: BOM Management -->
                    <?php if ($can_manage_boms || $can_view_boms): ?>
                    <div class="quick-actions">
                        <?php if ($can_manage_boms): ?>
                        <a href="../bom/add.php" class="action-btn">
                            <i class="bi bi-file-earmark-plus"></i>
                            Create BOM
                        </a>
                        <?php endif; ?>
                        <a href="../bom/index.php" class="action-btn">
                            <i class="bi bi-list-ul"></i>
                            View BOMs
                        </a>
                        <?php if ($can_manage_boms): ?>
                        <a href="../bom/production.php" class="action-btn">
                            <i class="bi bi-gear"></i>
                            Production Orders
                        </a>
                        <?php endif; ?>
                        <a href="../bom/reports.php" class="action-btn">
                            <i class="bi bi-graph-up"></i>
                            BOM Reports
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-12">
                    <h3 class="section-title mb-3">Inventory Statistics</h3>
                    <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
                    <div class="stat-label">Total Products</div>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> In stock
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['low_stock']); ?></div>
                    <div class="stat-label">Low Stock Items</div>
                    <?php if ($stats['low_stock'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-arrow-down"></i> Requires attention
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> All good
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['out_of_stock']); ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <?php if ($stats['out_of_stock'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-exclamation-triangle"></i> Needs restocking
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> Fully stocked
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_inventory_value'], 2); ?></div>
                    <div class="stat-label">Inventory Value</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Current value
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['received_orders']); ?></div>
                    <div class="stat-label">Invoices</div>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> Received orders
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_invoice_value'], 2); ?></div>
                    <div class="stat-label">Invoice Value</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Total received
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-secondary">
                            <i class="bi bi-arrow-return-left"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_returns']); ?></div>
                    <div class="stat-label">Total Returns</div>
                    <div class="stat-change <?php echo $stats['pending_returns'] > 0 ? 'negative' : 'positive'; ?>">
                        <i class="bi bi-<?php echo $stats['pending_returns'] > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <?php echo $stats['pending_returns']; ?> pending
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['active_returns']); ?></div>
                    <div class="stat-label">Active Returns</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-right"></i> In progress
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['completed_returns']); ?></div>
                    <div class="stat-label">Completed Returns</div>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> Processed
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-cash-coin"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['total_return_value'], 2); ?></div>
                    <div class="stat-label">Return Value</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-down"></i> Returned value
                    </div>
                </div>

                <!-- Enhanced Order Statistics -->
                <div class="stat-card" onclick="window.location.href='view_orders.php?status=pending'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['orders_pending_reception']); ?></div>
                    <div class="stat-label">Orders Pending Reception</div>
                    <?php if ($stats['orders_pending_reception'] > 0): ?>
                    <div class="stat-change negative">
                        <i class="bi bi-clock"></i> Awaiting reception
                    </div>
                    <?php else: ?>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> All caught up
                    </div>
                    <?php endif; ?>
                </div>

                <div class="stat-card" onclick="window.location.href='view_orders.php?date=today'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['orders_received_today']); ?></div>
                    <div class="stat-label">Orders Received Today</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Today's activity
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='view_invoices.php?date=today'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($stats['orders_value_today'], 2); ?></div>
                    <div class="stat-label">Orders Value Today</div>
                    <div class="stat-change positive">
                        <i class="bi bi-graph-up"></i> Today's total
                    </div>
                </div>

                <!-- BOM Statistics -->
                <?php if (($can_manage_boms || $can_view_boms) && !empty($bom_stats)): ?>
                <div class="stat-card" onclick="window.location.href='../bom/index.php'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['total_active_boms'] ?? 0); ?></div>
                    <div class="stat-label">Active BOMs</div>
                    <div class="stat-change positive">
                        <i class="bi bi-arrow-up"></i> Manufacturing specs
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='../bom/index.php?status=draft'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['draft_boms'] ?? 0); ?></div>
                    <div class="stat-label">Draft BOMs</div>
                    <div class="stat-change <?php echo ($bom_stats['draft_boms'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                        <i class="bi bi-<?php echo ($bom_stats['draft_boms'] ?? 0) > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <?php echo ($bom_stats['draft_boms'] ?? 0) > 0 ? 'Needs approval' : 'All approved'; ?>
                    </div>
                </div>

                <div class="stat-card" onclick="window.location.href='../bom/index.php?production=active'" style="cursor: pointer;">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-gear"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['active_production_orders'] ?? 0); ?></div>
                    <div class="stat-label">Active Production</div>
                    <div class="stat-change positive">
                        <i class="bi bi-play-circle"></i> In progress
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-trophy"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($bom_stats['completed_this_month'] ?? 0); ?></div>
                    <div class="stat-label">Completed This Month</div>
                    <div class="stat-change positive">
                        <i class="bi bi-check-circle"></i> This month
                    </div>
                </div>
                <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- Bulk Operations Section -->
            <?php if (hasPermission('manage_products', $permissions) || hasPermission('manage_inventory', $permissions)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Bulk Operations</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <a href="../products/products.php" class="btn btn-outline-primary d-block">
                                            <i class="bi bi-boxes me-2"></i>
                                            <strong>Bulk Product Management</strong><br>
                                            <small>Update multiple products at once</small>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <a href="bulk_status_update.php" class="btn btn-outline-warning d-block">
                                            <i class="bi bi-toggle-on me-2"></i>
                                            <strong>Bulk Status Update</strong><br>
                                            <small>Change status for multiple items</small>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <a href="../bom/auto_bom_index.php" class="btn btn-outline-success d-block">
                                            <i class="fas fa-cogs me-2"></i>
                                            <strong>Bulk Auto BOM Setup</strong><br>
                                            <small>Configure Auto BOM for products</small>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <a href="../products/bulk_operations.php" class="btn btn-outline-info d-block">
                                            <i class="bi bi-gear me-2"></i>
                                            <strong>Advanced Bulk Ops</strong><br>
                                            <small>Import, export, and advanced operations</small>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Low Stock Alert -->
            <?php if (!empty($low_stock_products)): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Low Stock Alert</h3>
                    <a href="../products/products.php?filter=low_stock" class="btn btn-outline-warning btn-sm">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Current Stock</th>
                                <th>Min. Stock</th>
                                <th>Cost Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><span class="badge bg-warning"><?php echo $product['quantity']; ?></span></td>
                                <td><?php echo $product['minimum_stock']; ?></td>
                                <td class="currency"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($product['cost_price'], 2); ?></td>
                                <td><span class="badge bg-danger">Low Stock</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>



            <!-- Out of Stock Items -->
            <?php if ($stats['out_of_stock'] > 0): ?>
            <div class="data-section">
                <div class="section-header">
                    <h3 class="section-title">Out of Stock Items</h3>
                    <a href="../products/products.php?filter=out_of_stock" class="btn btn-outline-danger btn-sm">View All</a>
                </div>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $stats['out_of_stock']; ?> products are currently out of stock and need immediate attention.
                </div>
            </div>
            <?php endif; ?>




        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>
