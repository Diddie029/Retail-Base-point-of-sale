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

// Check if user has permission to manage products
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
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$brand_filter = $_GET['brand'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause - include products that are inactive OR have blocked suppliers
$where_conditions = ["(p.status = 'inactive' OR (s.is_active = 0 AND s.id IS NOT NULL))"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.barcode LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if (!empty($brand_filter)) {
    $where_conditions[] = "p.brand_id = :brand_id";
    $params[':brand_id'] = $brand_filter;
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "p.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN brands b ON p.brand_id = b.id LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_products = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_products / $per_page);

// Get products
$sql = "
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name,
           s.is_active as supplier_active, s.supplier_block_note
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE $where_clause
    ORDER BY p.updated_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get brands for filter
$brands_stmt = $conn->query("SELECT * FROM brands WHERE is_active = 1 ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter
$suppliers_stmt = $conn->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = sanitizeProductInput($_POST['bulk_action']);
    $product_ids = $_POST['product_ids'] ?? [];

    if (!empty($product_ids) && is_array($product_ids)) {
        $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';

        if ($action === 'activate') {
            // Only activate products that are actually inactive (not supplier-blocked)
            $stmt = $conn->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id IN ($placeholders) AND status = 'inactive'");
            $stmt->execute($product_ids);
            $_SESSION['success'] = 'Selected products have been activated. Note: Products blocked due to supplier status cannot be activated individually.';
        } elseif ($action === 'delete') {
            // Check if products are being used in sales
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id IN ($placeholders)");
            $check_stmt->execute($product_ids);
            $usage_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($usage_count > 0) {
                $_SESSION['error'] = 'Cannot delete products that are being used in sales.';
            } else {
                $stmt = $conn->prepare("DELETE FROM products WHERE id IN ($placeholders)");
                $stmt->execute($product_ids);
                $_SESSION['success'] = 'Selected products have been deleted.';
            }
        }

        header("Location: blocked.php");
        exit();
    }
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Set headers for CSV download
    $filename = 'blocked_products_export_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');

    $output = fopen('php://output', 'w');

    // Add BOM for proper UTF-8 handling in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV Headers
    $headers = ['ID', 'Name', 'Category', 'Brand', 'Supplier', 'Supplier Status', 'Block Reason', 'Price', 'Sale Price', 'Sale Start Date', 'Sale End Date', 'Tax Rate', 'Quantity', 'Barcode', 'SKU', 'Description', 'Created Date', 'Updated Date'];
    fputcsv($output, $headers);

    // Get all blocked products for export (including those with blocked suppliers)
    $export_sql = "
        SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name,
               s.is_active as supplier_active, s.supplier_block_note
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE (p.status = 'inactive' OR (s.is_active = 0 AND s.id IS NOT NULL))
        ORDER BY p.name
    ";

    $export_stmt = $conn->prepare($export_sql);
    $export_stmt->execute();
    $export_products = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    // CSV Data
    foreach ($export_products as $product) {
        $row = [
            $product['id'],
            $product['name'],
            $product['category_name'] ?? 'Uncategorized',
            $product['brand_name'] ?? '',
            $product['supplier_name'] ?? '',
            $product['supplier_active'] == 1 ? 'Active' : 'Blocked',
            $product['supplier_block_note'] ?? '',
            number_format($product['price'], 2),
            !empty($product['sale_price']) ? number_format($product['sale_price'], 2) : '',
            $product['sale_start_date'] ?? '',
            $product['sale_end_date'] ?? '',
            !empty($product['tax_rate']) ? number_format($product['tax_rate'], 2) : '',
            $product['quantity'],
            $product['barcode'],
            $product['sku'] ?? '',
            $product['description'] ?? '',
            $product['created_at'],
            $product['updated_at']
        ];

        fputcsv($output, $row);
    }

    fclose($output);
    exit();
}

// Get statistics
$stats = [];

// Total blocked products (inactive + supplier blocked)
$stmt = $conn->query("SELECT COUNT(*) as count FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.status = 'inactive' OR (s.is_active = 0 AND s.id IS NOT NULL)");
$stats['total_blocked'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total inventory value of blocked products
$stmt = $conn->query("SELECT SUM(p.price * p.quantity) as total FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.status = 'inactive' OR (s.is_active = 0 AND s.id IS NOT NULL)");
$stats['blocked_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Count of directly blocked products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE status = 'inactive'");
$stats['directly_blocked'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count of supplier-blocked products
$stmt = $conn->query("SELECT COUNT(*) as count FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id WHERE p.status = 'active' AND s.is_active = 0 AND s.id IS NOT NULL");
$stats['supplier_blocked'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

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
    <title>Blocked Products - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/products.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .blocked-badge {
            background-color: #dc3545;
            color: white;
        }

        .stat-subtext {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .filter-section .form-group {
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }

            .form-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'products';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Blocked Products</h1>
                    <div class="header-subtitle">Manage disabled/inactive products</div>
                </div>
                <div class="header-actions">
                    <a href="products.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Products
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

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_blocked']); ?></div>
                    <div class="stat-label">Total Blocked</div>
                    <div class="stat-subtext">
                        <?php echo $stats['directly_blocked']; ?> direct + <?php echo $stats['supplier_blocked']; ?> supplier
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['blocked_value'], 2); ?></div>
                    <div class="stat-label">Blocked Inventory Value</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-download"></i>
                        </div>
                    </div>
                    <div class="stat-value">
                        <a href="?export=csv&<?php echo http_build_query($_GET); ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-download"></i>
                            Export
                        </a>
                    </div>
                    <div class="stat-label">Export Options</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="form-group">
                            <label for="searchInput" class="form-label">Search Products</label>
                            <input type="text" class="form-control" id="searchInput" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, barcode, or SKU...">
                        </div>
                        <div class="form-group">
                            <label for="categoryFilter" class="form-label">Category</label>
                            <select class="form-control" id="categoryFilter" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="brandFilter" class="form-label">Brand</label>
                            <select class="form-control" id="brandFilter" name="brand">
                                <option value="">All Brands</option>
                                <?php foreach ($brands as $brand): ?>
                                <option value="<?php echo $brand['id']; ?>" <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brand['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="supplierFilter" class="form-label">Supplier</label>
                            <select class="form-control" id="supplierFilter" name="supplier">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                    Filter
                                </button>
                                <?php if (!empty($search) || !empty($category_filter) || !empty($brand_filter) || !empty($supplier_filter)): ?>
                                <a href="blocked.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i>
                                    Clear
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <select class="form-control d-inline-block w-auto" name="bulk_action" required>
                            <option value="">Choose action</option>
                            <option value="activate">Activate Products</option>
                            <option value="delete">Delete Products</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check"></i>
                            Apply
                        </button>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo count($products); ?> of <?php echo $total_products; ?> blocked products
                    </div>
                </div>

                <!-- Products Table -->
                <div class="product-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAll">
                                </th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Brand</th>
                                <th>Supplier</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Barcode</th>
                                <th>SKU</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="12" class="text-center">
                                    <div class="py-4">
                                        <i class="bi bi-check-circle" style="font-size: 3rem; color: #10b981;"></i>
                                        <p class="text-muted mt-2">No blocked products found</p>
                                        <a href="products.php" class="btn btn-primary">Back to Products</a>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="product_ids[]" value="<?php echo $product['id']; ?>" class="product-checkbox">
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="product-image-placeholder me-3">
                                            <i class="bi bi-image"></i>
                                        </div>
                                        <div>
                                                                                    <div class="font-weight-bold">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                            <span class="badge blocked-badge ms-2">
                                                <?php if ($product['status'] === 'inactive'): ?>
                                                    BLOCKED
                                                <?php elseif ($product['supplier_active'] == 0): ?>
                                                    SUPPLIER BLOCKED
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <small class="text-muted">ID: <?php echo $product['id']; ?></small>
                                        <?php if ($product['supplier_active'] == 0): ?>
                                            <br><small class="text-warning">
                                                <i class="bi bi-info-circle"></i>
                                                <?php echo htmlspecialchars($product['supplier_block_note'] ?? 'Supplier is blocked'); ?>
                                            </small>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                <td><?php echo htmlspecialchars($product['brand_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($product['supplier_name'] ?? '-'); ?></td>
                                <td class="currency">
                                    <?php if (isProductOnSale($product)): ?>
                                        <div class="d-flex flex-column">
                                            <span class="text-danger fw-bold"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['sale_price'], 2); ?></span>
                                            <small class="text-muted text-decoration-line-through"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></small>
                                        </div>
                                    <?php else: ?>
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="stock-quantity">
                                    <?php echo number_format($product['quantity']); ?>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($product['barcode']); ?></code>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($product['sku'] ?? '-'); ?></code>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo date('M j, Y', strtotime($product['updated_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="delete.php?id=<?php echo $product['id']; ?>"
                                           class="btn btn-danger btn-sm btn-delete"
                                           data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                           title="Delete">
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
                    Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_products)); ?>
                    of <?php echo number_format($total_products); ?> blocked products
                </div>
                <nav aria-label="Blocked products pagination">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/products.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Bulk actions visibility
            const checkboxes = document.querySelectorAll('.product-checkbox');
            const bulkActions = document.getElementById('bulkActions');
            const selectAll = document.getElementById('selectAll');

            function toggleBulkActions() {
                const checkedBoxes = document.querySelectorAll('.product-checkbox:checked');
                bulkActions.style.display = checkedBoxes.length > 0 ? 'block' : 'none';
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', toggleBulkActions);
            });

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    toggleBulkActions();
                });
            }

            // Delete confirmation
            const deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const productName = this.getAttribute('data-product-name');
                    if (!confirm(`Are you sure you want to delete "${productName}"? This action cannot be undone.`)) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
