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
    $new_status = trim($_POST['new_status'] ?? '');

    // Debug logging
    error_log("Bulk status preview - POST data: " . json_encode($_POST));
    error_log("Bulk status preview - new_status: '$new_status'");

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

    if (!empty($filters['current_status'])) {
        $where_conditions[] = "p.status = :current_status";
        $params[':current_status'] = $filters['current_status'];
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
            case 'high_stock':
                $where_conditions[] = "p.quantity > 50";
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

    if (!empty($filters['created_after'])) {
        $where_conditions[] = "p.created_at >= :created_after";
        $params[':created_after'] = $filters['created_after'];
    }

    if (!empty($filters['created_before'])) {
        $where_conditions[] = "p.created_at <= :created_before . ' 23:59:59'";
        $params[':created_before'] = $filters['created_before'];
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get products that match the criteria with category, brand, and supplier names
    $select_sql = "
        SELECT
            p.id,
            p.name,
            p.sku,
            p.price,
            p.quantity,
            p.status as current_status,
            c.name as category_name,
            b.name as brand_name,
            s.name as supplier_name,
            p.created_at
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
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

    // Validate new status
    $valid_statuses = ['active', 'inactive', 'discontinued', 'blocked', 'Active', 'Inactive', 'Discontinued', 'Blocked'];
    if (empty($new_status)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please select a status (Active, Inactive, Discontinued, or Blocked) before previewing.'
        ]);
        exit();
    }

    if (!in_array($new_status, $valid_statuses)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid status selected. Please choose from: Active, Inactive, Discontinued, or Blocked.'
        ]);
        exit();
    }

    // Normalize status to lowercase
    $new_status = strtolower($new_status);

    // Count products by current status
    $status_counts = [
        'active' => 0,
        'inactive' => 0,
        'discontinued' => 0,
        'blocked' => 0
    ];

    foreach ($products as $product) {
        $status = $product['current_status'] ?? 'inactive';
        if (isset($status_counts[$status])) {
            $status_counts[$status]++;
        }
    }

    $total_products = count($products);
    $will_change_count = 0;

    // Count how many products will actually change status
    foreach ($products as $product) {
        if ($product['current_status'] !== $new_status) {
            $will_change_count++;
        }
    }

    // Generate HTML for preview
    $html = '<div class="table-responsive">';
    $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
    $html .= '<h5 class="mb-0">Found ' . $total_products . ' product' . ($total_products !== 1 ? 's' : '') . '</h5>';
    $html .= '<small class="text-muted">Showing first 50 products</small>';
    $html .= '</div>';

    // Status summary
    $html .= '<div class="row mb-4">';
    $html .= '<div class="col-md-12">';
    $html .= '<div class="alert alert-info">';
    $html .= '<h6><i class="fas fa-info-circle me-2"></i>Status Update Summary</h6>';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-4">';
    $html .= '<strong>Current Status Distribution:</strong><br>';
    foreach ($status_counts as $status => $count) {
        if ($count > 0) {
            $status_badge = getStatusBadge($status);
            $html .= "- $status_badge: $count products<br>";
        }
    }
    $html .= '</div>';
    $html .= '<div class="col-md-4">';
    $html .= '<strong>Proposed Change:</strong><br>';
    $new_status_badge = getStatusBadge($new_status);
    $html .= "Change all to: $new_status_badge";
    $html .= '</div>';
    $html .= '<div class="col-md-4">';
    $html .= '<strong>Impact:</strong><br>';
    $html .= "$will_change_count of $total_products products will be updated";
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<table class="table table-striped table-hover">';
    $html .= '<thead class="table-dark">';
    $html .= '<tr>';
    $html .= '<th>Product</th>';
    $html .= '<th>SKU</th>';
    $html .= '<th>Category</th>';
    $html .= '<th>Current Status</th>';
    $html .= '<th>New Status</th>';
    $html .= '<th>Stock</th>';
    $html .= '<th>Price</th>';
    $html .= '<th>Change</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';

    foreach ($products as $product) {
        $current_status = $product['current_status'] ?? 'inactive';
        $will_change = $current_status !== $new_status;

        $html .= '<tr' . ($will_change ? ' class="table-warning"' : '') . '>';
        $html .= '<td><strong>' . htmlspecialchars($product['name']) . '</strong></td>';
        $html .= '<td><code>' . htmlspecialchars($product['sku']) . '</code></td>';
        $html .= '<td>' . htmlspecialchars($product['category_name'] ?: 'N/A') . '</td>';
        $html .= '<td>' . getStatusBadge($current_status) . '</td>';
        $html .= '<td>' . getStatusBadge($new_status) . '</td>';
        $html .= '<td class="text-center">';
        $quantity = (int)($product['quantity'] ?? 0);
        $stock_class = $quantity === 0 ? 'text-danger' : ($quantity <= 10 ? 'text-warning' : 'text-success');
        $html .= '<span class="' . $stock_class . '">' . $quantity . '</span>';
        $html .= '</td>';
        $html .= '<td>$' . number_format($product['price'], 2) . '</td>';

        // Change indicator
        $html .= '<td class="text-center">';
        if ($will_change) {
            $html .= '<i class="fas fa-exchange-alt text-warning" title="Status will change"></i>';
        } else {
            $html .= '<i class="fas fa-check-circle text-success" title="No change needed"></i>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    // Add confirmation message
    $html .= '<div class="alert alert-warning mt-3">';
    $html .= '<h6><i class="fas fa-exclamation-triangle me-2"></i>Confirmation Required</h6>';
    $html .= '<p class="mb-0">This action will update the status of <strong>' . $will_change_count . '</strong> products from their current status to <strong>' . ucfirst($new_status) . '</strong>. Products that are already ' . $new_status . ' will not be changed.</p>';
    $html .= '</div>';

    echo json_encode([
        'success' => true,
        'html' => $html,
        'total_products' => $total_products,
        'will_change_count' => $will_change_count,
        'new_status' => $new_status
    ]);

} catch (Exception $e) {
    error_log("Bulk status preview error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing the preview: ' . $e->getMessage()
    ]);
}

/**
 * Generate Bootstrap badge for status
 */
function getStatusBadge($status) {
    $status = strtolower($status);

    switch ($status) {
        case 'active':
            return '<span class="badge bg-success">Active</span>';
        case 'inactive':
            return '<span class="badge bg-warning text-dark">Inactive</span>';
        case 'discontinued':
            return '<span class="badge bg-secondary">Discontinued</span>';
        case 'blocked':
            return '<span class="badge bg-danger">Blocked</span>';
        default:
            return '<span class="badge bg-light text-dark">' . ucfirst($status) . '</span>';
    }
}
?>
