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

// Get selected products and their custom quantities
$product_ids = $_GET['products'] ?? '';
$quantities_param = $_GET['quantities'] ?? '';
$custom_quantities = [];

if (!empty($quantities_param)) {
    $custom_quantities = json_decode(urldecode($quantities_param), true) ?? [];
}

if (empty($product_ids)) {
    header("Location: shelf_labels.php?error=no_products_selected");
    exit();
}

$product_ids_array = explode(',', $product_ids);
$placeholders = str_repeat('?,', count($product_ids_array) - 1) . '?';

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
    WHERE p.id IN ($placeholders)
    ORDER BY p.name ASC
");
$stmt->execute($product_ids_array);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    header("Location: shelf_labels.php?error=products_not_found");
    exit();
}

// Handle label generation
if ($_POST && isset($_POST['generate_labels'])) {
    $label_size = $_POST['label_size'] ?? 'standard';
    $include_barcode = isset($_POST['include_barcode']);
    $include_image = isset($_POST['include_image']);
    $custom_text = $_POST['custom_text'] ?? '';
    
    // Store generation settings in session for printing
    $_SESSION['label_settings'] = [
        'size' => $label_size,
        'include_barcode' => $include_barcode,
        'include_image' => $include_image,
        'custom_text' => $custom_text,
        'products' => $product_ids,
        'quantities' => $custom_quantities
    ];

    $quantities_param = urlencode(json_encode($custom_quantities));
    header("Location: print_labels.php?preview=1&products={$product_ids}&quantities={$quantities_param}");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Shelf Labels - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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

        .generation-form {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .label-preview {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .preview-label {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem;
            background: #f8fafc;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .preview-label.standard { min-height: 150px; }
        .preview-label.small { min-height: 120px; }
        .preview-label.large { min-height: 180px; }

        .label-header {
            text-align: center;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .label-header h6 {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 600;
            color: #1e293b;
        }

        .label-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .label-product-name {
            font-weight: 800;
            font-size: 0.8rem;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 0.1rem;
        }

        .label-details {
            font-size: 0.7rem;
            color: #1e293b;
            line-height: 1.1;
            font-weight: 700;
        }

        .label-price {
            font-weight: 900;
            font-size: 1rem;
            color: var(--primary-color);
            text-align: center;
            margin: 0.25rem 0;
        }

        .label-footer {
            text-align: center;
            font-size: 0.55rem;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 0.25rem;
            margin-top: 0.25rem;
        }

        .barcode-section {
            text-align: center;
            margin: 0.25rem 0;
            padding: 0.15rem 0;
        }

        .barcode-image {
            max-width: 100%;
            height: 20px;
            margin-bottom: 0.1rem;
        }

        .barcode-text {
            font-size: 0.55rem;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .price-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.1rem;
        }

        .original-price {
            text-decoration: line-through;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: normal;
        }

        .sale-price {
            color: #dc2626;
            font-weight: 900;
            font-size: 1rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h5 {
            color: #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .size-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .size-option {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .size-option:hover {
            border-color: var(--primary-color);
            background: #f8fafc;
        }

        .size-option.selected {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .size-option h6 {
            margin: 0;
            font-size: 0.875rem;
        }

        .size-option small {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .custom-text-area {
            min-height: 100px;
            font-family: 'Courier New', monospace;
        }

        .selected-products {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .product-count {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 1rem;
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
                    <h1>Generate Shelf Labels</h1>
                    <p class="header-subtitle">Customize and generate shelf labels for selected products</p>
                </div>
                <div class="header-actions">
                    <a href="shelf_labels.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Shelf Labels
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Selected Products Summary -->
            <div class="selected-products">
                <div class="product-count">
                    <?php echo count($products); ?> Products Selected
                </div>
                <div class="row">
                    <?php foreach (array_slice($products, 0, 6) as $product): ?>
                    <div class="col-md-2 col-sm-4 col-6 mb-2">
                        <div class="d-flex align-items-center">
                            <div class="me-2">
                                <?php if (isset($product['image']) && !empty($product['image'])): ?>
                                <img src="../storage/products/<?php echo htmlspecialchars($product['image']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                     style="width: 30px; height: 30px; object-fit: cover; border-radius: 4px;">
                                <?php else: ?>
                                <div style="width: 30px; height: 30px; background: #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-image text-muted" style="font-size: 0.875rem;"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size: 0.75rem;"><?php echo htmlspecialchars(substr($product['name'], 0, 20)); ?><?php echo strlen($product['name']) > 20 ? '...' : ''; ?></div>
                                <div class="text-muted" style="font-size: 0.625rem;"><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($products) > 6): ?>
                    <div class="col-md-2 col-sm-4 col-6 mb-2">
                        <div class="text-center text-muted">
                            <i class="bi bi-ellipsis-h" style="font-size: 1.5rem;"></i>
                            <div style="font-size: 0.75rem;">+<?php echo count($products) - 6; ?> more</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Label Generation Form -->
            <div class="generation-form">
                <form method="POST">
                    <div class="form-section">
                        <h5><i class="bi bi-rulers me-2"></i>Label Size & Format</h5>
                        <div class="size-options">
                            <div class="size-option" data-size="small">
                                <h6>Small</h6>
                                <small>1.2" x 0.6"</small>
                                <div class="mt-2">
                                    <input type="radio" name="label_size" value="small" id="size_small" class="form-check-input" checked>
                                    <label for="size_small" class="form-check-label">Select</label>
                                </div>
                            </div>
                            <div class="size-option" data-size="standard">
                                <h6>Standard</h6>
                                <small>1.5" x 0.8"</small>
                                <div class="mt-2">
                                    <input type="radio" name="label_size" value="standard" id="size_standard" class="form-check-input">
                                    <label for="size_standard" class="form-check-label">Select</label>
                                </div>
                            </div>
                            <div class="size-option" data-size="large">
                                <h6>Large</h6>
                                <small>2" x 1"</small>
                                <div class="mt-2">
                                    <input type="radio" name="label_size" value="large" id="size_large" class="form-check-input">
                                    <label for="size_large" class="form-check-label">Select</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5><i class="bi bi-gear me-2"></i>Label Options</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="include_barcode" id="include_barcode" checked>
                                    <label class="form-check-label" for="include_barcode">
                                        Include Product Barcode
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="include_image" id="include_image">
                                    <label class="form-check-label" for="include_image">
                                        Include Product Image
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="custom_text" class="form-label">Custom Text (Optional)</label>
                                <textarea class="form-control custom-text-area" name="custom_text" id="custom_text" 
                                          placeholder="Enter any additional text to include on labels..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="text-center">
                        <button type="submit" name="generate_labels" class="btn btn-primary btn-lg">
                            <i class="bi bi-tags me-2"></i>Generate Labels
                        </button>
                        <a href="shelf_labels.php" class="btn btn-outline-secondary btn-lg ms-2">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

            <!-- Label Preview -->
            <?php
            // Calculate total labels to be printed
            $total_preview_labels = 0;
            foreach ($products as $product) {
                $product_id = $product['id'];
                $quantity = isset($custom_quantities[$product_id])
                    ? max(1, (int)$custom_quantities[$product_id])
                    : 1; // Default to 1 label if no custom quantity specified
                $total_preview_labels += $quantity;
            }
            ?>
            <div class="label-preview">
                <h5><i class="bi bi-eye me-2"></i>Label Preview (<?php echo $total_preview_labels; ?> total labels)</h5>
                <p class="text-muted">Preview of how your labels will look with the selected settings. Each product will generate multiple labels based on the number of labels you specified to print.</p>
                
                <div class="preview-grid">
                    <?php foreach (array_slice($products, 0, 4) as $product): ?>
                    <div class="preview-label standard">
                        <div class="label-header">
                            <h6><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></h6>
                        </div>
                        
                        <div class="label-content">
                            <div class="label-product-name">
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            </div>
                            
                            <div class="label-details">
                                <div><strong>SKU:</strong> <strong><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></strong></div>
                                <div><strong>Category:</strong> <strong><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></strong></div>
                            </div>

                            <!-- Barcode Section -->
                            <?php if (!empty($product['sku'])): ?>
                            <div class="barcode-section">
                                <img src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($product['sku']); ?>&code=Code128&dpi=96&dataseparator=" alt="Barcode" class="barcode-image">
                                <div class="barcode-text"><small><?php echo htmlspecialchars($product['sku']); ?></small></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="label-price">
                            <?php if ($product['is_on_sale'] && !empty($product['sale_price'])): ?>
                                <div class="price-container">
                                    <span class="original-price"><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></span>
                                    <span class="sale-price"><?php echo formatCurrencyWithSettings($product['sale_price'], $settings); ?></span>
                                </div>
                            <?php else: ?>
                                <strong><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></strong>
                            <?php endif; ?>
                        </div>
                        
                        <div class="label-footer">
                            Generated on <?php echo date('M d, Y'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Size option selection
        document.querySelectorAll('.size-option').forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                document.querySelectorAll('.size-option').forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Check the radio button
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Update preview labels
                updatePreviewSize(this.dataset.size);
            });
        });

        function updatePreviewSize(size) {
            const previewLabels = document.querySelectorAll('.preview-label');
            previewLabels.forEach(label => {
                label.className = `preview-label ${size}`;
            });
        }

        // Initialize with standard size selected
        document.querySelector('.size-option[data-size="standard"]').classList.add('selected');
    </script>
</body>
</html>
