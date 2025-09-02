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

// Check if user has permission to view product families
if (!hasPermission('manage_boms', $permissions) && !hasPermission('view_boms', $permissions)) {
    header("Location: families.php");
    exit();
}

// Get family ID from URL
$family_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$family_id) {
    header("Location: families.php");
    exit();
}

// Get family data
$stmt = $conn->prepare("SELECT * FROM product_families WHERE id = :id");
$stmt->bindParam(':id', $family_id);
$stmt->execute();
$family = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$family) {
    $_SESSION['error'] = 'Product family not found.';
    header("Location: families.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get family statistics
$stmt = $conn->prepare("
    SELECT
        COUNT(p.id) as product_count,
        COALESCE(SUM(p.quantity), 0) as total_inventory,
        COALESCE(SUM(p.price * p.quantity), 0) as total_value,
        COALESCE(AVG(p.price), 0) as avg_price,
        COALESCE(MIN(p.price), 0) as min_price,
        COALESCE(MAX(p.price), 0) as max_price,
        COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_products,
        COUNT(CASE WHEN p.quantity <= 10 AND p.quantity > 0 THEN 1 END) as low_stock_products,
        COUNT(CASE WHEN p.quantity = 0 THEN 1 END) as out_of_stock_products
    FROM products p
    WHERE p.product_family_id = :family_id
");
$stmt->bindParam(':family_id', $family_id);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get products in this family
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql = "
    SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name,
           s.is_active as supplier_active, s.supplier_block_note
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.product_family_id = :family_id
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
$stmt->bindParam(':family_id', $family_id);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total product count for pagination
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM products WHERE product_family_id = :family_id");
$stmt->bindParam(':family_id', $family_id);
$stmt->execute();
$total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_products / $per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($family['name']); ?> - Product Family - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/families.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'families';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($family['name']); ?></h1>
                    <div class="header-subtitle">Product Family Details & Management</div>
                </div>
                <div class="header-actions">
                    <a href="families.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i>
                        Back to Families
                    </a>
                    <?php if (hasPermission('manage_boms', $permissions)): ?>
                    <a href="edit.php?id=<?php echo $family_id; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i>
                        Edit Family
                    </a>
                    <?php endif; ?>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Family Overview -->
            <div class="overview-section">
                <div class="overview-card">
                    <div class="overview-header">
                        <div class="family-icon-large">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                        <div class="family-info">
                            <h2><?php echo htmlspecialchars($family['name']); ?></h2>
                            <div class="family-meta">
                                <span class="badge badge-info"><?php echo htmlspecialchars($family['base_unit']); ?></span>
                                <span class="badge badge-secondary"><?php echo ucwords(str_replace('_', ' ', $family['default_pricing_strategy'])); ?></span>
                                <span class="badge <?php echo $family['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                    <?php echo ucfirst($family['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($family['description'])): ?>
                    <div class="family-description">
                        <p><?php echo nl2br(htmlspecialchars($family['description'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="family-details">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>Created</label>
                                <div><?php echo date('M j, Y g:i A', strtotime($family['created_at'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Last Updated</label>
                                <div><?php echo date('M j, Y g:i A', strtotime($family['updated_at'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Family ID</label>
                                <div><?php echo $family['id']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-box"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['product_count']); ?></div>
                    <div class="stat-label">Total Products</div>
                    <div class="stat-subtext"><?php echo number_format($stats['active_products']); ?> active</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_inventory']); ?></div>
                    <div class="stat-label">Total Inventory</div>
                    <div class="stat-subtext"><?php echo $stats['low_stock_products']; ?> low stock</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value currency"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['total_value'], 2); ?></div>
                    <div class="stat-label">Inventory Value</div>
                    <div class="stat-subtext">Avg: <?php echo $settings['currency_symbol']; ?> <?php echo number_format($stats['avg_price'], 2); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['out_of_stock_products']; ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <div class="stat-subtext"><?php echo $stats['low_stock_products']; ?> low stock</div>
                </div>
            </div>

            <!-- Products in Family -->
            <div class="products-section">
                <div class="section-header">
                    <h3><i class="bi bi-list me-2"></i>Products in This Family</h3>
                    <div class="section-actions">
                        <span class="text-muted">Showing <?php echo count($products); ?> of <?php echo $total_products; ?> products</span>
                    </div>
                </div>

                <?php if (empty($products)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-box"></i>
                    </div>
                    <h4>No Products Yet</h4>
                    <p>This product family doesn't have any products assigned to it yet.</p>
                    <?php if (hasPermission('manage_products', $permissions)): ?>
                    <a href="../products/add.php?family_id=<?php echo $family_id; ?>" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add First Product
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="products-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="product-image-placeholder me-3">
                                            <i class="bi bi-image"></i>
                                        </div>
                                        <div>
                                            <div class="font-weight-bold">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                                <?php if ($product['supplier_id'] && $product['supplier_active'] == 0): ?>
                                                <span class="badge badge-warning ms-2">Supplier Blocked</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">ID: <?php echo $product['id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                <td class="currency">
                                    <?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?>
                                </td>
                                <td>
                                    <?php echo number_format($product['quantity']); ?>
                                    <?php if ($product['quantity'] == 0): ?>
                                        <span class="badge badge-danger ms-1">Out of Stock</span>
                                    <?php elseif ($product['quantity'] <= 10): ?>
                                        <span class="badge badge-warning ms-1">Low Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $product['status'] === 'active' ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="../products/view.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-secondary btn-sm" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (hasPermission('manage_products', $permissions)): ?>
                                        <a href="../products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">
                            Showing <?php echo number_format($offset + 1); ?> to <?php echo number_format(min($offset + $per_page, $total_products)); ?>
                            of <?php echo number_format($total_products); ?> products
                        </div>
                        <nav aria-label="Products pagination">
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $family_id; ?>&page=<?php echo $page - 1; ?>">
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
                                    <a class="page-link" href="?id=<?php echo $family_id; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $family_id; ?>&page=<?php echo $page + 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/families.js"></script>

    <style>
        .overview-section {
            margin-bottom: 2rem;
        }

        .overview-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }

        .overview-header {
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .family-icon-large {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        .family-info h2 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
        }

        .family-meta {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .family-description {
            padding: 0 2rem;
            margin-bottom: 1.5rem;
        }

        .family-description p {
            margin: 0;
            color: #6b7280;
            line-height: 1.6;
        }

        .family-details {
            padding: 0 2rem 2rem 2rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .detail-item label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            display: block;
            margin-bottom: 0.25rem;
        }

        .detail-item div {
            font-size: 0.875rem;
            color: #374151;
        }

        .stat-subtext {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .products-section {
            margin-top: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
        }

        .section-actions {
            color: #6b7280;
            font-size: 0.875rem;
        }

        .empty-state {
            background: white;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .empty-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 2rem;
            margin: 0 auto 1rem auto;
        }

        .empty-state h4 {
            margin: 0 0 0.5rem 0;
            color: #1f2937;
        }

        .empty-state p {
            margin: 0 0 1.5rem 0;
            color: #6b7280;
        }

        .products-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .product-image-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 6px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
        }

        @media (max-width: 768px) {
            .overview-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .family-info h2 {
                font-size: 1.5rem;
            }

            .family-meta {
                justify-content: center;
            }

            .section-header {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }

            .pagination-wrapper {
                flex-direction: column;
                gap: 1rem;
                align-items: center;
            }
        }
    </style>
</body>
</html>
