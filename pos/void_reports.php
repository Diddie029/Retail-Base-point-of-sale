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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$void_type = $_GET['void_type'] ?? 'all';
$user_filter = $_GET['user_filter'] ?? 'all';

// Build query
$where_conditions = ["DATE(vt.voided_at) BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if ($void_type !== 'all') {
    $where_conditions[] = "vt.void_type = ?";
    $params[] = $void_type;
}

if ($user_filter !== 'all') {
    $where_conditions[] = "vt.user_id = ?";
    $params[] = $user_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get void transactions
$void_query = "
    SELECT vt.*, u.username, rt.till_name
    FROM void_transactions vt
    LEFT JOIN users u ON vt.user_id = u.id
    LEFT JOIN register_tills rt ON vt.till_id = rt.id
    WHERE $where_clause
    ORDER BY vt.voided_at DESC
    LIMIT 1000
";

$stmt = $conn->prepare($void_query);
$stmt->execute($params);
$void_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get void statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_voids,
        SUM(total_amount) as total_voided_amount,
        COUNT(CASE WHEN void_type = 'product' THEN 1 END) as product_voids,
        COUNT(CASE WHEN void_type = 'cart' THEN 1 END) as cart_voids,
        COUNT(CASE WHEN void_type = 'sale' THEN 1 END) as sale_voids,
        COUNT(CASE WHEN void_type = 'held_transaction' THEN 1 END) as held_transaction_voids
    FROM void_transactions vt
    WHERE $where_clause
";

$stmt = $conn->prepare($stats_query);
$stmt->execute($params);
$void_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get users for filter
$users_stmt = $conn->query("SELECT id, username FROM users ORDER BY username");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Void Reports - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
        
        .void-card {
            border-left: 4px solid var(--primary-color);
        }
        
        .void-type-badge {
            font-size: 0.75rem;
        }
        
        .void-amount {
            font-weight: 600;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid py-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-x-circle text-danger"></i> Void Reports</h2>
                <a href="sale.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i> Back to POS
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card void-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title text-muted">Total Voids</h6>
                                    <h3 class="mb-0"><?php echo number_format($void_stats['total_voids']); ?></h3>
                                </div>
                                <div class="text-danger">
                                    <i class="bi bi-x-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card void-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title text-muted">Total Voided Amount</h6>
                                    <h3 class="mb-0 void-amount"><?php echo formatCurrency($void_stats['total_voided_amount'], $settings); ?></h3>
                                </div>
                                <div class="text-danger">
                                    <i class="bi bi-currency-dollar fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card void-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title text-muted">Product Voids</h6>
                                    <h3 class="mb-0"><?php echo number_format($void_stats['product_voids']); ?></h3>
                                </div>
                                <div class="text-warning">
                                    <i class="bi bi-box fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card void-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title text-muted">Cart Voids</h6>
                                    <h3 class="mb-0"><?php echo number_format($void_stats['cart_voids']); ?></h3>
                                </div>
                                <div class="text-info">
                                    <i class="bi bi-cart-x fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Void Type</label>
                            <select class="form-control" name="void_type">
                                <option value="all" <?php echo $void_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="product" <?php echo $void_type === 'product' ? 'selected' : ''; ?>>Product</option>
                                <option value="cart" <?php echo $void_type === 'cart' ? 'selected' : ''; ?>>Cart</option>
                                <option value="sale" <?php echo $void_type === 'sale' ? 'selected' : ''; ?>>Sale</option>
                                <option value="held_transaction" <?php echo $void_type === 'held_transaction' ? 'selected' : ''; ?>>Held Transaction</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">User</label>
                            <select class="form-control" name="user_filter">
                                <option value="all" <?php echo $user_filter === 'all' ? 'selected' : ''; ?>>All Users</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Void Transactions Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Void Transactions</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($void_transactions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-x-circle text-muted" style="font-size: 3rem;"></i>
                            <h5 class="text-muted mt-3">No void transactions found</h5>
                            <p class="text-muted">No void transactions match your filter criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Product</th>
                                        <th>Quantity</th>
                                        <th>Amount</th>
                                        <th>User</th>
                                        <th>Till</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($void_transactions as $void): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y g:i A', strtotime($void['voided_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $void['void_type'] === 'product' ? 'warning' : ($void['void_type'] === 'cart' ? 'info' : 'danger'); ?> void-type-badge">
                                                <?php echo ucfirst($void['void_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($void['product_name']): ?>
                                                <?php echo htmlspecialchars($void['product_name']); ?>
                                                <?php if ($void['product_id']): ?>
                                                    <br><small class="text-muted">ID: <?php echo $void['product_id']; ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $void['quantity'] ? number_format($void['quantity'], 3) : '-'; ?></td>
                                        <td class="void-amount"><?php echo formatCurrency($void['total_amount'], $settings); ?></td>
                                        <td><?php echo htmlspecialchars($void['username'] ?? 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($void['till_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($void['void_reason']); ?></td>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
