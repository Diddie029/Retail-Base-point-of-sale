<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user permissions
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

// No duplicate function needed - using centralized function

// Check if user has permission to manage products
if (!hasPermission('manage_products', $permissions)) {
    http_response_code(403);
    echo "Access denied";
    exit();
}

// Handle different export types
$export_type = $_GET['type'] ?? 'all';
$format = $_GET['format'] ?? 'csv';

// Build query based on export type
$where_clause = '';
$params = [];

switch ($export_type) {
    case 'low_stock':
        $where_clause = 'WHERE p.quantity <= 10 AND p.quantity > 0';
        break;
    case 'out_of_stock':
        $where_clause = 'WHERE p.quantity = 0';
        break;
    case 'in_stock':
        $where_clause = 'WHERE p.quantity > 10';
        break;
    case 'category':
        if (isset($_GET['category_id'])) {
            $where_clause = 'WHERE p.category_id = :category_id';
            $params[':category_id'] = $_GET['category_id'];
        }
        break;
    default:
        $where_clause = '';
        break;
}

if ($format === 'csv') {
    // Set headers for CSV download
    $filename = 'products_' . $export_type . '_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Get products
    $sql = "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        $where_clause 
        ORDER BY p.name
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for proper UTF-8 handling in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    $headers = [
        'ID',
        'Name',
        'Category',
        'Price',
        'Sale Price',
        'Sale Start Date',
        'Sale End Date',
        'Tax Rate',
        'Quantity',
        'Barcode',
        'SKU',
        'Description',
        'Created Date',
        'Updated Date',
        'Stock Status'
    ];
    
    fputcsv($output, $headers);
    
    // CSV Data
    foreach ($products as $product) {
        // Determine stock status
        $stock_status = 'In Stock';
        if ($product['quantity'] == 0) {
            $stock_status = 'Out of Stock';
        } elseif ($product['quantity'] <= 10) {
            $stock_status = 'Low Stock';
        }
        
        $row = [
            $product['id'],
            $product['name'],
            $product['category_name'] ?? 'Uncategorized',
            number_format($product['price'], 2),
            !empty($product['sale_price']) ? number_format($product['sale_price'], 2) : '',
            $product['sale_start_date'] ?? '',
            $product['sale_end_date'] ?? '',
            !empty($product['tax_rate']) ? number_format($product['tax_rate'], 2) : '',
            $product['quantity'],
            $product['barcode'],
            $product['sku'] ?? '',
            $product['description'] ?? '',
            date('Y-m-d H:i:s', strtotime($product['created_at'])),
            date('Y-m-d H:i:s', strtotime($product['updated_at'])),
            $stock_status
        ];
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
} else {
    // Invalid format
    http_response_code(400);
    echo "Invalid format requested";
    exit();
}
?>