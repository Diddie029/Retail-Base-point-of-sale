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

// Check permissions
if (!hasPermission('manage_products', $permissions) && !hasPermission('manage_inventory', $permissions)) {
    header("Location: inventory.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle bulk status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_status_update'])) {
    $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $new_status = $_POST['new_status'] ?? '';

    if (empty($product_ids)) {
        $_SESSION['error'] = 'No products selected for status update.';
        header("Location: bulk_status_update.php");
    exit();
}

    if (empty($new_status)) {
        $_SESSION['error'] = 'Please select a status to apply.';
        header("Location: bulk_status_update.php");
    exit();
}

    try {
        $conn->beginTransaction();
        $affected_count = 0;

        $stmt = $conn->prepare("UPDATE products SET status = ?, updated_at = NOW() WHERE id = ?");
        foreach ($product_ids as $product_id) {
            $stmt->execute([$new_status, $product_id]);
            $affected_count += $stmt->rowCount();
        }

        $conn->commit();

        $_SESSION['success'] = "Status updated to '$new_status' for $affected_count products successfully.";
        logActivity($conn, $user_id, 'bulk_status_update', "Updated status to '$new_status' for $affected_count products");

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Status update failed: ' . $e->getMessage();
        logActivity($conn, $user_id, 'bulk_status_update_failed', 'Failed bulk status update: ' . $e->getMessage());
    }

    header("Location: bulk_status_update.php");
    exit();
}

// Get filter parameters
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($category_filter)) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get products with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$count_sql = "
    SELECT COUNT(*) as total
    FROM products p
    {$where_clause}
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_products / $per_page);

$sql = "
    SELECT
        p.id,
        p.name,
        p.sku,
        p.barcode,
        p.price,
        p.cost_price,
        p.quantity,
        p.minimum_stock,
        p.status,
        c.name as category_name,
        b.name as brand_name,
        s.name as supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    {$where_clause}
    ORDER BY p.name ASC
    LIMIT {$per_page} OFFSET {$offset}
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$categories = [];
$stmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Status Update - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .bulk-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            margin: -20px -20px 20px -20px;
            border-radius: 8px;
        }

        .product-row {
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 10px;
            background: white;
            transition: all 0.2s;
        }

        .product-row:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: var(--primary-color);
        }

        .product-row.selected {
            border-color: var(--primary-color);
            background: #f8f9ff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .product-checkbox {
            transform: scale(1.2);
        }

        .bulk-actions-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            display: none;
        }

        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-active { background: #28a745; }
        .status-inactive { background: #6c757d; }
        .status-discontinued { background: #dc3545; }

        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }

        .select-all-section {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .progress-tracker {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .progress-bar-custom {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--primary-color);
            width: 0%;
            transition: width 0.3s ease;
        }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
    <?php include '../include/navmenu.php'; ?>

        <main class="main-content">
            <div class="bulk-header">
                <h1><i class="bi bi-toggle-on me-2"></i>Bulk Status Update</h1>
                <p>Update the status of multiple products at once</p>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Statistics Summary -->
            <div class="stats-summary">
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($total_products); ?></div>
                    <div class="stat-label">Total Products</div>
                                        </div>
                <div class="stat-item">
                    <div class="stat-value" id="selectedCount">0</div>
                    <div class="stat-label">Selected</div>
                                        </div>
                <div class="stat-item">
                    <div class="stat-value" id="activeCount">
                        <?php
                        $active_count = array_reduce($products, function($count, $product) {
                            return $count + ($product['status'] === 'active' ? 1 : 0);
                        }, 0);
                        echo number_format($active_count);
                        ?>
                                    </div>
                    <div class="stat-label">Active</div>
                                </div>
                <div class="stat-item">
                    <div class="stat-value" id="inactiveCount">
                        <?php
                        $inactive_count = array_reduce($products, function($count, $product) {
                            return $count + ($product['status'] === 'inactive' ? 1 : 0);
                        }, 0);
                        echo number_format($inactive_count);
                        ?>
                            </div>
                    <div class="stat-label">Inactive</div>
                    </div>
                </div>
                
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, SKU, or barcode...">
                                </div>
                                
                    <div class="col-md-3">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"
                                        <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                                    </select>
                                </div>
                                
                    <div class="col-md-3">
                        <label for="status" class="form-label">Current Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="discontinued" <?php echo $status_filter === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                        </select>
                                </div>
                                
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Filter
                                    </button>
                        <a href="bulk_status_update.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Clear
                                    </a>
                                </div>
                            </form>
                        </div>

            <!-- Bulk Actions Panel -->
            <form method="POST" id="bulkForm">
                <div class="bulk-actions-panel" id="bulkActions">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0">
                                <i class="bi bi-check-circle me-2"></i>
                                <span id="selectedText">No products selected</span>
                            </h6>
                    </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <select class="form-control form-control-sm" name="new_status" required>
                                    <option value="">Choose Status</option>
                                    <option value="active">Set Active</option>
                                    <option value="inactive">Set Inactive</option>
                                    <option value="discontinued">Set Discontinued</option>
                                </select>
                                <button type="submit" name="bulk_status_update" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check"></i> Apply
                                </button>
                </div>
            </div>
        </div>
    </div>

                <!-- Select All Section -->
                <div class="select-all-section">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <input type="checkbox" id="selectAllProducts" class="form-check-input me-2">
                            <label for="selectAllProducts" class="form-check-label fw-bold">
                                Select All Products (<?php echo number_format($total_products); ?>)
                            </label>
                        </div>
                        <div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="selectActive">
                                Select Active Only
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="selectInactive">
                                Select Inactive Only
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="clearSelection">
                                Clear All
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Products List -->
                <div class="products-list">
                    <?php if (empty($products)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-search display-1 text-muted mb-3"></i>
                            <h4 class="text-muted">No products found</h4>
                            <p class="text-muted mb-4">Try adjusting your search filters</p>
                            <a href="bulk_status_update.php" class="btn btn-primary">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Clear Filters
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <div class="product-row" data-product-id="<?php echo $product['id']; ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-1">
                                        <input type="checkbox" class="form-check-input product-checkbox"
                                               name="product_ids[]" value="<?php echo $product['id']; ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <div class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="text-muted small">
                                            SKU: <?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?> |
                                            Barcode: <?php echo htmlspecialchars($product['barcode'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <span class="badge bg-secondary">
                                            <span class="status-indicator status-<?php echo $product['status']; ?>"></span>
                                            <?php echo ucfirst($product['status']); ?>
                                        </span>
                                    </div>
                                    <div class="col-md-2">
                                        <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?>
                                    </div>
                                    <div class="col-md-1 text-center">
                                        <span class="badge bg-info"><?php echo number_format($product['quantity']); ?></span>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Products pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let selectedProducts = new Set();
            const bulkActions = document.getElementById('bulkActions');
            const selectedText = document.getElementById('selectedText');
            const selectedCount = document.getElementById('selectedCount');

            // Individual product checkboxes
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const productId = this.value;
                    const productRow = this.closest('.product-row');

                    if (this.checked) {
                        selectedProducts.add(productId);
                        productRow.classList.add('selected');
                    } else {
                        selectedProducts.delete(productId);
                        productRow.classList.remove('selected');
                    }

                    updateBulkActionsVisibility();
                    updateSelectAllCheckbox();
                });
            });

            // Select all checkbox
            document.getElementById('selectAllProducts').addEventListener('change', function() {
                const isChecked = this.checked;
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const productId = checkbox.value;
                    const productRow = checkbox.closest('.product-row');

                    if (isChecked) {
                        selectedProducts.add(productId);
                        productRow.classList.add('selected');
                    } else {
                        selectedProducts.clear();
                        productRow.classList.remove('selected');
                    }
                });

                updateBulkActionsVisibility();
            });

            // Select active only
            document.getElementById('selectActive').addEventListener('click', function() {
                clearSelection();
                document.querySelectorAll('.product-row').forEach(row => {
                    const statusBadge = row.querySelector('.badge');
                    if (statusBadge && statusBadge.textContent.trim().toLowerCase() === 'active') {
                        const checkbox = row.querySelector('.product-checkbox');
                        if (checkbox) {
                            checkbox.checked = true;
                            selectedProducts.add(checkbox.value);
                            row.classList.add('selected');
                        }
                    }
                });
                updateBulkActionsVisibility();
                updateSelectAllCheckbox();
            });

            // Select inactive only
            document.getElementById('selectInactive').addEventListener('click', function() {
                clearSelection();
                document.querySelectorAll('.product-row').forEach(row => {
                    const statusBadge = row.querySelector('.badge');
                    if (statusBadge && statusBadge.textContent.trim().toLowerCase() === 'inactive') {
                        const checkbox = row.querySelector('.product-checkbox');
                        if (checkbox) {
                            checkbox.checked = true;
                            selectedProducts.add(checkbox.value);
                            row.classList.add('selected');
                        }
                    }
                });
                updateBulkActionsVisibility();
                updateSelectAllCheckbox();
            });

            // Clear selection
            document.getElementById('clearSelection').addEventListener('click', function() {
                clearSelection();
                updateBulkActionsVisibility();
            });

            // Utility functions
            function updateBulkActionsVisibility() {
                if (selectedProducts.size > 0) {
                    bulkActions.style.display = 'block';
                    selectedCount.textContent = selectedProducts.size;
                    selectedText.textContent = `${selectedProducts.size} product${selectedProducts.size > 1 ? 's' : ''} selected`;
                } else {
                    bulkActions.style.display = 'none';
                }
            }

            function updateSelectAllCheckbox() {
                const totalCheckboxes = document.querySelectorAll('.product-checkbox').length;
                const checkedCheckboxes = document.querySelectorAll('.product-checkbox:checked').length;
                const selectAllCheckbox = document.getElementById('selectAllProducts');

                selectAllCheckbox.checked = checkedCheckboxes === totalCheckboxes && totalCheckboxes > 0;
                selectAllCheckbox.indeterminate = checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes;
            }

            function clearSelection() {
                selectedProducts.clear();
                document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                });
                document.querySelectorAll('.product-row').forEach(row => {
                    row.classList.remove('selected');
                });
                document.getElementById('selectAllProducts').checked = false;
                document.getElementById('selectAllProducts').indeterminate = false;
            }

            // Initialize
            updateBulkActionsVisibility();
            updateSelectAllCheckbox();
        });
    </script>
</body>
</html>
