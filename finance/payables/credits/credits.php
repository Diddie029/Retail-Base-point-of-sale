<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';
require_once __DIR__ . '/../../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters and search
$search = $_GET['search'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build WHERE clause
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(sc.credit_number LIKE :search OR s.name LIKE :search OR sc.reason LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "sc.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($type_filter)) {
    $where[] = "sc.credit_type = :type";
    $params[':type'] = $type_filter;
}

if (!empty($status_filter)) {
    $where[] = "sc.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($date_from)) {
    $where[] = "sc.credit_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "sc.credit_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM supplier_credits sc
    LEFT JOIN suppliers s ON sc.supplier_id = s.id
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_credits = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_credits / $per_page);

// Get credits with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT 
        sc.*,
        s.name as supplier_name,
        u.username as created_by_name
    FROM supplier_credits sc
    LEFT JOIN suppliers s ON sc.supplier_id = s.id
    LEFT JOIN users u ON sc.created_by = u.id
    $where_clause
    ORDER BY sc.credit_date DESC, sc.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter dropdown
$suppliers = [];
$stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get credit statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_credits,
        COALESCE(SUM(credit_amount), 0) as total_amount,
        COALESCE(SUM(available_amount), 0) as available_amount,
        COALESCE(SUM(applied_amount), 0) as applied_amount
    FROM supplier_credits
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats = $result;

$page_title = "Supplier Credits";
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
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <?php include '../../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
            <div class="header-content">
                <div class="header-title">
                    <div class="d-flex align-items-center mb-2">
                        <a href="../payables.php" class="btn btn-outline-light btn-sm me-3">
                            <i class="bi bi-arrow-left me-1"></i>Back to Payables
                        </a>
                        <h1 class="mb-0"><i class="bi bi-person-plus me-2"></i>Supplier Credits</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Credits</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">Manage supplier credits and applications</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Total Credits</h5>
                            <h3 class="my-2 py-1"><?php echo number_format($stats['total_credits']); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="bi bi-person-plus text-primary" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Total Amount</h5>
                            <h3 class="my-2 py-1"><?php echo formatCurrency($stats['total_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="bi bi-currency-dollar text-success" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Available</h5>
                            <h3 class="my-2 py-1 text-info"><?php echo formatCurrency($stats['available_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="bi bi-check-circle text-info" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-6">
                            <h5 class="text-muted fw-normal mt-0 text-truncate">Applied</h5>
                            <h3 class="my-2 py-1 text-warning"><?php echo formatCurrency($stats['applied_amount'], $settings); ?></h3>
                        </div>
                        <div class="col-6">
                            <div class="text-end">
                                <i class="bi bi-check text-warning" style="font-size: 3rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="header-title">Credit Management</h4>
                        <a href="add_credit.php" class="btn btn-primary">
                            <i class="bi bi-plus"></i> Add Credit
                        </a>
                    </div>
                    
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Credit #, Supplier, Reason...">
                        </div>
                        <div class="col-md-2">
                            <label for="supplier" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier" name="supplier">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" 
                                            <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="">All Types</option>
                                <option value="return" <?php echo $type_filter == 'return' ? 'selected' : ''; ?>>Return</option>
                                <option value="discount" <?php echo $type_filter == 'discount' ? 'selected' : ''; ?>>Discount</option>
                                <option value="overpayment" <?php echo $type_filter == 'overpayment' ? 'selected' : ''; ?>>Overpayment</option>
                                <option value="adjustment" <?php echo $type_filter == 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                                <option value="other" <?php echo $type_filter == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="partially_applied" <?php echo $status_filter == 'partially_applied' ? 'selected' : ''; ?>>Partially Applied</option>
                                <option value="fully_applied" <?php echo $status_filter == 'fully_applied' ? 'selected' : ''; ?>>Fully Applied</option>
                                <option value="expired" <?php echo $status_filter == 'expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block w-100">Filter</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Credits Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Credit #</th>
                                    <th>Supplier</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Applied</th>
                                    <th>Available</th>
                                    <th>Status</th>
                                    <th>Expiry</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($credits)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">No credits found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($credits as $credit): ?>
                                        <tr>
                                            <td>
                                                <a href="view_credit.php?id=<?php echo $credit['id']; ?>" class="text-primary">
                                                    <?php echo htmlspecialchars($credit['credit_number']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($credit['supplier_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $credit['credit_type'] == 'return' ? 'danger' : 
                                                        ($credit['credit_type'] == 'discount' ? 'success' : 
                                                        ($credit['credit_type'] == 'overpayment' ? 'info' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($credit['credit_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($credit['credit_date'])); ?></td>
                                            <td class="fw-bold"><?php echo formatCurrency($credit['credit_amount'], $settings); ?></td>
                                            <td><?php echo formatCurrency($credit['applied_amount'], $settings); ?></td>
                                            <td class="fw-bold text-info"><?php echo formatCurrency($credit['available_amount'], $settings); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $credit['status'] == 'available' ? 'success' : 
                                                        ($credit['status'] == 'partially_applied' ? 'warning' : 
                                                        ($credit['status'] == 'fully_applied' ? 'info' : 'danger')); 
                                                ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $credit['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($credit['expiry_date']): ?>
                                                    <?php 
                                                    $expiry_date = new DateTime($credit['expiry_date']);
                                                    $today = new DateTime();
                                                    $is_expired = $expiry_date < $today;
                                                    ?>
                                                    <span class="<?php echo $is_expired ? 'text-danger' : 'text-muted'; ?>">
                                                        <?php echo date('M d, Y', strtotime($credit['expiry_date'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">No expiry</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="view_credit.php?id=<?php echo $credit['id']; ?>" 
                                                       class="btn btn-outline-info btn-sm"
                                                       title="View Credit">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if ($credit['status'] != 'fully_applied' && $credit['available_amount'] > 0): ?>
                                                        <a href="apply_credit.php?credit_id=<?php echo $credit['id']; ?>" 
                                                           class="btn btn-outline-success btn-sm"
                                                           title="Apply Credit">
                                                            <i class="bi bi-check"></i>
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
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Credit pagination">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
