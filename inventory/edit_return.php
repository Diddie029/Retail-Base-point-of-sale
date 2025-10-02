<?php
session_start();

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
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

// Check if user has permission to edit returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get return ID from URL
$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$return_id) {
    header("Location: view_returns.php?error=invalid_return_id");
    exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Error loading settings: " . $e->getMessage());
}

// Get suppliers for dropdown
$suppliers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading suppliers: " . $e->getMessage());
}

// Get products for dropdown
$products = [];
try {
    $stmt = $conn->query("
        SELECT p.id, p.name, p.sku, p.cost_price, p.quantity, 
               c.name as category_name, b.name as brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.status = 'active'
        ORDER BY p.name ASC
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading products: " . $e->getMessage());
}

// Function to get return data
function getReturnData($conn, $return_id) {
    try {
        // Get return details
        $stmt = $conn->prepare("
            SELECT r.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name,
                   COALESCE(au.username, 'System') as approved_by_name
            FROM supplier_returns r
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users au ON r.approved_by = au.id
            WHERE r.id = :return_id
        ");
        $stmt->execute([':return_id' => $return_id]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return) {
            return null;
        }

        // Get return items
        $stmt = $conn->prepare("
            SELECT ri.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name
            FROM supplier_return_items ri
            LEFT JOIN products p ON ri.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ri.return_id = :return_id
            ORDER BY ri.id ASC
        ");
        $stmt->execute([':return_id' => $return_id]);
        $return['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $return;
    } catch (PDOException $e) {
        error_log("Error getting return data: " . $e->getMessage());
        return null;
    }
}

// Get return data
$return = getReturnData($conn, $return_id);
if (!$return) {
    header("Location: view_returns.php?error=return_not_found");
    exit();
}

// Check if return can be edited (only pending and draft returns can be edited)
if (!in_array($return['status'], ['pending', 'draft'])) {
    header("Location: view_return.php?id=" . $return_id . "&error=cannot_edit_status");
    exit();
}

// Handle form submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_return') {
        try {
            $conn->beginTransaction();

            // Get form data
            $supplier_id = intval($_POST['supplier_id'] ?? 0);
            $return_reason = trim($_POST['return_reason'] ?? '');
            $return_notes = trim($_POST['return_notes'] ?? '');
            $status = trim($_POST['status'] ?? 'pending');
            $items = json_decode($_POST['items'] ?? '[]', true);

            // Validate required fields
            if (!$supplier_id || !$return_reason || empty($items)) {
                throw new Exception("Please fill in all required fields and add at least one item.");
            }

            // Calculate totals
            $total_items = 0;
            $total_amount = 0;
            foreach ($items as $item) {
                $total_items += intval($item['quantity']);
                $total_amount += floatval($item['quantity']) * floatval($item['cost_price']);
            }

            // Update return
            $stmt = $conn->prepare("
                UPDATE supplier_returns 
                SET supplier_id = :supplier_id,
                    return_reason = :return_reason,
                    return_notes = :return_notes,
                    status = :status,
                    total_items = :total_items,
                    total_amount = :total_amount,
                    updated_at = NOW()
                WHERE id = :return_id
            ");
            $stmt->execute([
                ':supplier_id' => $supplier_id,
                ':return_reason' => $return_reason,
                ':return_notes' => $return_notes,
                ':status' => $status,
                ':total_items' => $total_items,
                ':total_amount' => $total_amount,
                ':return_id' => $return_id
            ]);

            // Delete existing return items
            $stmt = $conn->prepare("DELETE FROM supplier_return_items WHERE return_id = :return_id");
            $stmt->execute([':return_id' => $return_id]);

            // Insert updated return items
            $stmt = $conn->prepare("
                INSERT INTO supplier_return_items (
                    return_id, product_id, quantity, cost_price, return_reason, notes
                ) VALUES (
                    :return_id, :product_id, :quantity, :cost_price, :return_reason, :notes
                )
            ");

            foreach ($items as $item) {
                $stmt->execute([
                    ':return_id' => $return_id,
                    ':product_id' => intval($item['product_id']),
                    ':quantity' => intval($item['quantity']),
                    ':cost_price' => floatval($item['cost_price']),
                    ':return_reason' => trim($item['reason'] ?? ''),
                    ':notes' => trim($item['notes'] ?? '')
                ]);
            }

            $conn->commit();

            // Refresh return data
            $return = getReturnData($conn, $return_id);
            $message = "Return updated successfully!";
            $message_type = 'success';

        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Error updating return: " . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Return <?php echo htmlspecialchars($return['return_number']); ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .return-header {
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            color: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .item-row {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .item-row:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .remove-item {
            background: #dc3545;
            border: none;
            color: white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .remove-item:hover {
            background: #c82333;
            transform: scale(1.1);
        }

        .add-item-btn {
            background: var(--primary-color);
            border: 2px dashed var(--primary-color);
            color: white;
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .add-item-btn:hover {
            background: transparent;
            color: var(--primary-color);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: #5855eb;
            border-color: #5855eb;
        }

        .summary-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .summary-item:last-child {
            margin-bottom: 0;
            padding-top: 0.5rem;
            border-top: 2px solid #dee2e6;
            font-weight: 600;
            font-size: 1.1rem;
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
                    <h2>Edit Return <?php echo htmlspecialchars($return['return_number']); ?></h2>
                    <p class="header-subtitle">Modify return details and items</p>
                </div>
                <div class="header-actions">
                    <a href="view_return.php?id=<?php echo $return_id; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-eye me-2"></i>View Return
                    </a>
                    <a href="view_returns.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Returns
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Return Header -->
            <div class="return-header">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-1">Return <?php echo htmlspecialchars($return['return_number']); ?></h4>
                        <p class="mb-0 opacity-75">Created on <?php echo date('M j, Y g:i A', strtotime($return['created_at'])); ?></p>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="badge bg-warning fs-6 px-3 py-2">
                            <?php echo ucfirst($return['status']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Edit Form -->
            <form method="POST" id="editReturnForm">
                <input type="hidden" name="action" value="update_return">
                <input type="hidden" name="items" id="itemsData">

                <div class="row">
                    <!-- Return Details -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Return Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="supplier_id" class="form-label">Supplier *</label>
                                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                                <option value="">Select Supplier</option>
                                                <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?php echo $supplier['id']; ?>" 
                                                        <?php echo $supplier['id'] == $return['supplier_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-select" id="status" name="status">
                                                <option value="draft" <?php echo $return['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                                <option value="pending" <?php echo $return['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="return_reason" class="form-label">Return Reason *</label>
                                    <select class="form-select" id="return_reason" name="return_reason" required>
                                        <option value="">Select Reason</option>
                                        <option value="defective" <?php echo $return['return_reason'] === 'defective' ? 'selected' : ''; ?>>Defective Products</option>
                                        <option value="damaged" <?php echo $return['return_reason'] === 'damaged' ? 'selected' : ''; ?>>Damaged in Transit</option>
                                        <option value="wrong_items" <?php echo $return['return_reason'] === 'wrong_items' ? 'selected' : ''; ?>>Wrong Items Received</option>
                                        <option value="quality_issues" <?php echo $return['return_reason'] === 'quality_issues' ? 'selected' : ''; ?>>Quality Issues</option>
                                        <option value="overstock" <?php echo $return['return_reason'] === 'overstock' ? 'selected' : ''; ?>>Overstock</option>
                                        <option value="expired" <?php echo $return['return_reason'] === 'expired' ? 'selected' : ''; ?>>Expired Products</option>
                                        <option value="other" <?php echo $return['return_reason'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="return_notes" class="form-label">Return Notes</label>
                                    <textarea class="form-control" id="return_notes" name="return_notes" rows="3" 
                                              placeholder="Additional notes about this return..."><?php echo htmlspecialchars($return['return_notes'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Return Items -->
                        <div class="card mt-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Return Items</h5>
                                <button type="button" class="btn btn-primary btn-sm" onclick="addItem()">
                                    <i class="bi bi-plus-circle me-1"></i>Add Item
                                </button>
                            </div>
                            <div class="card-body">
                                <div id="itemsContainer">
                                    <!-- Items will be populated here -->
                                </div>

                                <div class="add-item-btn" onclick="addItem()">
                                    <i class="bi bi-plus-circle me-2"></i>
                                    Click to Add Item
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Sidebar -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-calculator me-2"></i>Return Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="summary-card">
                                    <div class="summary-item">
                                        <span>Total Items:</span>
                                        <span id="totalItems">0</span>
                                    </div>
                                    <div class="summary-item">
                                        <span>Total Amount:</span>
                                        <span id="totalAmount"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-4">
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-circle me-2"></i>Update Return
                                    </button>
                                    <a href="view_return.php?id=<?php echo $return_id; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let items = [];
        let itemCounter = 0;
        const products = <?php echo json_encode($products); ?>;
        const currencySymbol = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>';

        // Initialize with existing items
        const existingItems = <?php echo json_encode($return['items']); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Load existing items
            existingItems.forEach(item => {
                addItem({
                    product_id: item.product_id,
                    quantity: item.quantity,
                    cost_price: item.cost_price,
                    reason: item.return_reason || '',
                    notes: item.notes || ''
                });
            });

            updateSummary();
        });

        function addItem(data = {}) {
            itemCounter++;
            const itemId = 'item_' + itemCounter;
            
            const itemHtml = `
                <div class="item-row" id="${itemId}">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <label class="form-label small">Product *</label>
                            <select class="form-select product-select" onchange="updateProductInfo('${itemId}')">
                                <option value="">Select Product</option>
                                ${products.map(product => `
                                    <option value="${product.id}" 
                                            data-cost="${product.cost_price}" 
                                            data-stock="${product.quantity}"
                                            ${data.product_id == product.id ? 'selected' : ''}>
                                        ${product.name} (${product.sku || 'No SKU'})
                                    </option>
                                `).join('')}
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Quantity *</label>
                            <input type="number" class="form-control quantity-input" 
                                   min="1" value="${data.quantity || 1}" 
                                   onchange="updateItemTotal('${itemId}')">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Cost Price</label>
                            <input type="number" class="form-control cost-input" 
                                   step="0.01" min="0" value="${data.cost_price || 0}" 
                                   onchange="updateItemTotal('${itemId}')">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Total</label>
                            <div class="form-control-plaintext fw-bold item-total">
                                ${currencySymbol} 0.00
                            </div>
                        </div>
                        <div class="col-md-2 text-end">
                            <label class="form-label small">&nbsp;</label>
                            <div>
                                <button type="button" class="remove-item" onclick="removeItem('${itemId}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label class="form-label small">Return Reason</label>
                            <select class="form-select reason-select">
                                <option value="">Select Reason</option>
                                <option value="defective" ${data.reason === 'defective' ? 'selected' : ''}>Defective</option>
                                <option value="damaged" ${data.reason === 'damaged' ? 'selected' : ''}>Damaged</option>
                                <option value="wrong_item" ${data.reason === 'wrong_item' ? 'selected' : ''}>Wrong Item</option>
                                <option value="quality_issues" ${data.reason === 'quality_issues' ? 'selected' : ''}>Quality Issues</option>
                                <option value="expired" ${data.reason === 'expired' ? 'selected' : ''}>Expired</option>
                                <option value="other" ${data.reason === 'other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Notes</label>
                            <input type="text" class="form-control notes-input" 
                                   placeholder="Additional notes..." 
                                   value="${data.notes || ''}">
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('itemsContainer').insertAdjacentHTML('beforeend', itemHtml);
            
            // Update product info if product is pre-selected
            if (data.product_id) {
                updateProductInfo(itemId);
            }
            
            updateItemTotal(itemId);
            updateSummary();
        }

        function removeItem(itemId) {
            if (confirm('Are you sure you want to remove this item?')) {
                document.getElementById(itemId).remove();
                updateSummary();
            }
        }

        function updateProductInfo(itemId) {
            const itemRow = document.getElementById(itemId);
            const productSelect = itemRow.querySelector('.product-select');
            const costInput = itemRow.querySelector('.cost-input');
            
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            if (selectedOption.value) {
                const cost = parseFloat(selectedOption.dataset.cost) || 0;
                costInput.value = cost.toFixed(2);
                updateItemTotal(itemId);
            }
        }

        function updateItemTotal(itemId) {
            const itemRow = document.getElementById(itemId);
            const quantity = parseFloat(itemRow.querySelector('.quantity-input').value) || 0;
            const cost = parseFloat(itemRow.querySelector('.cost-input').value) || 0;
            const total = quantity * cost;
            
            itemRow.querySelector('.item-total').textContent = `${currencySymbol} ${total.toFixed(2)}`;
            updateSummary();
        }

        function updateSummary() {
            let totalItems = 0;
            let totalAmount = 0;

            document.querySelectorAll('.item-row').forEach(row => {
                const quantity = parseFloat(row.querySelector('.quantity-input').value) || 0;
                const cost = parseFloat(row.querySelector('.cost-input').value) || 0;
                
                totalItems += quantity;
                totalAmount += quantity * cost;
            });

            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('totalAmount').textContent = `${currencySymbol} ${totalAmount.toFixed(2)}`;
        }

        function collectItemsData() {
            const itemsData = [];
            
            document.querySelectorAll('.item-row').forEach(row => {
                const productSelect = row.querySelector('.product-select');
                const quantity = row.querySelector('.quantity-input').value;
                const cost = row.querySelector('.cost-input').value;
                const reason = row.querySelector('.reason-select').value;
                const notes = row.querySelector('.notes-input').value;
                
                if (productSelect.value && quantity && cost) {
                    itemsData.push({
                        product_id: productSelect.value,
                        quantity: parseInt(quantity),
                        cost_price: parseFloat(cost),
                        reason: reason,
                        notes: notes
                    });
                }
            });
            
            return itemsData;
        }

        // Form submission
        document.getElementById('editReturnForm').addEventListener('submit', function(e) {
            const itemsData = collectItemsData();
            
            if (itemsData.length === 0) {
                e.preventDefault();
                alert('Please add at least one item to the return.');
                return;
            }
            
            document.getElementById('itemsData').value = JSON.stringify(itemsData);
        });

        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
