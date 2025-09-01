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

// Handle preview request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    $filters = $_POST['filters'] ?? [];
    $export_fields = $_POST['export_fields'] ?? [];
    
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

        if (!empty($filters['created_after'])) {
            $where_conditions[] = "p.created_at >= :created_after";
            $params[':created_after'] = $filters['created_after'];
        }

        if (!empty($filters['created_before'])) {
            $where_conditions[] = "p.created_at <= :created_before";
            $params[':created_before'] = $filters['created_before'] . ' 23:59:59';
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // Build SELECT clause based on chosen fields
        $select_fields = [];
        $field_headers = [];
        
        $available_fields = [
            'p.id' => 'ID',
            'p.name' => 'Name',
            'p.sku' => 'SKU',
            'p.barcode' => 'Barcode',
            'p.description' => 'Description',
            'c.name' => 'Category',
            'b.name' => 'Brand',
            's.name' => 'Supplier',
            'p.price' => 'Price',
            'p.sale_price' => 'Sale Price',
            'p.cost_price' => 'Cost Price',
            'p.quantity' => 'Quantity',
            'p.min_stock_level' => 'Min Stock Level',
            'p.tax_rate' => 'Tax Rate',
            'p.status' => 'Status',
            'p.weight' => 'Weight',
            'p.dimensions' => 'Dimensions',
            'p.created_at' => 'Created Date',
            'p.updated_at' => 'Updated Date'
        ];

        if (empty($export_fields)) {
            // Default fields if none selected
            $export_fields = ['p.name', 'p.sku', 'p.barcode', 'c.name', 'p.price', 'p.quantity', 'p.status'];
        }

        foreach ($export_fields as $field) {
            if (isset($available_fields[$field])) {
                $select_fields[] = $field . ' AS `' . $available_fields[$field] . '`';
                $field_headers[] = $available_fields[$field];
            }
        }

        $select_clause = implode(', ', $select_fields);

        // Get total count
        $count_sql = "SELECT COUNT(*) as total FROM products p 
                      LEFT JOIN categories c ON p.category_id = c.id
                      LEFT JOIN brands b ON p.brand_id = b.id
                      LEFT JOIN suppliers s ON p.supplier_id = s.id
                      $where_clause";
        $count_stmt = $conn->prepare($count_sql);
        foreach ($params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        if ($total_count == 0) {
            echo json_encode([
                'success' => false,
                'message' => 'No products found matching the specified criteria.'
            ]);
            exit();
        }

        // Get sample products (first 10)
        $sql = "
            SELECT $select_clause
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            $where_clause
            ORDER BY p.name
            LIMIT 10
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate HTML for preview
        ob_start();
        ?>
        <div class="alert alert-info">
            <strong><?php echo number_format($total_count); ?> products</strong> will be exported with <strong><?php echo count($field_headers); ?> fields</strong>.
            <?php if ($total_count > 10): ?>
            Showing first 10 products as preview.
            <?php endif; ?>
        </div>

        <div class="alert alert-success">
            <h6><i class="fas fa-columns me-2"></i>Fields to be exported:</h6>
            <div class="row">
                <?php 
                $chunks = array_chunk($field_headers, ceil(count($field_headers) / 3));
                foreach ($chunks as $chunk): ?>
                <div class="col-md-4">
                    <ul class="list-unstyled">
                        <?php foreach ($chunk as $header): ?>
                        <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars($header); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped table-sm">
                <thead>
                    <tr>
                        <?php foreach ($field_headers as $header): ?>
                        <th><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <?php foreach ($product as $value): ?>
                        <td><?php echo htmlspecialchars($value ?? ''); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_count > 10): ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle me-2"></i>
            This is a preview of the first 10 products. The actual export will include all <strong><?php echo number_format($total_count); ?> products</strong>.
        </div>
        <?php endif; ?>
        <?php
        
        $html = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'html' => $html,
            'total_count' => $total_count,
            'field_count' => count($field_headers)
        ]);

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
