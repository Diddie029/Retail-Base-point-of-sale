<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Only handle preview action
if (!isset($_POST['action']) || $_POST['action'] !== 'preview') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

try {
    $filters = $_POST['filters'] ?? [];
    $pricing = $_POST['pricing'] ?? [];

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

    if (!empty($filters['price_range_min'])) {
        $where_conditions[] = "p.price >= :price_min";
        $params[':price_min'] = $filters['price_range_min'];
    }

    if (!empty($filters['price_range_max'])) {
        $where_conditions[] = "p.price <= :price_max";
        $params[':price_max'] = $filters['price_range_max'];
    }

    if (!empty($filters['status'])) {
        $where_conditions[] = "p.status = :status";
        $params[':status'] = $filters['status'];
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get products that match the criteria with category and brand names
    $select_sql = "
        SELECT
            p.id,
            p.name,
            p.sku,
            p.price,
            p.sale_price,
            c.name as category_name,
            b.name as brand_name,
            p.status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        $where_clause
        ORDER BY p.name
        LIMIT 50
    ";

    $select_stmt = $conn->prepare($select_sql);
    foreach ($params as $key => $value) {
        $select_stmt->bindValue($key, $value);
    }
    $select_stmt->execute();
    $products = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        echo json_encode([
            'success' => false,
            'message' => 'No products found matching the specified criteria.'
        ]);
        exit();
    }

    // Calculate pricing changes
    $pricing_type = $pricing['type'] ?? '';
    $pricing_value = (float)($pricing['value'] ?? 0);
    $sale_type = $pricing['sale_type'] ?? '';

    // Extract sale value based on sale type
    $sale_value = 0;
    if (!empty($sale_type)) {
        switch ($sale_type) {
            case 'percentage_off':
                $sale_value = (float)($pricing['sale_percentage'] ?? 0);
                break;
            case 'fixed_discount':
                $sale_value = (float)($pricing['sale_fixed'] ?? 0);
                break;
            case 'set_sale_price':
                $sale_value = (float)($pricing['sale_set'] ?? 0);
                break;
        }
    }

    $preview_data = [];
    $total_products = count($products);

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
        if (!empty($sale_type) && !empty($pricing['sale_value'])) {
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

        $preview_data[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'],
            'current_price' => $product['price'],
            'current_sale_price' => $product['sale_price'],
            'new_price' => $new_price,
            'new_sale_price' => $new_sale_price,
            'category_name' => $product['category_name'],
            'brand_name' => $product['brand_name'],
            'status' => $product['status']
        ];
    }

    // Generate HTML for preview
    $html = '<div class="table-responsive">';
    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<h5 class="mb-0">Found ' . $total_products . ' product' . ($total_products !== 1 ? 's' : '') . '</h5>';
    $html .= '<small class="text-muted">Showing first 50 products</small>';
    $html .= '</div>';

    $html .= '<table class="table table-striped table-hover">';
    $html .= '<thead class="table-dark">';
    $html .= '<tr>';
    $html .= '<th>Product</th>';
    $html .= '<th>SKU</th>';
    $html .= '<th>Category</th>';
    $html .= '<th>Current Price</th>';
    $html .= '<th>New Price</th>';
    $html .= '<th>Sale Price</th>';
    $html .= '<th>Change</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';

    foreach ($preview_data as $product) {
        $price_change = $product['new_price'] - $product['current_price'];
        $change_color = $price_change > 0 ? 'text-success' : ($price_change < 0 ? 'text-danger' : 'text-muted');
        $change_symbol = $price_change > 0 ? '+' : '';

        $html .= '<tr>';
        $html .= '<td><strong>' . htmlspecialchars($product['name']) . '</strong></td>';
        $html .= '<td><code>' . htmlspecialchars($product['sku']) . '</code></td>';
        $html .= '<td>' . htmlspecialchars($product['category_name'] ?: 'N/A') . '</td>';
        $html .= '<td>$' . number_format($product['current_price'], 2) . '</td>';
        $html .= '<td><strong>$' . number_format($product['new_price'], 2) . '</strong></td>';

        // Sale price column
        if ($product['new_sale_price'] !== null) {
            $html .= '<td>$' . number_format($product['new_sale_price'], 2) . '</td>';
        } else {
            $html .= '<td><span class="text-muted">-</span></td>';
        }

        // Change column
        $html .= '<td class="' . $change_color . '">' . $change_symbol . '$' . number_format($price_change, 2) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    // Add summary
    $total_current_value = array_sum(array_column($preview_data, 'current_price'));
    $total_new_value = array_sum(array_column($preview_data, 'new_price'));
    $total_change = $total_new_value - $total_current_value;

    $html .= '<div class="alert alert-info mt-3">';
    $html .= '<h6><i class="fas fa-chart-line me-2"></i>Summary</h6>';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-4">';
    $html .= '<strong>Total Current Value:</strong> $' . number_format($total_current_value, 2);
    $html .= '</div>';
    $html .= '<div class="col-md-4">';
    $html .= '<strong>Total New Value:</strong> $' . number_format($total_new_value, 2);
    $html .= '</div>';
    $html .= '<div class="col-md-4">';
    $change_class = $total_change >= 0 ? 'text-success' : 'text-danger';
    $change_prefix = $total_change >= 0 ? '+' : '';
    $html .= '<strong class="' . $change_class . '">Total Change: ' . $change_prefix . '$' . number_format($total_change, 2) . '</strong>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    echo json_encode([
        'success' => true,
        'html' => $html,
        'total_products' => $total_products
    ]);

} catch (Exception $e) {
    error_log("Bulk pricing preview error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing the preview: ' . $e->getMessage()
    ]);
}
?>
