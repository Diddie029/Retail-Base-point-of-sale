<?php
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../include/db.php';
require_once '../include/functions.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'Cashier';

// Get user permissions
$permissions = [];
if (isset($_SESSION['role_id']) && $_SESSION['role_id']) {
    $stmt = $conn->prepare("
        SELECT p.name
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        WHERE rp.role_id = :role_id
    ");
    $stmt->execute([':role_id' => $_SESSION['role_id']]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get system settings for navigation
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Default settings if table doesn't exist
    $settings = [
        'company_name' => 'POS System',
        'theme_color' => '#6366f1',
        'sidebar_color' => '#1e293b'
    ];
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$payment_method = $_GET['payment_method'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Build query conditions
$conditions = [];
$params = [];

if ($start_date) {
    $conditions[] = "DATE(s.created_at) >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $conditions[] = "DATE(s.created_at) <= ?";
    $params[] = $end_date;
}
if ($payment_method && $payment_method !== 'all') {
    $conditions[] = "s.payment_method = ?";
    $params[] = $payment_method;
}
if ($search) {
    $conditions[] = "(s.customer_name LIKE ? OR s.customer_phone LIKE ? OR s.id LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM sales s $where_clause";
$stmt = $conn->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get sales data with pagination
$sales_query = "
    SELECT 
        s.*,
        u.username as cashier_name,
        COUNT(si.id) as item_count,
        GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as products
    FROM sales s 
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN products p ON si.product_id = p.id
    $where_clause
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $conn->prepare($sales_query);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_sales,
        COALESCE(SUM(final_amount), 0) as total_revenue,
        COALESCE(AVG(final_amount), 0) as avg_sale,
        COUNT(DISTINCT user_id) as active_cashiers
    FROM sales s 
    $where_clause
";
$stmt = $conn->prepare($stats_query);
$stmt->execute($params);
$stats = $stmt->fetch();

// Get payment method breakdown
$payment_stats_query = "
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(final_amount) as total
    FROM sales s 
    $where_clause
    GROUP BY payment_method
    ORDER BY count DESC
";
$stmt = $conn->prepare($payment_stats_query);
$stmt->execute($params);
$payment_stats = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Management - POS System</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Custom CSS -->
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    
    <style>
        .sales-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .table th {
            background: #f8fafc;
            border: none;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
        }
        
        .table td {
            border: none;
            border-bottom: 1px solid #e5e7eb;
            padding: 1rem;
        }
        
        .badge {
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .export-btn {
            background: #059669;
            border: none;
            border-radius: 8px;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .export-btn:hover {
            background: #047857;
            transform: translateY(-1px);
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .payment-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 0.5rem;
        }
        
        .view-details-btn {
            border: none;
            background: #f3f4f6;
            color: #374151;
            padding: 0.5rem;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .view-details-btn:hover {
            background: #e5e7eb;
            color: #111827;
        }
        
        .quick-stats {
            margin-bottom: 2rem;
        }
        
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        
        /* Main content area with proper sidebar spacing */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            padding: 0;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .filters-card .row {
                gap: 1rem;
            }
            
            .table-responsive {
                border-radius: 12px;
                overflow: hidden;
            }
        }
        
        /* Loyalty Points Styling */
        .payment-icon.bg-info {
            background-color: #06b6d4 !important;
        }
        
        .payment-icon.bg-info i {
            color: white;
        }
        
        .loyalty-points-display {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .loyalty-points-display i {
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Sales Management</h1>
                    <div class="header-subtitle">View and manage all sales transactions</div>
                </div>
                <div class="header-actions">
                    <button class="btn export-btn" onclick="exportSales()">
                        <i class="bi bi-download"></i>
                        Export CSV
                    </button>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?= strtoupper(substr($username, 0, 2)) ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($username) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($role) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: #dbeafe; color: #1d4ed8;">
                                <i class="bi bi-receipt"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?= number_format($stats['total_sales']) ?></div>
                        <div class="stat-label">Total Sales</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: #d1fae5; color: #059669;">
                                <i class="bi bi-currency-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-value">KES <?= number_format($stats['total_revenue'], 2) ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                                <i class="bi bi-graph-up"></i>
                            </div>
                        </div>
                        <div class="stat-value">KES <?= number_format($stats['avg_sale'], 2) ?></div>
                        <div class="stat-label">Average Sale</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: #ede9fe; color: #7c3aed;">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <div class="stat-value"><?= $stats['active_cashiers'] ?></div>
                        <div class="stat-label">Active Cashiers</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Method</label>
                        <select class="form-select" name="payment_method">
                            <option value="all" <?= $payment_method === 'all' ? 'selected' : '' ?>>All Methods</option>
                            <option value="cash" <?= $payment_method === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="card" <?= $payment_method === 'card' ? 'selected' : '' ?>>Card</option>
                            <option value="mobile" <?= $payment_method === 'mobile' ? 'selected' : '' ?>>Mobile Money</option>
                            <option value="bank_transfer" <?= $payment_method === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" placeholder="Customer, phone, or sale ID" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i>
                            Apply Filters
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary ms-2">
                            <i class="bi bi-arrow-clockwise"></i>
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Payment Method Breakdown -->
            <?php if (!empty($payment_stats)): ?>
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-pie-chart"></i> Payment Methods Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="paymentChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-list"></i> Payment Summary</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($payment_stats as $stat): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <?php if ($stat['payment_method'] === 'loyalty_points'): ?>
                                        <div class="loyalty-points-display">
                                            <i class="bi bi-star-fill"></i>
                                            Loyalty Points
                                        </div>
                                    <?php else: ?>
                                        <span class="payment-icon bg-<?= $stat['payment_method'] === 'cash' ? 'success' : ($stat['payment_method'] === 'card' ? 'primary' : 'warning') ?>">
                                            <i class="bi bi-<?= $stat['payment_method'] === 'cash' ? 'cash' : ($stat['payment_method'] === 'card' ? 'credit-card' : 'phone') ?>"></i>
                                        </span>
                                        <?= ucfirst(str_replace('_', ' ', $stat['payment_method'] ?? 'unknown')) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold">KES <?= number_format($stat['total'], 2) ?></div>
                                    <small class="text-muted"><?= $stat['count'] ?> sales</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sales Table -->
            <div class="sales-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Sale ID</th>
                                <th>Date & Time</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Payment</th>
                                <th>Total</th>
                                <th>Cashier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-receipt text-muted" style="font-size: 3rem;"></i>
                                    <div class="mt-3">
                                        <h5>No Sales Found</h5>
                                        <p class="text-muted">Try adjusting your filters or check a different date range.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td>
                                    <strong>#<?= $sale['id'] ?></strong>
                                </td>
                                <td>
                                    <div><?= date('M j, Y', strtotime($sale['created_at'])) ?></div>
                                    <small class="text-muted"><?= date('g:i A', strtotime($sale['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($sale['customer_name'] ?: 'Walking Customer') ?></div>
                                    <?php if ($sale['customer_phone']): ?>
                                    <small class="text-muted"><?= htmlspecialchars($sale['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= $sale['item_count'] ?> items</div>
                                    <?php if ($sale['products']): ?>
                                    <small class="text-muted" title="<?= htmlspecialchars($sale['products']) ?>">
                                        <?= htmlspecialchars(strlen($sale['products']) > 30 ? substr($sale['products'], 0, 30) . '...' : $sale['products']) ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $sale['payment_method'] === 'cash' ? 'success' : ($sale['payment_method'] === 'card' ? 'primary' : 'warning') ?>">
                                        <i class="bi bi-<?= $sale['payment_method'] === 'cash' ? 'cash' : ($sale['payment_method'] === 'card' ? 'credit-card' : 'phone') ?>"></i>
                                        <?= ucfirst(str_replace('_', ' ', $sale['payment_method'] ?? 'loyalty points')) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong>KES <?= number_format($sale['final_amount'], 2) ?></strong>
                                    <?php if ($sale['discount'] > 0): ?>
                                    <small class="text-muted d-block">Discount: KES <?= number_format($sale['discount'], 2) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($sale['cashier_name'] ?: 'Unknown') ?>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm view-details-btn" 
                                                onclick="viewSaleDetails(<?= $sale['id'] ?>)"
                                                title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-sm view-details-btn" 
                                                onclick="printReceipt(<?= $sale['id'] ?>)"
                                                title="Print Receipt">
                                            <i class="bi bi-printer"></i>
                                        </button>
                                        <?php if ($role === 'Admin'): ?>
                                        <button class="btn btn-sm view-details-btn text-danger" 
                                                onclick="refundSale(<?= $sale['id'] ?>)"
                                                title="Refund Sale">
                                            <i class="bi bi-arrow-return-left"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-4">
                <div class="text-muted">
                    Showing <?= number_format(($page - 1) * $per_page + 1) ?> to <?= number_format(min($page * $per_page, $total_records)) ?> 
                    of <?= number_format($total_records) ?> results
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sale Details Modal -->
    <div class="modal fade" id="saleDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sale Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="saleDetailsContent">
                    <div class="loading-spinner">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printCurrentSale()">
                        <i class="bi bi-printer"></i> Print Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let currentSaleId = null;
        
        // Initialize payment method chart
        <?php if (!empty($payment_stats)): ?>
        const ctx = document.getElementById('paymentChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [<?= implode(',', array_map(function($stat) { 
                    $method = $stat['payment_method'] ?? 'unknown';
                    if ($method === 'loyalty_points') {
                        return '"Loyalty Points"';
                    }
                    return '"' . ucfirst(str_replace('_', ' ', $method)) . '"';
                }, $payment_stats)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($payment_stats, 'total')) ?>],
                    backgroundColor: [
                        '#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // View sale details
        async function viewSaleDetails(saleId) {
            currentSaleId = saleId;
            const modal = new bootstrap.Modal(document.getElementById('saleDetailsModal'));
            const content = document.getElementById('saleDetailsContent');
            
            content.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            try {
                const response = await fetch(`get_sale_details.php?id=${saleId}`);
                const data = await response.json();
                
                if (data.success) {
                    content.innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Sale Information</h6>
                                <table class="table table-borderless table-sm">
                                    <tr><th>Sale ID:</th><td>#${data.sale.id}</td></tr>
                                    <tr><th>Date:</th><td>${new Date(data.sale.created_at).toLocaleString()}</td></tr>
                                    <tr><th>Customer:</th><td>${data.sale.customer_name || 'Walking Customer'}</td></tr>
                                    <tr><th>Phone:</th><td>${data.sale.customer_phone || 'N/A'}</td></tr>
                                    <tr><th>Cashier:</th><td>${data.sale.cashier_name}</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Payment Information</h6>
                                <table class="table table-borderless table-sm">
                                    <tr><th>Subtotal:</th><td>KES ${parseFloat(data.sale.total_amount).toFixed(2)}</td></tr>
                                    <tr><th>Tax:</th><td>KES ${parseFloat(data.sale.tax_amount || 0).toFixed(2)}</td></tr>
                                    <tr><th>Discount:</th><td>KES ${parseFloat(data.sale.discount || 0).toFixed(2)}</td></tr>
                                    <tr><th class="fw-bold">Final Amount:</th><td class="fw-bold">KES ${parseFloat(data.sale.final_amount).toFixed(2)}</td></tr>
                                    <tr><th>Payment Method:</th><td><span class="badge bg-primary">${data.sale.payment_method.replace('_', ' ')}</span></td></tr>
                                </table>
                            </div>
                        </div>
                        
                        <h6 class="mt-4">Items Purchased</h6>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Unit Price</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.items.map(item => `
                                        <tr>
                                            <td>${item.product_name}</td>
                                            <td>${item.quantity}</td>
                                            <td>KES ${parseFloat(item.unit_price || item.price).toFixed(2)}</td>
                                            <td>KES ${parseFloat(item.total_price || (item.price * item.quantity)).toFixed(2)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        ${data.sale.notes ? `
                            <h6 class="mt-4">Notes</h6>
                            <div class="alert alert-info">
                                ${data.sale.notes}
                            </div>
                        ` : ''}
                    `;
                } else {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            Error loading sale details: ${data.error}
                        </div>
                    `;
                }
            } catch (error) {
                content.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        Error loading sale details. Please try again.
                    </div>
                `;
            }
        }
        
        // Print receipt
        function printReceipt(saleId) {
            window.open(`../pos/print_receipt.php?sale_id=${saleId}`, '_blank');
        }
        
        // Print current sale from modal
        function printCurrentSale() {
            if (currentSaleId) {
                printReceipt(currentSaleId);
            }
        }
        
        // Export sales
        function exportSales() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = `export_sales.php?${params.toString()}`;
        }
        
        // Refund sale (Admin only)
        function refundSale(saleId) {
            if (confirm('Are you sure you want to refund this sale? This action cannot be undone.')) {
                // Implementation for refund functionality
                alert('Refund functionality will be implemented here.');
            }
        }
        
        // Auto-refresh every 5 minutes
        setTimeout(() => {
            window.location.reload();
        }, 300000);
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
