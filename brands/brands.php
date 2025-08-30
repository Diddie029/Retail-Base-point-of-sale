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

// Check if user has permission to manage products (includes brands)
if (!hasPermission('manage_products', $permissions)) {
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
$search = sanitizeProductInput($_GET['search'] ?? '');
$status_filter = sanitizeProductInput($_GET['status'] ?? 'all');
$sort_by = sanitizeProductInput($_GET['sort'] ?? 'name');
$sort_order = sanitizeProductInput($_GET['order'] ?? 'ASC');

// Build query with filters
$query = "
    SELECT b.*,
           COUNT(p.id) as product_count,
           SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) as active_product_count
    FROM brands b
    LEFT JOIN products p ON b.id = p.brand_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (b.name LIKE :search OR b.description LIKE :search OR b.website LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status_filter !== 'all') {
    $query .= " AND b.is_active = :is_active";
    $params[':is_active'] = ($status_filter === 'active') ? 1 : 0;
}

$query .= " GROUP BY b.id";

// Add sorting
$valid_sort_columns = ['name', 'created_at', 'product_count'];
if (in_array($sort_by, $valid_sort_columns)) {
    if ($sort_by === 'name') {
        $query .= " ORDER BY b.name " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'created_at') {
        $query .= " ORDER BY b.created_at " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } else {
        $query .= " ORDER BY " . $sort_by . " " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    }
} else {
    $query .= " ORDER BY b.name ASC";
}

// Get total count for pagination
$count_query = str_replace("SELECT b.*", "SELECT COUNT(DISTINCT b.id) as total", $query);
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_brands = $result ? $result['total'] : 0;

// Pagination
$per_page = 20;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $per_page;
$total_pages = ceil($total_brands / $per_page);

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $per_page;
$params[':offset'] = $offset;

// Execute main query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = sanitizeProductInput($_POST['bulk_action']);
    $brand_ids = $_POST['brand_ids'] ?? [];

    if (!empty($brand_ids) && is_array($brand_ids)) {
        $placeholders = str_repeat('?,', count($brand_ids) - 1) . '?';

        if ($action === 'activate') {
            $stmt = $conn->prepare("UPDATE brands SET is_active = 1 WHERE id IN ($placeholders)");
            $stmt->execute($brand_ids);
            $_SESSION['success'] = 'Selected brands have been activated.';
        } elseif ($action === 'deactivate') {
            $stmt = $conn->prepare("UPDATE brands SET is_active = 0 WHERE id IN ($placeholders)");
            $stmt->execute($brand_ids);
            $_SESSION['success'] = 'Selected brands have been deactivated.';
        } elseif ($action === 'delete') {
            // Check if brands are being used
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE brand_id IN ($placeholders)");
            $check_stmt->execute($brand_ids);
            $usage_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($usage_count > 0) {
                $_SESSION['error'] = 'Cannot delete brands that are being used by products.';
            } else {
                $stmt = $conn->prepare("DELETE FROM brands WHERE id IN ($placeholders)");
                $stmt->execute($brand_ids);
                $_SESSION['success'] = 'Selected brands have been deleted.';
            }
        }

        header("Location: brands.php");
        exit();
    }
}

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brand Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'brands';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Brand Management</h1>
                    <div class="header-subtitle">Manage product brands and manufacturers</div>
                </div>
                <div class="header-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Brand
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filters and Search -->
            <div class="filter-section">
                <form method="GET" class="filter-row">
                    <div class="form-group">
                        <input type="text" class="form-control" id="searchInput" name="search"
                               value="<?php echo htmlspecialchars($search); ?>" placeholder="Search brands...">
                    </div>
                    <div class="form-group">
                        <select class="form-control" name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <select class="form-control" name="sort">
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            <option value="product_count" <?php echo $sort_by === 'product_count' ? 'selected' : ''; ?>>Product Count</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                        <a href="brands.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <select class="form-control d-inline-block w-auto" name="bulk_action" required>
                            <option value="">Choose action</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check"></i>
                            Apply
                        </button>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo count($brands); ?> of <?php echo $total_brands; ?> brands
                    </div>
                </div>

                <!-- Brands Table -->
                <div class="product-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Products</th>
                                <th>Status</th>
                                <th>Website</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($brands)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-star text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="mt-3 text-muted">No brands found</h5>
                                    <p class="text-muted">Start by adding your first brand</p>
                                    <a href="add.php" class="btn btn-primary">
                                        <i class="bi bi-plus"></i>
                                        Add First Brand
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($brands as $brand): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="brand_ids[]" value="<?php echo $brand['id']; ?>"
                                               class="form-check-input brand-checkbox">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="product-image-placeholder me-3">
                                                <i class="bi bi-star"></i>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($brand['name']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($brand['description'] ?? 'No description'); ?></td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo $brand['active_product_count']; ?>/<?php echo $brand['product_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $brand['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo $brand['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($brand['website']): ?>
                                            <a href="<?php echo htmlspecialchars($brand['website']); ?>" target="_blank"
                                               class="text-decoration-none">
                                                <i class="bi bi-link-45deg"></i>
                                                Visit
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($brand['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="view.php?id=<?php echo $brand['id']; ?>" class="btn btn-sm btn-outline-primary"
                                               title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $brand['id']; ?>" class="btn btn-sm btn-outline-secondary"
                                               title="Edit Brand">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete.php?id=<?php echo $brand['id']; ?>" class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to delete this brand?')"
                                               title="Delete Brand">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                </div>
                <nav aria-label="Brand pagination">
                    <ul class="pagination">
                        <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                Next
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        // Bulk selection functionality
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const brandCheckboxes = document.querySelectorAll('.brand-checkbox');
            const bulkActions = document.getElementById('bulkActions');

            if (selectAllCheckbox && brandCheckboxes.length > 0) {
                selectAllCheckbox.addEventListener('change', function() {
                    brandCheckboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateBulkActions();
                });

                brandCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        updateBulkActions();

                        // Update select all checkbox
                        const checkedCount = document.querySelectorAll('.brand-checkbox:checked').length;
                        selectAllCheckbox.checked = checkedCount === brandCheckboxes.length;
                        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < brandCheckboxes.length;
                    });
                });
            }

            function updateBulkActions() {
                const checkedCount = document.querySelectorAll('.brand-checkbox:checked').length;
                if (bulkActions) {
                    if (checkedCount > 0) {
                        bulkActions.style.display = 'block';
                    } else {
                        bulkActions.style.display = 'none';
                    }
                }
            }
        });
    </script>
</body>
</html>
