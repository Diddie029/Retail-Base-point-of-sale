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

// Handle quick actions from bulk operations page
if (isset($_GET['quick_action'])) {
    $quick_action = $_GET['quick_action'];
    
    try {
        $conn->beginTransaction();
        
        if ($quick_action === 'activate_low_stock') {
            $stmt = $conn->prepare("UPDATE products SET status = 'active' WHERE quantity <= 10 AND status = 'inactive'");
            $stmt->execute();
            $affected = $stmt->rowCount();
            $_SESSION['success'] = "Activated $affected low stock products.";
        } elseif ($quick_action === 'deactivate_out_of_stock') {
            $stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE quantity = 0 AND status = 'active'");
            $stmt->execute();
            $affected = $stmt->rowCount();
            $_SESSION['success'] = "Deactivated $affected out of stock products.";
        }
        
        $conn->commit();
        
        // Log the action
        try {
            $log_stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                VALUES (:user_id, :username, :action, :details, NOW())
            ");
            $log_stmt->execute([
                ':user_id' => $user_id,
                ':username' => $username,
                ':action' => 'bulk_status_quick_action',
                ':details' => "Quick action: $quick_action - $affected products affected"
            ]);
        } catch (Exception $e) {
            // Log table might not exist, ignore
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
    }
    
    header("Location: bulk_status.php");
    exit();
}

// Get reference data
$categories_stmt = $conn->query("SELECT * FROM categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$brands_stmt = $conn->query("SELECT * FROM brands ORDER BY name");
$brands = $brands_stmt->fetchAll(PDO::FETCH_ASSOC);

$suppliers_stmt = $conn->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle status update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_status'])) {
    $filters = $_POST['filters'] ?? [];
    $new_status = $_POST['new_status'] ?? '';
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];

    if (empty($new_status)) {
        $_SESSION['error'] = "Please select a status to apply.";
        header("Location: bulk_status.php");
        exit();
    }

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

        if (!empty($filters['current_status'])) {
            $where_conditions[] = "status = :current_status";
            $params[':current_status'] = $filters['current_status'];
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
                case 'high_stock':
                    $where_conditions[] = "quantity > 50";
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

        // Date filters
        if (!empty($filters['created_after'])) {
            $where_conditions[] = "created_at >= :created_after";
            $params[':created_after'] = $filters['created_after'];
        }

        if (!empty($filters['created_before'])) {
            $where_conditions[] = "created_at <= :created_before";
            $params[':created_before'] = $filters['created_before'] . ' 23:59:59';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Update products
        $update_sql = "UPDATE products SET status = :new_status, updated_at = NOW() $where_clause";
        $params[':new_status'] = $new_status;
        
        $update_stmt = $conn->prepare($update_sql);
        foreach ($params as $key => $value) {
            $update_stmt->bindValue($key, $value);
        }

        if ($update_stmt->execute()) {
            $success_count = $update_stmt->rowCount();
        }

        $conn->commit();

        if ($success_count > 0) {
            $_SESSION['success'] = "Successfully updated status for $success_count products to " . ucfirst($new_status) . ".";
            
            // Log the bulk operation
            try {
                $log_stmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, username, action, details, created_at) 
                    VALUES (:user_id, :username, :action, :details, NOW())
                ");
                $log_stmt->execute([
                    ':user_id' => $user_id,
                    ':username' => $username,
                    ':action' => 'bulk_status_update',
                    ':details' => "Updated status for $success_count products to $new_status"
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
        $_SESSION['error'] = "An error occurred during the bulk status operation.";
    }

    header("Location: bulk_status.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mass Status Updates - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
        
        .status-section {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-section {
            background-color: #f8f9fa;
        }
        .action-section {
            background-color: #e8f4f8;
        }
        .preview-section {
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        .quick-actions {
            background-color: #fff3cd;
            border-color: #ffeaa7;
        }
        .status-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .status-card.selected {
            border-color: #007bff;
            background-color: #e3f2fd;
        }
        .status-card.active-card {
            border-color: #28a745;
        }
        .status-card.inactive-card {
            border-color: #ffc107;
        }
        .status-card.discontinued-card {
            border-color: #6c757d;
        }
        .status-card.blocked-card {
            border-color: #dc3545;
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
                    <h2><i class="fas fa-toggle-on me-2"></i>Mass Status Updates</h2>
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

                <!-- Quick Actions -->
                <div class="status-section quick-actions">
                    <h4><i class="fas fa-bolt me-2"></i>Quick Actions</h4>
                    <p class="text-muted">Common status update operations:</p>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <button class="btn btn-warning w-100" onclick="window.location.href='?quick_action=activate_low_stock'">
                                <i class="fas fa-exclamation-triangle me-2"></i>Activate Low Stock Items
                            </button>
                            <small class="d-block text-muted mt-1">Items with quantity ≤ 10</small>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-danger w-100" onclick="window.location.href='?quick_action=deactivate_out_of_stock'">
                                <i class="fas fa-times-circle me-2"></i>Deactivate Out of Stock
                            </button>
                            <small class="d-block text-muted mt-1">Items with 0 quantity</small>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-info w-100" onclick="previewStatusChange('activate_all_in_stock')">
                                <i class="fas fa-check-circle me-2"></i>Activate All In Stock
                            </button>
                            <small class="d-block text-muted mt-1">Items with quantity > 0</small>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-success w-100" onclick="previewCustomFilters()">
                                <i class="fas fa-filter me-2"></i>Custom Filters
                            </button>
                            <small class="d-block text-muted mt-1">Use advanced criteria</small>
                        </div>
                    </div>
                </div>

                <form method="POST" id="statusForm">
                    <!-- Step 1: Filter Products -->
                    <div class="status-section filter-section">
                        <h4><i class="fas fa-filter me-2"></i>Step 1: Select Products to Update</h4>
                        <p class="text-muted">Choose criteria to filter which products to update:</p>
                        
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
                                <select name="filters[current_status]" class="form-select" onchange="handleStatusFilterChange(this.value)">
                                    <option value="">Any Status</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                    <option value="discontinued">Discontinued Only</option>
                                    <option value="blocked">Blocked Only</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stock Condition</label>
                                <select name="filters[stock_condition]" class="form-select">
                                    <option value="">Any Stock Level</option>
                                    <option value="in_stock">In Stock (>0)</option>
                                    <option value="low_stock">Low Stock (≤10)</option>
                                    <option value="out_of_stock">Out of Stock (0)</option>
                                    <option value="high_stock">High Stock (>50)</option>
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
                            <div class="col-md-6">
                                <label class="form-label">Created Date Range</label>
                                <div class="input-group">
                                    <input type="date" name="filters[created_after]" class="form-control">
                                    <span class="input-group-text">to</span>
                                    <input type="date" name="filters[created_before]" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Choose New Status -->
                    <div class="status-section action-section">
                        <h4><i class="fas fa-toggle-on me-2"></i>Step 2: Choose New Status</h4>
                        <p class="text-muted">Select the status you want to apply to the filtered products:</p>

                        <div class="row">
                            <div class="col-md-6 col-lg-3">
                                <div class="status-card active-card" onclick="selectStatus('active')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="new_status" value="active" id="status_active" onchange="console.log('Active radio changed:', this.checked)" onclick="event.stopPropagation();">
                                        <label class="form-check-label" for="status_active" onclick="event.stopPropagation();">
                                            <h5><i class="fas fa-check-circle text-success me-2"></i>Active</h5>
                                        </label>
                                    </div>
                                    <p class="mb-0 text-muted small">Make products visible and available for sale</p>
                                    <ul class="list-unstyled mt-2 mb-0 small">
                                        <li><i class="fas fa-check text-success me-2"></i>Appears in POS</li>
                                        <li><i class="fas fa-check text-success me-2"></i>Available for orders</li>
                                        <li><i class="fas fa-check text-success me-2"></i>Included in reports</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="status-card inactive-card" onclick="selectStatus('inactive')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="new_status" value="inactive" id="status_inactive" onchange="console.log('Inactive radio changed:', this.checked)" onclick="event.stopPropagation();">
                                        <label class="form-check-label" for="status_inactive" onclick="event.stopPropagation();">
                                            <h5><i class="fas fa-pause-circle text-warning me-2"></i>Inactive</h5>
                                        </label>
                                    </div>
                                    <p class="mb-0 text-muted small">Hide products from sale while keeping data</p>
                                    <ul class="list-unstyled mt-2 mb-0 small">
                                        <li><i class="fas fa-times text-danger me-2"></i>Hidden from POS</li>
                                        <li><i class="fas fa-times text-danger me-2"></i>Not available for orders</li>
                                        <li><i class="fas fa-check text-success me-2"></i>Data preserved</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="status-card discontinued-card" onclick="selectStatus('discontinued')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="new_status" value="discontinued" id="status_discontinued" onchange="console.log('Discontinued radio changed:', this.checked)" onclick="event.stopPropagation();">
                                        <label class="form-check-label" for="status_discontinued" onclick="event.stopPropagation();">
                                            <h5><i class="fas fa-ban text-secondary me-2"></i>Discontinued</h5>
                                        </label>
                                    </div>
                                    <p class="mb-0 text-muted small">Product no longer available for purchase</p>
                                    <ul class="list-unstyled mt-2 mb-0 small">
                                        <li><i class="fas fa-times text-danger me-2"></i>Not for new sales</li>
                                        <li><i class="fas fa-times text-danger me-2"></i>Hidden from catalog</li>
                                        <li><i class="fas fa-check text-success me-2"></i>Historical data kept</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-3">
                                <div class="status-card blocked-card" onclick="selectStatus('blocked')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="new_status" value="blocked" id="status_blocked" onchange="console.log('Blocked radio changed:', this.checked)" onclick="event.stopPropagation();">
                                        <label class="form-check-label" for="status_blocked" onclick="event.stopPropagation();">
                                            <h5><i class="fas fa-exclamation-triangle text-danger me-2"></i>Blocked</h5>
                                        </label>
                                    </div>
                                    <p class="mb-0 text-muted small">Product blocked for specific reasons</p>
                                    <ul class="list-unstyled mt-2 mb-0 small">
                                        <li><i class="fas fa-times text-danger me-2"></i>Completely blocked</li>
                                        <li><i class="fas fa-times text-danger me-2"></i>All sales stopped</li>
                                        <li><i class="fas fa-info-circle text-info me-2"></i>Check block reason</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Section -->
                    <div class="status-section preview-section" id="previewSection" style="display: none;">
                        <h4><i class="fas fa-eye me-2"></i>Preview: Products to be Updated</h4>
                        <div id="previewContent">
                            <!-- Preview content will be loaded here -->
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="text-center">
                        <button type="submit" name="apply_status" class="btn btn-info btn-lg" onclick="return confirmStatusUpdate()">
                            <i class="fas fa-save me-2"></i>Apply Status Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function selectStatus(status) {
            console.log('selectStatus called with:', status); // Debug log
            
            // Remove selected class from all status cards
            document.querySelectorAll('.status-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            event.currentTarget.classList.add('selected');
            
            // Select the radio button
            const radioButton = document.getElementById('status_' + status);
            console.log('Radio button found:', radioButton); // Debug log
            
            if (radioButton) {
                // Uncheck all other radio buttons first
                document.querySelectorAll('input[name="new_status"]').forEach(radio => {
                    radio.checked = false;
                });
                
                // Check the selected radio button
                radioButton.checked = true;
                console.log('Radio button checked:', radioButton.checked); // Debug log
                
                // Trigger change event
                radioButton.dispatchEvent(new Event('change'));
            } else {
                console.error('Radio button not found for status:', status);
            }
        }

        function handleStatusFilterChange(selectedStatus) {
            console.log('Status filter changed to:', selectedStatus); // Debug log
            
            if (selectedStatus && selectedStatus !== '') {
                // Find the corresponding status card and select it
                const statusCard = document.querySelector(`[onclick="selectStatus('${selectedStatus}')"]`);
                if (statusCard) {
                    console.log('Found status card for:', selectedStatus); // Debug log
                    
                    // Remove selected class from all status cards
                    document.querySelectorAll('.status-card').forEach(card => {
                        card.classList.remove('selected');
                    });
                    
                    // Add selected class to the corresponding card
                    statusCard.classList.add('selected');
                    
                    // Select the radio button
                    const radioButton = document.getElementById('status_' + selectedStatus);
                    if (radioButton) {
                        // Uncheck all other radio buttons first
                        document.querySelectorAll('input[name="new_status"]').forEach(radio => {
                            radio.checked = false;
                        });
                        
                        // Check the selected radio button
                        radioButton.checked = true;
                        console.log('Radio button checked from filter:', radioButton.checked); // Debug log
                        
                        // Trigger change event
                        radioButton.dispatchEvent(new Event('change'));
                        
                        // Scroll to the status selection area to show the user what was selected
                        document.querySelector('.action-section').scrollIntoView({ behavior: 'smooth' });
                    }
                } else {
                    console.error('Status card not found for:', selectedStatus);
                }
            }
        }

        function previewProducts() {
            // Debug: Check all radio buttons
            const allRadios = document.querySelectorAll('input[name="new_status"]');
            console.log('All radio buttons found:', allRadios.length);
            allRadios.forEach((radio, index) => {
                console.log(`Radio ${index}: id=${radio.id}, value=${radio.value}, checked=${radio.checked}`);
            });
            
            // Validate that a status is selected before previewing
            const selectedStatus = document.querySelector('input[name="new_status"]:checked');
            console.log('Selected status:', selectedStatus); // Debug log
            
            if (!selectedStatus) {
                alert('Please select a status (Active, Inactive, Discontinued, or Blocked) before previewing products.');
                // Highlight the status selection area
                document.querySelector('.action-section').scrollIntoView({ behavior: 'smooth' });
                document.querySelector('.action-section').style.borderColor = '#dc3545';
                setTimeout(() => {
                    document.querySelector('.action-section').style.borderColor = '#e8f4f8';
                }, 2000);
                return;
            }

            console.log('Selected status value:', selectedStatus.value); // Debug log

            const formData = new FormData(document.getElementById('statusForm'));
            formData.append('action', 'preview');
            
            // Debug: Log all form data
            console.log('Form data being sent:');
            for (let [key, value] of formData.entries()) {
                console.log(key, value);
            }

            fetch('bulk_status_handler.php', {
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

        function confirmStatusUpdate() {
            const previewSection = document.getElementById('previewSection');
            if (previewSection.style.display === 'none') {
                alert('Please preview the products first to see what will be updated.');
                return false;
            }

            const selectedStatus = document.querySelector('input[name="new_status"]:checked');
            if (!selectedStatus) {
                alert('Please select a status to apply.');
                return false;
            }

            const statusValue = selectedStatus.value;
            const statusLabels = {
                'active': 'activate',
                'inactive': 'deactivate',
                'discontinued': 'mark as discontinued',
                'blocked': 'block'
            };

            const statusText = statusLabels[statusValue] || 'update the status of';
            return confirm(`Are you sure you want to ${statusText} the selected products? This action can be reversed later if needed.`);
        }

        function previewStatusChange(action) {
            if (action === 'activate_all_in_stock') {
                // Set filters for in-stock items
                document.querySelector('select[name="filters[stock_condition]"]').value = 'in_stock';
                document.querySelector('select[name="filters[current_status]"]').value = 'inactive';
                document.getElementById('status_active').checked = true;
                selectStatus('active');
                previewProducts();
            }
        }

        function previewCustomFilters() {
            document.querySelector('.filter-section').scrollIntoView({ behavior: 'smooth' });
            // Add some visual emphasis
            document.querySelector('.filter-section').style.borderColor = '#007bff';
            setTimeout(() => {
                document.querySelector('.filter-section').style.borderColor = '#e9ecef';
            }, 2000);
        }
    </script>
</body>
</html>
