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
if (!hasPermission('manage_inventory', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=access_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default settings if not set
$defaults = [
    'currency_symbol' => 'KES',
    'currency_position' => 'before',
    'currency_decimal_places' => '2'
];

foreach($defaults as $key => $value) {
    if(!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Helper function for currency formatting using admin settings
function formatCurrencyWithSettings($amount, $settings) {
    $symbol = $settings['currency_symbol'] ?? 'KES';
    $position = $settings['currency_position'] ?? 'before';
    $decimals = (int)($settings['currency_decimal_places'] ?? 2);

    $formatted_amount = number_format((float)$amount, $decimals);

    if ($position === 'after') {
        return $formatted_amount . ' ' . $symbol;
    } else {
        return $symbol . ' ' . $formatted_amount;
    }
}

// Handle bulk operations
if ($_POST && isset($_POST['bulk_action'])) {
    $selected_products = $_POST['selected_products'] ?? [];
    $label_quantities = $_POST['label_quantities'] ?? [];
    $action = $_POST['bulk_action'];

    if (!empty($selected_products)) {
        // Create a combined parameter with products and their quantities
        $products_with_quantities = [];
        foreach ($selected_products as $product_id) {
            $quantity = isset($label_quantities[$product_id]) ? (int)$label_quantities[$product_id] : 1;
            $products_with_quantities[$product_id] = max(1, $quantity);
        }

        $products_param = urlencode(implode(',', $selected_products));
        $quantities_param = urlencode(json_encode($products_with_quantities));

        switch ($action) {
            case 'generate_labels':
                // Generate shelf labels for selected products with custom quantities
                header("Location: generate_labels.php?products={$products_param}&quantities={$quantities_param}");
                exit();
                break;

            case 'print_labels':
                // Print shelf labels for selected products with custom quantities
                header("Location: print_labels.php?products={$products_param}&quantities={$quantities_param}");
                exit();
                break;

            case 'export_labels':
                // Export shelf labels for selected products with custom quantities
                header("Location: export_labels.php?products={$products_param}&quantities={$quantities_param}");
                exit();
                break;
        }
    }
}

// Get products for shelf labels
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$brand_filter = $_GET['brand'] ?? '';

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_filter) {
    $where_conditions[] = "p.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($brand_filter) {
    $where_conditions[] = "p.brand_id = :brand_id";
    $params[':brand_id'] = $brand_filter;
}

$where_clause = implode(" AND ", $where_conditions);

$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name,
           CASE
               WHEN p.sale_price IS NOT NULL
                    AND (p.sale_start_date IS NULL OR p.sale_start_date <= NOW())
                    AND (p.sale_end_date IS NULL OR p.sale_end_date >= NOW())
               THEN 1 ELSE 0 END as is_on_sale
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE $where_clause
    ORDER BY p.name ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get brands for filter
$brands = $conn->query("SELECT id, name FROM brands ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shelf Labels - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/shelf_label.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .bulk-actions {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .bulk-actions .form-check {
            margin-bottom: 1rem;
        }

        .bulk-actions .btn-group {
            gap: 0.5rem;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .product-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: #f8fafc;
        }

        .product-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }

        .product-info h5 {
            margin-bottom: 0.25rem;
            color: #1e293b;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .product-meta {
            color: #1e293b;
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .product-price {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }



        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .select-all-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .select-all-container .form-check {
            margin-bottom: 0;
        }

        .select-all-container .btn {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .quantity-input-container {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: rgba(99, 102, 241, 0.05);
            border-radius: 6px;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .quantity-input-container .form-label {
            margin-bottom: 0.25rem;
            font-size: 0.75rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .quantity-input-container .form-control {
            text-align: center;
            font-weight: 600;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .quantity-input-container .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }

        .barcode-preview {
            margin-top: 0.5rem;
            padding: 0.25rem;
            background: rgba(99, 102, 241, 0.05);
            border-radius: 4px;
            text-align: center;
        }

        .mini-barcode {
            max-width: 100%;
            height: 15px;
            margin-top: 0.25rem;
        }

        .price-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.15rem;
        }

        .original-price {
            text-decoration: line-through;
            color: #94a3b8;
            font-size: 0.9rem;
            font-weight: normal;
        }

        .sale-price {
            color: #dc2626;
            font-weight: 900;
            font-size: 1.2rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .product-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 0.75rem;
            }

            .bulk-actions .btn-group {
                flex-direction: column;
                width: 100%;
            }

            .bulk-actions .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .select-all-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .product-image {
                height: 100px;
                margin-bottom: 0.5rem;
            }

            .product-card {
                padding: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .product-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .product-card {
                padding: 0.75rem;
            }

            .product-image {
                height: 90px;
                margin-bottom: 0.5rem;
            }

            .quantity-input-container {
                margin-top: 0.25rem;
                padding: 0.25rem;
            }

            .barcode-preview {
                margin-top: 0.25rem;
                padding: 0.15rem;
            }

            .mini-barcode {
                height: 12px;
            }

            .bulk-actions {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Shelf Labels</h1>
                    <p class="header-subtitle">Generate and manage product shelf labels</p>
                </div>
                <div class="header-actions">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($username, 0, 2)); ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($username); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($role_name); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search Products</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by name, SKU, or barcode">
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
                        <label for="brand" class="form-label">Brand</label>
                        <select class="form-select" id="brand" name="brand">
                            <option value="">All Brands</option>
                            <?php foreach ($brands as $brand): ?>
                            <option value="<?php echo $brand['id']; ?>" 
                                    <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($brand['name']); ?>
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

            <!-- Bulk Operations -->
            <div class="bulk-actions">
                <form method="POST" id="bulkForm">
                    <div class="select-all-container">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAll">
                            <label class="form-check-label" for="selectAll">
                                <strong>Select All Products</strong>
                            </label>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                            Clear Selection
                        </button>
                        <span class="text-muted" id="selectionCount">0 products selected</span>
                    </div>

                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllVisible">
                                <label class="form-check-label" for="selectAllVisible">
                                    Select All Visible Products (<?php echo count($products); ?>)
                                </label>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    <span id="labelInfo">Select products to see label count</span>
                                </small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="btn-group float-end">
                                <button type="submit" name="bulk_action" value="generate_labels" 
                                        class="btn btn-primary" id="generateBtn" disabled>
                                    <i class="bi bi-tags"></i> Generate Labels
                                </button>
                                <button type="submit" name="bulk_action" value="print_labels" 
                                        class="btn btn-success" id="printBtn" disabled>
                                    <i class="bi bi-printer"></i> Print Labels
                                </button>
                                <button type="submit" name="bulk_action" value="export_labels" 
                                        class="btn btn-info" id="exportBtn" disabled>
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Products Grid -->
                            <div class="product-grid">
                    <?php foreach ($products as $product): ?>
                    <div class="product-card" data-product-id="<?php echo $product['id']; ?>" data-quantity="<?php echo (int)($product['quantity'] ?? 0); ?>">
                        <div class="form-check">
                            <input class="form-check-input product-checkbox" type="checkbox"
                                   name="selected_products[]" value="<?php echo $product['id']; ?>"
                                   form="bulkForm">
                        </div>

                        <!-- Quantity Input (shown when selected) -->
                        <div class="quantity-input-container" style="display: none;">
                            <label class="form-label small">Labels to Print:</label>
                            <input type="number" class="form-control form-control-sm quantity-input"
                                   name="label_quantities[<?php echo $product['id']; ?>]"
                                   value="1" min="1" max="999"
                                   form="bulkForm">
                        </div>
                    
                    <?php if (isset($product['image']) && !empty($product['image'])): ?>
                    <img src="../storage/products/<?php echo htmlspecialchars($product['image']); ?>"
                         alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-image">
                    <?php else: ?>
                    <div class="product-image bg-light d-flex align-items-center justify-content-center">
                        <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <?php endif; ?>

                    <div class="product-info">
                        <h5><strong><?php echo htmlspecialchars($product['name']); ?></strong></h5>
                        <div class="product-meta">
                            <div><strong>SKU:</strong> <strong><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></strong></div>
                            <div><strong>Category:</strong> <strong><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></strong></div>
                            <?php if (!empty($product['sku'])): ?>
                            <div class="barcode-preview">
                                <small><strong>Barcode:</strong></small><br>
                                <img src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($product['sku']); ?>&code=Code128&dpi=96&dataseparator=" alt="Barcode Preview" class="mini-barcode">
                            </div>
                            <?php endif; ?>
                        </div>
                        
                                                                        <div class="product-price">
                            <?php if ($product['is_on_sale'] && !empty($product['sale_price'])): ?>
                                <div class="price-container">
                                    <span class="original-price"><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></span>
                                    <span class="sale-price"><?php echo formatCurrencyWithSettings($product['sale_price'], $settings); ?></span>
                                </div>
                            <?php else: ?>
                                <strong><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></strong>
                            <?php endif; ?>
                        </div>
                        

                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($products)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No products found</h4>
                <p class="text-muted">Try adjusting your search criteria or filters.</p>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Select All functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                updateProductCardSelection(checkbox);
            });
            updateSelectionCount();
            updateBulkButtons();
        });

        document.getElementById('selectAllVisible').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.product-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                updateProductCardSelection(checkbox);
            });
            updateSelectionCount();
            updateBulkButtons();
        });

        // Individual checkbox selection
        document.querySelectorAll('.product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateProductCardSelection(this);
                toggleQuantityInput(this);
                updateSelectionCount();
                updateBulkButtons();
                updateSelectAllStates();
            });
        });

        // Quantity input changes
        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('input', function() {
                updateSelectionCount();
            });
        });

        function updateProductCardSelection(checkbox) {
            const productCard = checkbox.closest('.product-card');
            if (checkbox.checked) {
                productCard.classList.add('selected');
            } else {
                productCard.classList.remove('selected');
            }
        }

        function toggleQuantityInput(checkbox) {
            const productCard = checkbox.closest('.product-card');
            const quantityContainer = productCard.querySelector('.quantity-input-container');
            if (checkbox.checked) {
                quantityContainer.style.display = 'block';
            } else {
                quantityContainer.style.display = 'none';
            }
        }

        function updateSelectionCount() {
            const selectedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
            const selectedCount = selectedCheckboxes.length;
            let totalLabels = 0;

            selectedCheckboxes.forEach(checkbox => {
                const productCard = checkbox.closest('.product-card');
                const quantityInput = productCard.querySelector('.quantity-input');
                const customQuantity = parseInt(quantityInput.value) || 1;
                totalLabels += Math.max(1, customQuantity);
            });

            document.getElementById('selectionCount').textContent = `${selectedCount} products selected`;
            document.getElementById('labelInfo').textContent = selectedCount > 0
                ? `Selected: ${selectedCount} products → ${totalLabels} labels total`
                : 'Select products to see label count';
        }

        function updateBulkButtons() {
            const selectedCount = document.querySelectorAll('.product-checkbox:checked').length;
            const buttons = ['generateBtn', 'printBtn', 'exportBtn'];
            
            buttons.forEach(btnId => {
                const btn = document.getElementById(btnId);
                btn.disabled = selectedCount === 0;
            });
        }

        function updateSelectAllStates() {
            const totalCheckboxes = document.querySelectorAll('.product-checkbox').length;
            const checkedCheckboxes = document.querySelectorAll('.product-checkbox:checked').length;
            
            document.getElementById('selectAll').checked = checkedCheckboxes === totalCheckboxes;
            document.getElementById('selectAllVisible').checked = checkedCheckboxes === totalCheckboxes;
        }

        function clearSelection() {
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                updateProductCardSelection(checkbox);
                toggleQuantityInput(checkbox);
            });
            document.getElementById('selectAll').checked = false;
            document.getElementById('selectAllVisible').checked = false;
            updateSelectionCount();
            updateBulkButtons();
        }

        // Initialize
        updateSelectionCount();
        updateBulkButtons();
    </script>
</body>
</html>
