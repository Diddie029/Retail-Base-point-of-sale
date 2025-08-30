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
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Debug: Check session variables
if (!isset($_SESSION['user_id'])) {
    echo "<!-- Debug: User not logged in -->";
    // Redirect to login if not logged in
    header("Location: ../auth/login.php");
    exit();
}

// Get user permissions
$permissions = [];
if ($role_id) {
    try {
        $stmt = $conn->prepare("
            SELECT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
        ");
        $stmt->bindParam(':role_id', $role_id);
        $stmt->execute();
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // If permissions tables don't exist, use default permissions
        $permissions = ['manage_products', 'process_sales', 'manage_sales'];
    }
}

// Check if user has permission to manage products (includes suppliers)
if (!hasPermission('manage_products', $permissions)) {
    echo "<!-- Debug: User doesn't have manage_products permission -->";
    // For debugging, allow access anyway
    // header("Location: ../dashboard/dashboard.php");
    // exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // If settings table doesn't exist or query fails, use defaults
    $settings = [
        'company_name' => 'POS System',
        'theme_color' => '#6366f1',
        'sidebar_color' => '#1e293b'
    ];
}

// Handle search and filters
$search = sanitizeProductInput($_GET['search'] ?? '');
$status_filter = sanitizeProductInput($_GET['status'] ?? 'all');
$sort_by = sanitizeProductInput($_GET['sort'] ?? 'name');
$sort_order = sanitizeProductInput($_GET['order'] ?? 'ASC');

// Build query with filters
$query = "
    SELECT s.*,
           COUNT(p.id) as product_count,
           COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_product_count
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (s.name LIKE :search OR s.contact_person LIKE :search OR s.email LIKE :search OR s.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status_filter !== 'all') {
    $query .= " AND s.is_active = :is_active";
    $params[':is_active'] = ($status_filter === 'active') ? 1 : 0;
}

$query .= " GROUP BY s.id";

// Add sorting
$valid_sort_columns = ['name', 'contact_person', 'email', 'created_at', 'product_count'];
if (in_array($sort_by, $valid_sort_columns)) {
    if ($sort_by === 'name') {
        $query .= " ORDER BY s.name " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'contact_person') {
        $query .= " ORDER BY s.contact_person " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'email') {
        $query .= " ORDER BY s.email " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'created_at') {
        $query .= " ORDER BY s.created_at " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } else {
        $query .= " ORDER BY " . $sort_by . " " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    }
} else {
    $query .= " ORDER BY s.name ASC";
}

// Get total count for pagination
$count_query = str_replace("SELECT s.*", "SELECT COUNT(DISTINCT s.id) as total", $query);
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_suppliers = $result ? $result['total'] : 0;

// Pagination
$per_page = 20;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $per_page;
$total_pages = ceil($total_suppliers / $per_page);

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
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle individual toggle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_supplier'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $deactivation_type = $_POST['deactivation_type'] ?? 'simple';
    $supplier_block_note = trim($_POST['supplier_block_note'] ?? '');

    // Get current status
    $stmt = $conn->prepare("SELECT is_active FROM suppliers WHERE id = :id");
    $stmt->bindParam(':id', $supplier_id);
    $stmt->execute();
    $current_status = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_status) {
        if ($current_status['is_active']) {
            // Deactivating supplier - validate block note is required
            if (empty($supplier_block_note)) {
                $_SESSION['error'] = 'Block reason is required when deactivating a supplier.';
                header("Location: suppliers.php");
                exit();
            }

            $new_status = 0;

            // Handle different deactivation types
            if ($deactivation_type === 'deactivate_products') {
                // Deactivate supplier and all associated products
                $conn->beginTransaction();
                try {
                    // Update supplier status and block note
                    $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                    $update_stmt->bindParam(':status', $new_status);
                    $update_stmt->bindParam(':block_note', $supplier_block_note);
                    $update_stmt->bindParam(':id', $supplier_id);
                    $update_stmt->execute();

                    // Deactivate all products from this supplier
                    $product_stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE supplier_id = :supplier_id");
                    $product_stmt->bindParam(':supplier_id', $supplier_id);
                    $product_stmt->execute();

                    $conn->commit();
                    $_SESSION['success'] = 'Supplier and all associated products have been deactivated.';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $_SESSION['error'] = 'Failed to deactivate supplier and products.';
                }
            } elseif ($deactivation_type === 'allow_selling') {
                // Deactivate supplier but keep products active for selling
                $conn->beginTransaction();
                try {
                    // Update supplier status and block note
                    $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                    $update_stmt->bindParam(':status', $new_status);
                    $update_stmt->bindParam(':block_note', $supplier_block_note);
                    $update_stmt->bindParam(':id', $supplier_id);
                    $update_stmt->execute();

                    $conn->commit();
                    $_SESSION['success'] = 'Supplier deactivated but products remain active for selling.';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $_SESSION['error'] = 'Failed to deactivate supplier.';
                }
            } elseif ($deactivation_type === 'supplier_notice') {
                // Issue supplier notice - keep supplier active but add block note
                $update_stmt = $conn->prepare("UPDATE suppliers SET supplier_block_note = :block_note WHERE id = :id");
                $update_stmt->bindParam(':block_note', $supplier_block_note);
                $update_stmt->bindParam(':id', $supplier_id);

                if ($update_stmt->execute()) {
                    $_SESSION['success'] = 'Supplier notice issued successfully.';
                } else {
                    $_SESSION['error'] = 'Failed to issue supplier notice.';
                }
            } else {
                // Simple deactivation - only supplier status changes
                $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                $update_stmt->bindParam(':status', $new_status);
                $update_stmt->bindParam(':block_note', $supplier_block_note);
                $update_stmt->bindParam(':id', $supplier_id);

                if ($update_stmt->execute()) {
                    $_SESSION['success'] = 'Supplier deactivated successfully.';
                } else {
                    $_SESSION['error'] = 'Failed to deactivate supplier.';
                }
            }
        } else {
            // Activating supplier - clear block note
            $new_status = 1;
            $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = NULL WHERE id = :id");
            $update_stmt->bindParam(':status', $new_status);
            $update_stmt->bindParam(':id', $supplier_id);

            if ($update_stmt->execute()) {
                $_SESSION['success'] = 'Supplier activated successfully.';
            } else {
                $_SESSION['error'] = 'Failed to activate supplier.';
            }
        }
    }

    header("Location: suppliers.php");
    exit();
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = sanitizeProductInput($_POST['bulk_action']);
    $supplier_ids = $_POST['supplier_ids'] ?? [];

    if (!empty($supplier_ids) && is_array($supplier_ids)) {
        $placeholders = str_repeat('?,', count($supplier_ids) - 1) . '?';

                 if ($action === 'activate') {
             // Check if confirmation checkbox is checked
             if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                 $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                 header("Location: suppliers.php");
                 exit();
             }
             
             $stmt = $conn->prepare("UPDATE suppliers SET is_active = 1, supplier_block_note = NULL WHERE id IN ($placeholders)");
             $stmt->execute($supplier_ids);
             $_SESSION['success'] = 'Selected suppliers have been activated.';
                 } elseif ($action === 'deactivate') {
             // Check if confirmation checkbox is checked
             if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                 $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                 header("Location: suppliers.php");
                 exit();
             }
             
                           $supplier_block_note = trim($_POST['supplier_block_note'] ?? '');
 
              // Validate block note is required for deactivation
              if (empty($supplier_block_note)) {
                  $_SESSION['error'] = 'Block reason is required when deactivating suppliers.';
                  header("Location: suppliers.php");
                  exit();
              }
              
              // Simple bulk deactivation - only supplier status changes
              $stmt = $conn->prepare("UPDATE suppliers SET is_active = 0, supplier_block_note = ? WHERE id IN ($placeholders)");
              $params = array_merge([$supplier_block_note], $supplier_ids);
              $stmt->execute($params);
              $_SESSION['success'] = 'Selected suppliers have been deactivated.';
        } elseif ($action === 'delete') {
            // Check if confirmation checkbox is checked
            if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                header("Location: suppliers.php");
                exit();
            }
            
            // Check if suppliers are being used
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE supplier_id IN ($placeholders)");
            $check_stmt->execute($supplier_ids);
            $usage_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($usage_count > 0) {
                $_SESSION['error'] = 'Cannot delete suppliers that are being used by products.';
            } else {
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($placeholders)");
                $stmt->execute($supplier_ids);
                $_SESSION['success'] = 'Selected suppliers have been deleted.';
            }
        }

        header("Location: suppliers.php");
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
    <title>Supplier Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Supplier Management</h1>
                    <div class="header-subtitle">Manage your product suppliers and vendors</div>
                </div>
                <div class="header-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Supplier
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
                               value="<?php echo htmlspecialchars($search); ?>" placeholder="Search suppliers...">
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
                            <option value="contact_person" <?php echo $sort_by === 'contact_person' ? 'selected' : ''; ?>>Contact Person</option>
                            <option value="email" <?php echo $sort_by === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            <option value="product_count" <?php echo $sort_by === 'product_count' ? 'selected' : ''; ?>>Product Count</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                        <a href="suppliers.php" class="btn btn-outline-secondary">
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
                        <select class="form-control d-inline-block w-auto" name="bulk_action" id="bulkAction" required>
                            <option value="">Choose action</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <textarea class="form-control d-inline-block w-auto ms-2" name="supplier_block_note" id="bulkBlockNote" rows="1" placeholder="Block reason..." style="display: none; min-width: 200px;" required></textarea>
                        <textarea class="form-control d-inline-block w-auto ms-2" name="supplier_block_note" id="bulkBlockNote" rows="1" placeholder="Block reason..." style="display: none; min-width: 200px;" required></textarea>
                        <div class="form-check d-inline-block ms-2" id="bulkConfirmationSection" style="display: none;">
                            <input class="form-check-input" type="checkbox" id="bulkConfirmAction" name="bulk_confirm_action" required>
                            <label class="form-check-label" for="bulkConfirmAction">
                                Confirm action
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check"></i>
                            Apply
                        </button>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo count($suppliers); ?> of <?php echo $total_suppliers; ?> suppliers
                    </div>
                </div>

                <!-- Suppliers Table -->
                <div class="product-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>Name</th>
                                <th>Contact Person</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Products</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <i class="bi bi-truck text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="mt-3 text-muted">No suppliers found</h5>
                                    <p class="text-muted">Start by adding your first supplier</p>
                                    <a href="add.php" class="btn btn-primary">
                                        <i class="bi bi-plus"></i>
                                        Add First Supplier
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="supplier_ids[]" value="<?php echo $supplier['id']; ?>"
                                               class="form-check-input supplier-checkbox">
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="product-image-placeholder me-3">
                                                <i class="bi bi-truck"></i>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($supplier['name']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'Not specified'); ?></td>
                                    <td>
                                        <?php if ($supplier['email']): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($supplier['phone']): ?>
                                            <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($supplier['phone']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo $supplier['active_product_count']; ?>/<?php echo $supplier['product_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $supplier['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                            <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($supplier['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="view.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-primary"
                                               title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-secondary"
                                               title="Edit Supplier">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm <?php echo $supplier['is_active'] ? 'btn-warning' : 'btn-success'; ?> toggle-status"
                                                    data-id="<?php echo $supplier['id']; ?>"
                                                    data-current-status="<?php echo $supplier['is_active']; ?>"
                                                    title="<?php echo $supplier['is_active'] ? 'Deactivate Supplier' : 'Activate Supplier'; ?>">
                                                <i class="bi <?php echo $supplier['is_active'] ? 'bi-pause-fill' : 'bi-play-fill'; ?>"></i>
                                            </button>
                                            <a href="delete.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Are you sure you want to delete this supplier?')"
                                               title="Delete Supplier">
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
                <nav aria-label="Supplier pagination">
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
    <script src="../assets/js/suppliers.js"></script>

</body>
</html>
