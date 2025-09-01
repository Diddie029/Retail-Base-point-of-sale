<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check permissions
if (!hasPermission('view_expense_reports', $permissions)) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get filter parameters
$category_filter = $_GET['category'] ?? '';
$vendor_filter = $_GET['vendor'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($category_filter) {
    $where_conditions[] = "e.category_id = ?";
    $params[] = $category_filter;
}

if ($vendor_filter) {
    $where_conditions[] = "e.vendor_id = ?";
    $params[] = $vendor_filter;
}

if ($department_filter) {
    $where_conditions[] = "e.department_id = ?";
    $params[] = $department_filter;
}

if ($status_filter) {
    $where_conditions[] = "e.approval_status = ?";
    $params[] = $status_filter;
}

if ($date_from) {
    $where_conditions[] = "e.expense_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "e.expense_date <= ?";
    $params[] = $date_to;
}

if ($search) {
    $where_conditions[] = "(e.title LIKE ? OR e.description LIKE ? OR e.expense_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get expenses with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT e.*, 
           ec.name as category_name,
           sc.name as subcategory_name,
           ev.name as vendor_name,
           ed.name as department_name,
           epm.name as payment_method_name,
           u.username as created_by_name,
           a.username as approved_by_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON e.category_id = ec.id
    LEFT JOIN expense_categories sc ON e.subcategory_id = sc.id
    LEFT JOIN expense_vendors ev ON e.vendor_id = ev.id
    LEFT JOIN expense_departments ed ON e.department_id = ed.id
    LEFT JOIN expense_payment_methods epm ON e.payment_method_id = epm.id
    LEFT JOIN users u ON e.created_by = u.id
    LEFT JOIN users a ON e.approved_by = a.id
    $where_clause
    ORDER BY e.created_at DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total FROM expenses e $where_clause
";
$stmt = $conn->prepare($count_query);
$stmt->execute($params);
$total_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_expenses / $per_page);

// Get filter options
$categories = $conn->query("SELECT id, name FROM expense_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$vendors = $conn->query("SELECT id, name FROM expense_vendors WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $conn->query("SELECT id, name FROM expense_departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_expenses,
        SUM(total_amount) as total_amount,
        SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as pending_amount
    FROM expenses e
    $where_clause
";
$stmt = $conn->prepare($stats_query);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Management - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
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
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-cash-stack"></i> Expense Management</h1>
                    <p class="header-subtitle">Manage and track your business expenses</p>
                </div>
                <div class="header-actions">
                    <?php if (hasPermission('create_expenses', $permissions)): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Expense
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('export_expenses', $permissions)): ?>
                    <a href="export.php" class="btn btn-outline-secondary">
                        <i class="bi bi-download"></i> Export
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Expenses</h6>
                                        <h3><?= number_format($stats['total_expenses']) ?></h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-cash-stack fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Total Amount</h6>
                                        <h3>KES <?= number_format($stats['total_amount'] ?? 0, 2) ?></h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-currency-dollar fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Pending Approval</h6>
                                        <h3><?= number_format($stats['pending_count'] ?? 0) ?></h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-clock fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-title">Pending Payment</h6>
                                        <h3>KES <?= number_format($stats['pending_amount'] ?? 0, 2) ?></h3>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-credit-card fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Management Links -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-gear"></i> Quick Management</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <?php if (hasPermission('manage_expense_categories', $permissions)): ?>
                                    <div class="col-md-3">
                                        <a href="categories.php" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-tags"></i> Manage Categories
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (hasPermission('manage_expense_departments', $permissions)): ?>
                                    <div class="col-md-3">
                                        <a href="departments.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-building"></i> Manage Departments
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (hasPermission('manage_expense_vendors', $permissions)): ?>
                                    <div class="col-md-3">
                                        <a href="vendors.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-shop"></i> Manage Vendors
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (hasPermission('view_expense_reports', $permissions)): ?>
                                    <div class="col-md-3">
                                        <a href="reports.php" class="btn btn-outline-warning w-100">
                                            <i class="bi bi-graph-up"></i> View Reports
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel"></i> Filters</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search expenses...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Vendor</label>
                                <select class="form-select" name="vendor">
                                    <option value="">All Vendors</option>
                                    <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= $vendor['id'] ?>" <?= $vendor_filter == $vendor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($vendor['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>" <?= $department_filter == $department['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($department['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                            </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Expenses Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-ul"></i> Expenses List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($expenses)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox fs-1 text-muted"></i>
                            <h5 class="mt-3">No expenses found</h5>
                            <p class="text-muted">No expenses match your current filters.</p>
                            <?php if (hasPermission('create_expenses', $permissions)): ?>
                            <a href="add.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Add Your First Expense
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Expense #</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Vendor</th>
                                        <th>Department</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($expense['expense_number']) ?></strong>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($expense['title']) ?></strong>
                                                <?php if ($expense['is_recurring']): ?>
                                                <span class="badge bg-info">Recurring</span>
                                                <?php endif; ?>
                                                <?php if ($expense['is_tax_deductible']): ?>
                                                <span class="badge bg-success">Tax Deductible</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($expense['description']): ?>
                                            <small class="text-muted"><?= htmlspecialchars(substr($expense['description'], 0, 50)) ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background-color: <?= $expense['category_name'] ? '#6366f1' : '#6b7280' ?>">
                                                <?= htmlspecialchars($expense['category_name']) ?>
                                            </span>
                                            <?php if ($expense['subcategory_name']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($expense['subcategory_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($expense['vendor_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($expense['department_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <strong>KES <?= number_format($expense['total_amount'], 2) ?></strong>
                                            <?php if ($expense['tax_amount'] > 0): ?>
                                            <br><small class="text-muted">Tax: KES <?= number_format($expense['tax_amount'], 2) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_colors = [
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                'cancelled' => 'secondary'
                                            ];
                                            $status_color = $status_colors[$expense['approval_status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $status_color ?>">
                                                <?= ucfirst($expense['approval_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $payment_colors = [
                                                'pending' => 'warning',
                                                'paid' => 'success',
                                                'partial' => 'info',
                                                'overdue' => 'danger'
                                            ];
                                            $payment_color = $payment_colors[$expense['payment_status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $payment_color ?>">
                                                <?= ucfirst($expense['payment_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?= date('M d, Y', strtotime($expense['expense_date'])) ?></div>
                                            <small class="text-muted">by <?= htmlspecialchars($expense['created_by_name']) ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="view.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-primary" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if (hasPermission('edit_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                                                <a href="edit.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (hasPermission('approve_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                                                <a href="approve.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-success" title="Approve">
                                                    <i class="bi bi-check-circle"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (hasPermission('delete_expenses', $permissions) && $expense['approval_status'] == 'pending'): ?>
                                                <a href="delete.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this expense?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Expenses pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                                </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/expenses.js"></script>
</body>
</html>
