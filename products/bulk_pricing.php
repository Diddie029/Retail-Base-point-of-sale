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

// Get reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle pricing update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_pricing'])) {
    $filters = $_POST['filters'] ?? [];
    $pricing = $_POST['pricing'] ?? [];
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];

    try {
        $conn->beginTransaction();

        // Build WHERE clause based on filters
        $where_conditions = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where_conditions[] = "category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        if (!empty($filters['brand_id'])) {
            $where_conditions[] = "brand_id = :brand_id";
            $params[':brand_id'] = $filters['brand_id'];
        }

        if (!empty($filters['price_range_min'])) {
            $where_conditions[] = "price >= :price_min";
            $params[':price_min'] = $filters['price_range_min'];
        }

        if (!empty($filters['price_range_max'])) {
            $where_conditions[] = "price <= :price_max";
            $params[':price_max'] = $filters['price_range_max'];
        }

        if (!empty($filters['status'])) {
            $where_conditions[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get products that match the criteria
        $select_sql = "SELECT id, name, price, sale_price FROM products $where_clause";
        $select_stmt = $conn->prepare($select_sql);
        foreach ($params as $key => $value) {
            $select_stmt->bindValue($key, $value);
        }
        $select_stmt->execute();
        $products = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            $errors[] = "No products found matching the specified criteria.";
        } else {
            $pricing_type = $pricing['type'] ?? '';
            $pricing_value = (float)($pricing['value'] ?? 0);
            
            foreach ($products as $product) {
                $new_price = $product['price'];
                $new_sale_price = $product['sale_price'];
                
                // Calculate new regular price
                switch ($pricing_type) {
                    case 'percentage_increase':
                        $new_price = $product['price'] * (1 + $pricing_value / 100);
                        break;
                    case 'percentage_decrease':
                        $new_price = $product['price'] * (1 - $pricing_value / 100);
                        break;
                    case 'fixed_increase':
                        $new_price = $product['price'] + $pricing_value;
                        break;
                    case 'fixed_decrease':
                        $new_price = $product['price'] - $pricing_value;
                        break;
                    case 'set_price':
                        $new_price = $pricing_value;
                        break;
                }

                // Handle sale price if specified
                if (!empty($pricing['sale_type']) && !empty($pricing['sale_value'])) {
                    $sale_type = $pricing['sale_type'];
                    $sale_value = (float)$pricing['sale_value'];
                    
                    switch ($sale_type) {
                        case 'percentage_off':
                            $new_sale_price = $new_price * (1 - $sale_value / 100);
                            break;
                        case 'fixed_discount':
                            $new_sale_price = $new_price - $sale_value;
                            break;
                        case 'set_sale_price':
                            $new_sale_price = $sale_value;
                            break;
                        case 'clear_sale':
                            $new_sale_price = null;
                            break;
                    }
                }

                // Ensure prices are not negative
                $new_price = max(0, $new_price);
                if ($new_sale_price !== null) {
                    $new_sale_price = max(0, $new_sale_price);
                }

                // Round to 2 decimal places
                $new_price = round($new_price, 2);
                if ($new_sale_price !== null) {
                    $new_sale_price = round($new_sale_price, 2);
                }

                // Update the product
                $update_sql = "UPDATE products SET price = :price, sale_price = :sale_price, updated_at = NOW() WHERE id = :id";
                $update_stmt = $conn->prepare($update_sql);
                
                if ($update_stmt->execute([
                    ':price' => $new_price,
                    ':sale_price' => $new_sale_price,
                    ':id' => $product['id']
                ])) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }

        $conn->commit();

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully updated pricing for $success_count products.";
            
            // Log the bulk operation
            try {
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                    VALUES (:user_id, :username, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':username' => $username,
                    ':action' => 'bulk_pricing_update',
                    ':details' => "Updated pricing for $success_count products using $pricing_type"
                ]);
            } catch (Exception $e) {
                // Log table might not exist, ignore
            }
        } else {
            $_SESSION['error'] = "No products were updated. Please check your criteria and try again.";
        }

    } catch (Exception $e) {
        $conn->rollBack();
        $errors[] = "Database error: " . $e->getMessage();
        $_SESSION['error'] = "An error occurred during the bulk pricing operation.";
    }

    header("Location: bulk_pricing.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Pricing Changes - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Main layout alignment */
        .main-content {
            margin-left: 250px;
            padding: 0;
            min-height: 100vh;
        }
        
        .content {
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .pricing-section {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-section {
            background-color: #f8f9fa;
        }
        .pricing-rules-section {
            background-color: #fff3cd;
        }
        .preview-section {
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        .pricing-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            background-color: #ffffff;
        }
        .pricing-card.selected {
            border-color: #007bff;
            background-color: #e3f2fd;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Sidebar -->
    <?php
    $current_page = 'bulk_operations';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-dollar-sign me-2"></i>Bulk Pricing Changes</h2>
                    <a href="bulk_operations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Bulk Operations
                    </a>
                </div>

                <!-- Display messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="pricingForm">
                    <!-- Step 1: Select Products -->
                    <div class="pricing-section filter-section">
                        <h4><i class="fas fa-filter me-2"></i>Step 1: Select Products</h4>
                        <p class="text-muted">Choose which products to apply pricing changes to:</p>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select name="filters[category_id]" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Brand</label>
                                <select name="filters[brand_id]" class="form-select">
                                    <option value="">All Brands</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo $brand['id']; ?>">
                                            <?php echo htmlspecialchars($brand['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="filters[status]" class="form-select">
                                    <option value="">Any Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Current Price Range</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="filters[price_range_min]" class="form-control" placeholder="Min Price" step="0.01">
                                    <span class="input-group-text">to</span>
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="filters[price_range_max]" class="form-control" placeholder="Max Price" step="0.01">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-outline-primary" style="margin-top: 32px;" onclick="previewProducts()">
                                    <i class="fas fa-eye me-2"></i>Preview Selected Products
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Pricing Rules -->
                    <div class="pricing-section pricing-rules-section">
                        <h4><i class="fas fa-calculator me-2"></i>Step 2: Set Pricing Rules</h4>
                        <p class="text-muted">Choose how you want to adjust the prices:</p>

                        <!-- Regular Price Changes -->
                        <div class="row">
                            <div class="col-md-12">
                                <h5>Regular Price Adjustment</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="pricing-card" onclick="selectPricingType('percentage_increase')">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="pricing[type]" value="percentage_increase" id="percentage_increase">
                                                <label class="form-check-label" for="percentage_increase">
                                                    <strong>Percentage Increase</strong>
                                                </label>
                                            </div>
                                            <p class="mb-0 text-muted">Increase all prices by a percentage</p>
                                            <div class="mt-2">
                                                <div class="input-group">
                                                    <input type="number" class="form-control" placeholder="Enter percentage" step="0.01" min="0">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="pricing-card" onclick="selectPricingType('percentage_decrease')">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="pricing[type]" value="percentage_decrease" id="percentage_decrease">
                                                <label class="form-check-label" for="percentage_decrease">
                                                    <strong>Percentage Decrease</strong>
                                                </label>
                                            </div>
                                            <p class="mb-0 text-muted">Decrease all prices by a percentage</p>
                                            <div class="mt-2">
                                                <div class="input-group">
                                                    <input type="number" class="form-control" placeholder="Enter percentage" step="0.01" min="0" max="100">
                                                    <span class="input-group-text">%</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="pricing-card" onclick="selectPricingType('fixed_increase')">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="pricing[type]" value="fixed_increase" id="fixed_increase">
                                                <label class="form-check-label" for="fixed_increase">
                                                    <strong>Fixed Amount Increase</strong>
                                                </label>
                                            </div>
                                            <p class="mb-0 text-muted">Add a fixed amount to all prices</p>
                                            <div class="mt-2">
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" placeholder="Enter amount" step="0.01" min="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="pricing-card" onclick="selectPricingType('fixed_decrease')">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="pricing[type]" value="fixed_decrease" id="fixed_decrease">
                                                <label class="form-check-label" for="fixed_decrease">
                                                    <strong>Fixed Amount Decrease</strong>
                                                </label>
                                            </div>
                                            <p class="mb-0 text-muted">Subtract a fixed amount from all prices</p>
                                            <div class="mt-2">
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" placeholder="Enter amount" step="0.01" min="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <div class="pricing-card" onclick="selectPricingType('set_price')">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="pricing[type]" value="set_price" id="set_price">
                                                <label class="form-check-label" for="set_price">
                                                    <strong>Set Fixed Price</strong>
                                                </label>
                                            </div>
                                            <p class="mb-0 text-muted">Set all products to the same price</p>
                                            <div class="mt-2">
                                                <div class="input-group">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" class="form-control" placeholder="Enter new price" step="0.01" min="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="pricing[value]" id="pricingValue">
                            </div>
                        </div>

                        <!-- Sale Price Management -->
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <h5>Sale Price Management (Optional)</h5>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pricing[sale_type]" value="percentage_off" id="sale_percentage_off">
                                            <label class="form-check-label" for="sale_percentage_off">
                                                Percentage Off
                                            </label>
                                        </div>
                                        <div class="input-group mt-1">
                                            <input type="number" name="pricing[sale_percentage]" class="form-control form-control-sm" placeholder="%" step="0.01" min="0" max="100">
                                            <span class="input-group-text input-group-text-sm">%</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pricing[sale_type]" value="fixed_discount" id="sale_fixed_discount">
                                            <label class="form-check-label" for="sale_fixed_discount">
                                                Fixed Discount
                                            </label>
                                        </div>
                                        <div class="input-group mt-1">
                                            <span class="input-group-text input-group-text-sm">$</span>
                                            <input type="number" name="pricing[sale_fixed]" class="form-control form-control-sm" placeholder="Amount" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pricing[sale_type]" value="set_sale_price" id="sale_set_price">
                                            <label class="form-check-label" for="sale_set_price">
                                                Set Sale Price
                                            </label>
                                        </div>
                                        <div class="input-group mt-1">
                                            <span class="input-group-text input-group-text-sm">$</span>
                                            <input type="number" name="pricing[sale_set]" class="form-control form-control-sm" placeholder="Sale Price" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="pricing[sale_type]" value="clear_sale" id="sale_clear">
                                            <label class="form-check-label" for="sale_clear">
                                                Clear Sale Prices
                                            </label>
                                        </div>
                                        <small class="text-muted">Remove all sale prices</small>
                                    </div>
                                </div>
                                <input type="hidden" name="pricing[sale_value]" id="saleValue">
                            </div>
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="pricing-section preview-section" id="previewSection" style="display: none;">
                        <h4><i class="fas fa-eye me-2"></i>Preview: Products and Pricing Changes</h4>
                        <div id="previewContent">
                            <!-- Preview content will be loaded here -->
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="text-center">
                        <button type="submit" name="apply_pricing" class="btn btn-warning btn-lg" onclick="return confirmPricing()">
                            <i class="fas fa-dollar-sign me-2"></i>Apply Pricing Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function selectPricingType(type) {
            // Remove selected class from all pricing cards
            document.querySelectorAll('.pricing-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            event.currentTarget.classList.add('selected');
            
            // Select the radio button
            document.getElementById(type).checked = true;
            
            // Update hidden value field
            const input = event.currentTarget.querySelector('input[type="number"]');
            if (input) {
                input.addEventListener('input', function() {
                    document.getElementById('pricingValue').value = this.value;
                });
                document.getElementById('pricingValue').value = input.value;
            }
        }

        function previewProducts() {
            const formData = new FormData(document.getElementById('pricingForm'));
            formData.append('action', 'preview');

            fetch('bulk_pricing_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('previewContent').innerHTML = data.html;
                    document.getElementById('previewSection').style.display = 'block';
                    document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while previewing pricing changes.');
            });
        }

        function confirmPricing() {
            const previewSection = document.getElementById('previewSection');
            if (previewSection.style.display === 'none') {
                alert('Please preview the pricing changes first.');
                return false;
            }

            const pricingType = document.querySelector('input[name="pricing[type]"]:checked');
            if (!pricingType) {
                alert('Please select a pricing rule.');
                return false;
            }

            return confirm('Are you sure you want to apply these pricing changes? This action cannot be undone.');
        }

        // Handle sale value updates
        document.querySelectorAll('input[name="pricing[sale_percentage]"], input[name="pricing[sale_fixed]"], input[name="pricing[sale_set]"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.name === 'pricing[sale_percentage]' && document.getElementById('sale_percentage_off').checked) {
                    document.getElementById('saleValue').value = this.value;
                } else if (this.name === 'pricing[sale_fixed]' && document.getElementById('sale_fixed_discount').checked) {
                    document.getElementById('saleValue').value = this.value;
                } else if (this.name === 'pricing[sale_set]' && document.getElementById('sale_set_price').checked) {
                    document.getElementById('saleValue').value = this.value;
                }
            });
        });

        // Handle sale type selection
        document.querySelectorAll('input[name="pricing[sale_type]"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const correspondingInput = this.parentElement.nextElementSibling.querySelector('input[type="number"]');
                if (correspondingInput) {
                    document.getElementById('saleValue').value = correspondingInput.value;
                } else if (this.value === 'clear_sale') {
                    document.getElementById('saleValue').value = 'clear';
                }
            });
        });
    </script>
</body>
</html>
