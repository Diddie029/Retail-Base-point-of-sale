<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user has permission to manage products
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

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

if (!hasPermission('manage_products', $permissions)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Handle preview request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    $filters = $_POST['filters'] ?? [];
    
    try {
        // Build WHERE clause based on filters
        $where_conditions = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where_conditions[] = "p.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        if (!empty($filters['brand_id'])) {
            $where_conditions[] = "p.brand_id = :brand_id";
            $params[':brand_id'] = $filters['brand_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $where_conditions[] = "p.supplier_id = :supplier_id";
            $params[':supplier_id'] = $filters['supplier_id'];
        }

        if (!empty($filters['status'])) {
            $where_conditions[] = "p.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['stock_condition'])) {
            switch ($filters['stock_condition']) {
                case 'low_stock':
                    $where_conditions[] = "p.quantity <= 10";
                    break;
                case 'out_of_stock':
                    $where_conditions[] = "p.quantity = 0";
                    break;
                case 'in_stock':
                    $where_conditions[] = "p.quantity > 0";
                    break;
            }
        }

        if (!empty($filters['price_range_min'])) {
            $where_conditions[] = "p.price >= :price_min";
            $params[':price_min'] = $filters['price_range_min'];
        }

        if (!empty($filters['price_range_max'])) {
            $where_conditions[] = "p.price <= :price_max";
            $params[':price_max'] = $filters['price_range_max'];
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Get products that match the criteria
        $sql = "
            SELECT p.*, c.name as category_name, b.name as brand_name, s.name as supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            $where_clause
            ORDER BY p.name
            LIMIT 50
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM products p $where_clause";
        $count_stmt = $conn->prepare($count_sql);
        foreach ($params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        if (empty($products)) {
            echo json_encode([
                'success' => false, 
                'message' => 'No products found matching the specified criteria.'
            ]);
        } else {
            // Generate HTML for preview
            ob_start();
            ?>
            <div class="alert alert-info">
                <strong><?php echo number_format($total_count); ?> products</strong> match your criteria. 
                <?php if ($total_count > 50): ?>
                Showing first 50 products.
                <?php endif; ?>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Brand</th>
                            <th>Supplier</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($product['brand_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></td>
                            <td>$<?php echo number_format($product['price'], 2); ?></td>
                            <td>
                                <?php 
                                $qty_class = '';
                                if ($product['quantity'] == 0) $qty_class = 'text-danger';
                                elseif ($product['quantity'] <= 10) $qty_class = 'text-warning';
                                ?>
                                <span class="<?php echo $qty_class; ?>">
                                    <?php echo $product['quantity']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $product['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_count > 50): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                Only the first 50 products are shown here, but <strong>all <?php echo number_format($total_count); ?> matching products</strong> will be updated when you apply changes.
            </div>
            <?php endif; ?>
            <?php
            
            $html = ob_get_clean();
            
            echo json_encode([
                'success' => true,
                'html' => $html,
                'total_count' => $total_count
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
