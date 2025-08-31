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
if (!in_array('view_expiry_alerts', $permissions) && !in_array('manage_expiry_tracker', $permissions)) {
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
    <title><?php echo $page_title; ?> - POS System</title>
    <link rel="stylesheet" href="../assets/css/expiry_tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-clock"></i> <?php echo $page_title; ?></h1>
            <div class="header-actions">
                <?php if (in_array('manage_expiry_tracker', $permissions)): ?>
                    <a href="add_expiry_date.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Expiry Date
                    </a>
                <?php endif; ?>

                <!-- Database is now automatically managed through include/db.php -->
                <div class="alert alert-info" style="margin-top: 15px;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Database Status:</strong> All expiry tracker tables are automatically managed.
                </div>
            </div>
        </div>

        <!-- Alert Summary -->
        <div class="alert-summary">
            <div class="alert-card active">
                <div class="alert-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="alert-content">
                    <h3><?php echo count($critical_alerts); ?></h3>
                    <p>Critical Alerts</p>
                </div>
            </div>
            <div class="alert-card expired">
                <div class="alert-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="alert-content">
                    <h3><?php echo $alert_counts['expired']; ?></h3>
                    <p>Expired Items</p>
                </div>
            </div>
            <div class="alert-card disposed">
                <div class="alert-icon">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="alert-content">
                    <h3><?php echo $alert_counts['disposed']; ?></h3>
                    <p>Disposed Items</p>
                </div>
            </div>
            <div class="alert-card returned">
                <div class="alert-icon">
                    <i class="fas fa-undo"></i>
                </div>
                <div class="alert-content">
                    <h3><?php echo $alert_counts['returned']; ?></h3>
                    <p>Returned Items</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="disposed" <?php echo $status_filter === 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                        <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="category">Category:</label>
                    <select name="category" id="category">
                        <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="days">Expires Within:</label>
                    <select name="days" id="days">
                        <option value="all" <?php echo $days_filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="7" <?php echo $days_filter === '7' ? 'selected' : ''; ?>>7 Days</option>
                        <option value="30" <?php echo $days_filter === '30' ? 'selected' : ''; ?>>30 Days</option>
                        <option value="60" <?php echo $days_filter === '60' ? 'selected' : ''; ?>>60 Days</option>
                        <option value="90" <?php echo $days_filter === '90' ? 'selected' : ''; ?>>90 Days</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="search">Search:</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name, SKU, or batch number">
                </div>
                
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="expiry_tracker.php" class="btn btn-secondary">Clear Filters</a>
            </form>
        </div>

        <!-- Expiry Data Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
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
                            <td colspan="9" class="no-data">No expiry data found matching the current filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expiry_data as $item): ?>
                            <?php
                            $days_until_expiry = (strtotime($item['expiry_date']) - time()) / (60 * 60 * 24);
                            $is_critical = $days_until_expiry <= 7 && $item['status'] === 'active';
                            $is_expired = $days_until_expiry < 0;
                            ?>
                            <tr class="<?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                <td>
                                    <div class="product-info">
                                        <?php if ($item['image_url']): ?>
                                            <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" class="product-image">
                                        <?php endif; ?>
                                        <div>
                                            <div class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                            <div class="product-sku"><?php echo htmlspecialchars($item['sku']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($item['batch_number'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="expiry-date <?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                        <?php echo date('M d, Y', strtotime($item['expiry_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="days-left <?php echo $is_critical ? 'critical' : ($is_expired ? 'expired' : ''); ?>">
                                        <?php 
                                        if ($is_expired) {
                                            echo '<span class="expired">Expired ' . abs(round($days_until_expiry)) . ' days ago</span>';
                                        } else {
                                            echo round($days_until_expiry) . ' days';
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="quantity">
                                        <?php echo number_format($item['remaining_quantity']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $item['status']; ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($item['expiry_category_name']): ?>
                                        <span class="expiry-category" style="background-color: <?php echo $item['expiry_color']; ?>">
                                            <?php echo htmlspecialchars($item['expiry_category_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="expiry-category default">
                                            <?php echo htmlspecialchars($item['category_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['supplier_name'] ?: 'N/A'); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_expiry_item.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (in_array('handle_expired_items', $permissions) && $item['status'] === 'active'): ?>
                                            <a href="handle_expiry.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-warning" title="Handle Expiry">
                                                <i class="fas fa-tools"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (in_array('manage_expiry_tracker', $permissions)): ?>
                                            <a href="edit_expiry_date.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
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

        <!-- Pagination -->
        <div class="pagination">
            <p>Showing <?php echo count($expiry_data); ?> items</p>
        </div>
    </div>

    <script src="../assets/js/expiry_tracker.js"></script>
</body>
</html>
