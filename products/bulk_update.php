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

// Check if user has permission to perform bulk operations on products
if (!hasPermission('bulk_edit_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mass_update'])) {
    $update_type = $_POST['update_type'] ?? '';
    $filters = $_POST['filters'] ?? [];
    $updates = $_POST['updates'] ?? [];
    
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

        if (!empty($filters['supplier_id'])) {
            $where_conditions[] = "supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }

        if (!empty($filters['status'])) {
            $where_conditions[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['stock_condition'])) {
            switch ($filters['stock_condition']) {
                case 'low_stock':
                    $where_conditions[] = "quantity <= 10";
                    break;
                case 'out_of_stock':
                    $where_conditions[] = "quantity = 0";
                    break;
                case 'in_stock':
                    $where_conditions[] = "quantity > 0";
                    break;
            }
        }

        if (!empty($filters['price_range_min'])) {
            $where_conditions[] = "price >= :price_min";
            $params[':price_min'] = $filters['price_range_min'];
        }

        if (!empty($filters['price_range_max'])) {
            $where_conditions[] = "price <= :price_max";
            $params[':price_max'] = $filters['price_range_max'];
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get products to update
        $select_sql = "SELECT id, name, price, sale_price FROM products $where_clause";
        $select_stmt = $conn->prepare($select_sql);
        foreach ($params as $key => $value) {
            $select_stmt->bindValue($key, $value);
        }
        $select_stmt->execute();
        $products_to_update = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products_to_update)) {
            $errors[] = "No products found matching the specified criteria.";
        } else {
            // Build UPDATE clause based on what needs to be updated
            $update_fields = [];
            $update_params = [];

            // Handle different update types
            if (!empty($updates['category_id'])) {
                $update_fields[] = "category_id = :new_category_id";
                $update_params[':new_category_id'] = $updates['category_id'];
            }

            if (!empty($updates['brand_id'])) {
                $update_fields[] = "brand_id = :new_brand_id";
                $update_params[':new_brand_id'] = $updates['brand_id'];
            }

            if (!empty($updates['supplier_id'])) {
                $update_fields[] = "supplier_id = :new_supplier_id";
                $update_params[':new_supplier_id'] = $updates['supplier_id'];
            }

            if (!empty($updates['status'])) {
                $update_fields[] = "status = :new_status";
                $update_params[':new_status'] = $updates['status'];
            }

            if (!empty($updates['tax_rate'])) {
                $update_fields[] = "tax_rate = :new_tax_rate";
                $update_params[':new_tax_rate'] = $updates['tax_rate'];
            }

            if (!empty($updates['description_append'])) {
                $update_fields[] = "description = CONCAT(COALESCE(description, ''), :description_append)";
                $update_params[':description_append'] = " " . $updates['description_append'];
            }

            if (!empty($updates['description_replace'])) {
                $update_fields[] = "description = :description_replace";
                $update_params[':description_replace'] = $updates['description_replace'];
            }

            // Add updated timestamp
            $update_fields[] = "updated_at = NOW()";

            if (!empty($update_fields)) {
                $update_sql = "UPDATE products SET " . implode(', ', $update_fields) . " $where_clause";
                $update_stmt = $conn->prepare($update_sql);
                
                // Bind filter parameters
                foreach ($params as $key => $value) {
                    $update_stmt->bindValue($key, $value);
                }
                
                // Bind update parameters
                foreach ($update_params as $key => $value) {
                    $update_stmt->bindValue($key, $value);
                }

                if ($update_stmt->execute()) {
                    $success_count = $update_stmt->rowCount();
                }
            }
        }

        $conn->commit();

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully updated $success_count products.";
            
            // Log the bulk operation
            try {
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                    VALUES (:user_id, :username, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':username' => $username,
                    ':action' => 'bulk_update_products',
                    ':details' => "Updated $success_count products"
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
        $_SESSION['error'] = "An error occurred during the bulk update operation.";
    }

    header("Location: bulk_update.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mass Product Updates - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Main layout alignment */
        .main-content {
            margin-left: 250px;
            padding: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .content {
            padding: 20px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Modern section styling */
        .filter-section, .update-section, .preview-section {
            border: none;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .filter-section {
            background: rgba(255, 255, 255, 0.95);
        }
        
        .filter-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .update-section {
            background: rgba(255, 255, 255, 0.95);
        }
        
        .update-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ffecd2 0%, #fcb69f 100%);
        }
        
        .preview-section {
            background: rgba(209, 236, 241, 0.95);
            border: 1px solid #bee5eb;
        }
        
        .preview-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
        }
        
        /* Enhanced form controls */
        .form-select, .form-control {
            border-radius: 12px;
            border: 2px solid #e3f2fd;
            padding: 12px 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 0.2rem rgba(79, 172, 254, 0.15);
            background: rgba(255, 255, 255, 1);
        }
        
        /* Section headers */
        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(79, 172, 254, 0.1);
        }
        
        .section-header h4 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .section-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.5rem;
        }
        
        .filter-icon {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .update-icon {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #333;
        }
        
        /* Enhanced buttons */
        .btn-modern {
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            text-transform: none;
        }
        
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-outline-primary {
            border: 2px solid #667eea;
            color: #667eea;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .btn-outline-primary:hover {
            background: #667eea;
            border-color: #667eea;
        }
        
        /* Form labels */
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        /* Card animations */
        .card {
            transition: all 0.3s ease;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        
        /* Input group enhancements */
        .input-group-text {
            background: rgba(79, 172, 254, 0.1);
            border: 2px solid #e3f2fd;
            color: #4facfe;
            font-weight: 600;
        }
        
        /* Page header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        /* Progress indicators */
        .step-indicator {
            position: absolute;
            top: -10px;
            right: 20px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.9rem;
            font-weight: 600;
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
                    <h2><i class="fas fa-edit me-2"></i>Mass Product Updates</h2>
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

                <form method="POST" id="massUpdateForm">
                    <!-- Step 1: Filter Products -->
                    <div class="filter-section">
                        <h4><i class="fas fa-filter me-2"></i>Step 1: Select Products to Update</h4>
                        <p class="text-muted">Choose criteria to filter which products will be updated:</p>
                        
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
                                <label class="form-label">Supplier</label>
                                <select name="filters[supplier_id]" class="form-select">
                                    <option value="">All Suppliers</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>">
                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-4">
                                <label class="form-label">Current Status</label>
                                <select name="filters[status]" class="form-select">
                                    <option value="">Any Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stock Condition</label>
                                <select name="filters[stock_condition]" class="form-select">
                                    <option value="">Any Stock Level</option>
                                    <option value="in_stock">In Stock (>0)</option>
                                    <option value="low_stock">Low Stock (≤10)</option>
                                    <option value="out_of_stock">Out of Stock (0)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-outline-primary" style="margin-top: 32px;" onclick="previewProducts()">
                                    <i class="fas fa-eye me-2"></i>Preview Products
                                </button>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Price Range</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="filters[price_range_min]" class="form-control" placeholder="Min Price" step="0.01">
                                    <span class="input-group-text">to</span>
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="filters[price_range_max]" class="form-control" placeholder="Max Price" step="0.01">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Define Updates -->
                    <div class="update-section">
                        <h4><i class="fas fa-wrench me-2"></i>Step 2: Define Updates</h4>
                        <p class="text-muted">Choose what fields to update (leave blank to keep current values):</p>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Change Category To</label>
                                <select name="updates[category_id]" class="form-select">
                                    <option value="">Keep Current Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Change Brand To</label>
                                <select name="updates[brand_id]" class="form-select">
                                    <option value="">Keep Current Brand</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo $brand['id']; ?>">
                                            <?php echo htmlspecialchars($brand['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Change Supplier To</label>
                                <select name="updates[supplier_id]" class="form-select">
                                    <option value="">Keep Current Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>">
                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Change Status To</label>
                                <select name="updates[status]" class="form-select">
                                    <option value="">Keep Current Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tax Rate (%)</label>
                                <input type="number" name="updates[tax_rate]" class="form-control" placeholder="Leave blank to keep current" step="0.01" min="0" max="100">
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label class="form-label">Append to Description</label>
                                <textarea name="updates[description_append]" class="form-control" rows="3" placeholder="Text to add to end of existing descriptions"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Replace Description</label>
                                <textarea name="updates[description_replace]" class="form-control" rows="3" placeholder="New description (will replace existing)"></textarea>
                                <small class="form-text text-muted">Note: This will replace ALL descriptions for selected products</small>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="preview-section" id="previewSection" style="display: none;">
                        <h4><i class="fas fa-eye me-2"></i>Preview: Products to be Updated</h4>
                        <div id="previewContent">
                            <!-- Preview content will be loaded here -->
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="text-center">
                        <button type="submit" name="mass_update" class="btn btn-primary btn-lg" onclick="return confirmUpdate()">
                            <i class="fas fa-save me-2"></i>Apply Mass Updates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function previewProducts() {
            const formData = new FormData(document.getElementById('massUpdateForm'));
            formData.append('action', 'preview');

            fetch('bulk_update_handler.php', {
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
                alert('An error occurred while previewing products.');
            });
        }

        function confirmUpdate() {
            const previewSection = document.getElementById('previewSection');
            if (previewSection.style.display === 'none') {
                alert('Please preview the products first to see what will be updated.');
                return false;
            }

            return confirm('Are you sure you want to apply these updates to the selected products? This action cannot be undone.');
        }

        // Auto-clear conflicting fields
        document.querySelector('textarea[name="updates[description_append]"]').addEventListener('input', function() {
            if (this.value.trim()) {
                document.querySelector('textarea[name="updates[description_replace]"]').value = '';
            }
        });

        document.querySelector('textarea[name="updates[description_replace]"]').addEventListener('input', function() {
            if (this.value.trim()) {
                document.querySelector('textarea[name="updates[description_append]"]').value = '';
            }
        });
    </script>
</body>
</html>
