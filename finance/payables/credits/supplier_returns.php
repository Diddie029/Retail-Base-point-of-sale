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
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// Build WHERE clause for returns (from inventory system)
$where = [];
$params = [];

// Show returns ready for credit processing by default
$where[] = "r.status IN ('approved', 'completed', 'processed')";

if (!empty($search)) {
    $where[] = "(r.return_number LIKE :search OR s.name LIKE :search OR u.username LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($supplier_filter)) {
    $where[] = "r.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

if (!empty($status_filter)) {
    // Override default filter if specific status is selected
    $where = array_filter($where, function($clause) {
        return !str_contains($clause, "r.status IN");
    });
    $where[] = "r.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($date_from)) {
    $where[] = "DATE(r.created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "DATE(r.created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM returns r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    LEFT JOIN users u ON r.user_id = u.id
    $where_clause
";

$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_returns = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_returns / $per_page);
$offset = ($page - 1) * $per_page;

// Get returns with pagination (from inventory system)
$sql = "
    SELECT 
        r.*,
        s.name as supplier_name,
        s.contact_person,
        s.phone,
        s.email,
        u.username as created_by_name,
        COALESCE(au.username, 'System') as approved_by_name
    FROM returns r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN users au ON r.approved_by = au.id
    $where_clause
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT :offset, :per_page
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_returns,
        SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as pending_returns,
        SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved_returns,
        SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed_returns,
        SUM(CASE WHEN r.status = 'processed' THEN 1 ELSE 0 END) as processed_returns,
        SUM(COALESCE(r.total_amount, 0)) as total_amount
    FROM returns r
    LEFT JOIN suppliers s ON r.supplier_id = s.id
    LEFT JOIN users u ON r.user_id = u.id
    $where_clause
";

$stats_stmt = $conn->prepare($stats_sql);
foreach ($params as $key => $value) {
    $stats_stmt->bindValue($key, $value);
}
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get suppliers for filter
$suppliers = [];
$supplier_stmt = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Supplier Returns";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="../../../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
        }
        .action-btn {
            min-width: 80px;
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
                            <i class="mdi mdi-arrow-left me-1"></i>Back to Payables
                        </a>
                        <h1 class="mb-0"><i class="mdi mdi-undo me-2"></i>Supplier Returns</h1>
                    </div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                            <li class="breadcrumb-item"><a href="../../../dashboard/dashboard.php" style="color: rgba(255,255,255,0.8);">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../../index.php" style="color: rgba(255,255,255,0.8);">Finance</a></li>
                            <li class="breadcrumb-item"><a href="../payables.php" style="color: rgba(255,255,255,0.8);">Payables</a></li>
                            <li class="breadcrumb-item active" style="color: white;">Supplier Returns</li>
                        </ol>
                    </nav>
                    <p class="header-subtitle mb-0" style="color: rgba(255,255,255,0.9);">View supplier returns ready for credit processing</p>
                </div>
            </div>
        </header>

        <main class="content">

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100 card-hover">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Ready for Credit</h6>
                            <h2 class="fw-bold text-primary mb-0"><?php echo number_format($stats['total_returns'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-undo text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Approved/Completed/Processed</span>
                        <span class="fw-semibold text-primary"><?php echo formatCurrency($stats['total_amount'] ?? 0, $settings); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100 card-hover">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Pending</h6>
                            <h2 class="fw-bold text-warning mb-0"><?php echo number_format($stats['pending_returns'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-clock-outline text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Awaiting Approval</span>
                        <span class="fw-semibold text-warning">Needs Review</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100 card-hover">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Approved</h6>
                            <h2 class="fw-bold text-success mb-0"><?php echo number_format($stats['approved_returns'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-check-circle text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Processed</span>
                        <span class="fw-semibold text-success">Ready for Credit</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="card border-0 shadow-sm h-100 card-hover">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="text-muted fw-semibold text-uppercase mb-1">Completed</h6>
                            <h2 class="fw-bold text-success mb-0"><?php echo number_format($stats['completed_returns'] ?? 0); ?></h2>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded-circle p-3">
                            <i class="mdi mdi-check-circle text-success" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Fully Processed</span>
                        <span class="fw-semibold text-success">Ready for Credit</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Return number, supplier, notes...">
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
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="processed" <?php echo $status_filter == 'processed' ? 'selected' : ''; ?>>Processed</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
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

    <!-- Returns Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="header-title">Returns (<?php echo $total_returns; ?>)</h4>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Return #</th>
                                    <th>Supplier</th>
                                    <th>Return Date</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th width="200">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($returns)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">No returns found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($returns as $return): ?>
                                        <tr>
                                            <td>
                                                <a href="../../../inventory/view_returns.php?id=<?php echo $return['id']; ?>" class="text-primary fw-bold">
                                                    <?php echo htmlspecialchars($return['return_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($return['supplier_name']); ?></div>
                                                    <?php if ($return['contact_person']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($return['contact_person']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($return['created_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $return['total_items'] ?? 0; ?> items</span>
                                            </td>
                                            <td class="fw-bold"><?php echo formatCurrency($return['total_amount'] ?? 0, $settings); ?></td>
                                            <td>
                                                <span class="badge status-badge bg-<?php 
                                                    echo $return['status'] == 'completed' ? 'success' : 
                                                        ($return['status'] == 'processed' ? 'success' :
                                                        ($return['status'] == 'approved' ? 'info' :
                                                        ($return['status'] == 'cancelled' ? 'danger' : 'warning'))); 
                                                ?>">
                                                    <?php echo ucfirst($return['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo htmlspecialchars($return['created_by_name']); ?></small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="../../../inventory/view_returns.php?id=<?php echo $return['id']; ?>" 
                                                       class="btn btn-primary btn-sm action-btn" 
                                                       title="View Return">
                                                        <i class="mdi mdi-eye me-1"></i>View
                                                    </a>
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
                        <nav aria-label="Return pagination">
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
    <script>
    // No JavaScript needed for basic functionality
    </script>
</body>
</html>
