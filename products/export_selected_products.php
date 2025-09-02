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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get selected product IDs from session
$selectedProductIds = isset($_SESSION['export_product_ids']) ? $_SESSION['export_product_ids'] : [];

if (empty($selectedProductIds)) {
    header("Location: products.php");
    exit();
}

// Get selected products data
$placeholders = str_repeat('?,', count($selectedProductIds) - 1) . '?';
$stmt = $conn->prepare("
    SELECT
        p.*,
        c.name as category_name,
        b.name as brand_name,
        s.name as supplier_name,
        p.is_auto_bom_enabled,
        p.auto_bom_type,
        COUNT(DISTINCT su.id) as selling_units_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id AND su.status = 'active'
    WHERE p.id IN ($placeholders)
    GROUP BY p.id
    ORDER BY p.name ASC
");
$stmt->execute($selectedProductIds);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export format
$exportFormat = $_GET['format'] ?? 'csv';

// Generate filename
$timestamp = date('Y-m-d_H-i-s');
$filename = "selected_products_export_{$timestamp}";

// Clear the session data
unset($_SESSION['export_product_ids']);

if ($exportFormat === 'excel' || $exportFormat === 'xlsx') {
    // Excel export
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    echo "<table border='1'>\n";
    echo "<tr>\n";
    echo "<th>ID</th>\n";
    echo "<th>Name</th>\n";
    echo "<th>SKU</th>\n";
    echo "<th>Barcode</th>\n";
    echo "<th>Category</th>\n";
    echo "<th>Brand</th>\n";
    echo "<th>Supplier</th>\n";
    echo "<th>Cost Price</th>\n";
    echo "<th>Selling Price</th>\n";
    echo "<th>Stock Quantity</th>\n";
    echo "<th>Minimum Stock</th>\n";
    echo "<th>Status</th>\n";
    echo "<th>Auto BOM Enabled</th>\n";
    echo "<th>Auto BOM Type</th>\n";
    echo "<th>Selling Units</th>\n";
    echo "<th>Description</th>\n";
    echo "<th>Created Date</th>\n";
    echo "</tr>\n";

    foreach ($products as $product) {
        echo "<tr>\n";
        echo "<td>" . htmlspecialchars($product['id']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['name']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['sku'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['barcode'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['category_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['brand_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['supplier_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['cost_price']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['price']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['quantity']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['minimum_stock'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars(ucfirst($product['status'])) . "</td>\n";
        echo "<td>" . ($product['is_auto_bom_enabled'] ? 'Yes' : 'No') . "</td>\n";
        echo "<td>" . htmlspecialchars(ucfirst(str_replace('_', ' ', $product['auto_bom_type'] ?? ''))) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['selling_units_count']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['description'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars(date('Y-m-d', strtotime($product['created_at']))) . "</td>\n";
        echo "</tr>\n";
    }

    echo "</table>\n";

} elseif ($exportFormat === 'pdf') {
    // Simple HTML to PDF (you might want to use a proper PDF library)
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '.html"');

    echo "<!DOCTYPE html>\n";
    echo "<html>\n<head>\n";
    echo "<title>Selected Products Export</title>\n";
    echo "<style>\n";
    echo "body { font-family: Arial, sans-serif; margin: 20px; }\n";
    echo "table { width: 100%; border-collapse: collapse; margin-top: 20px; }\n";
    echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }\n";
    echo "th { background-color: #f2f2f2; font-weight: bold; }\n";
    echo "tr:nth-child(even) { background-color: #f9f9f9; }\n";
    echo "h1 { color: #333; }\n";
    echo "</style>\n";
    echo "</head>\n<body>\n";
    echo "<h1>Selected Products Export</h1>\n";
    echo "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>\n";
    echo "<p>Total Products: " . count($products) . "</p>\n";

    echo "<table>\n";
    echo "<thead>\n<tr>\n";
    echo "<th>ID</th>\n";
    echo "<th>Name</th>\n";
    echo "<th>SKU</th>\n";
    echo "<th>Barcode</th>\n";
    echo "<th>Category</th>\n";
    echo "<th>Brand</th>\n";
    echo "<th>Supplier</th>\n";
    echo "<th>Cost Price</th>\n";
    echo "<th>Selling Price</th>\n";
    echo "<th>Stock Quantity</th>\n";
    echo "<th>Minimum Stock</th>\n";
    echo "<th>Status</th>\n";
    echo "<th>Auto BOM</th>\n";
    echo "<th>Description</th>\n";
    echo "<th>Created Date</th>\n";
    echo "</tr>\n</thead>\n";
    echo "<tbody>\n";

    foreach ($products as $product) {
        echo "<tr>\n";
        echo "<td>" . htmlspecialchars($product['id']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['name']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['sku'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['barcode'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['category_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['brand_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['supplier_name'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['cost_price']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['price']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['quantity']) . "</td>\n";
        echo "<td>" . htmlspecialchars($product['minimum_stock'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars(ucfirst($product['status'])) . "</td>\n";
        echo "<td>" . ($product['is_auto_bom_enabled'] ? 'Yes (' . ucfirst(str_replace('_', ' ', $product['auto_bom_type'] ?? '')) . ')' : 'No') . "</td>\n";
        echo "<td>" . htmlspecialchars($product['description'] ?? '') . "</td>\n";
        echo "<td>" . htmlspecialchars(date('Y-m-d', strtotime($product['created_at']))) . "</td>\n";
        echo "</tr>\n";
    }

    echo "</tbody>\n</table>\n";
    echo "</body>\n</html>\n";

} else {
    // Default CSV export
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');

    // Output CSV headers
    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'ID',
        'Name',
        'SKU',
        'Barcode',
        'Category',
        'Brand',
        'Supplier',
        'Cost Price',
        'Selling Price',
        'Stock Quantity',
        'Minimum Stock',
        'Status',
        'Auto BOM Enabled',
        'Auto BOM Type',
        'Selling Units Count',
        'Description',
        'Created Date'
    ]);

    // CSV data
    foreach ($products as $product) {
        fputcsv($output, [
            $product['id'],
            $product['name'],
            $product['sku'] ?? '',
            $product['barcode'] ?? '',
            $product['category_name'] ?? '',
            $product['brand_name'] ?? '',
            $product['supplier_name'] ?? '',
            $product['cost_price'],
            $product['price'],
            $product['quantity'],
            $product['minimum_stock'] ?? '',
            ucfirst($product['status']),
            $product['is_auto_bom_enabled'] ? 'Yes' : 'No',
            ucfirst(str_replace('_', ' ', $product['auto_bom_type'] ?? '')),
            $product['selling_units_count'],
            $product['description'] ?? '',
            date('Y-m-d', strtotime($product['created_at']))
        ]);
    }

    fclose($output);
}

// Log the export activity
logActivity($conn, $user_id, 'export_selected_products', "Exported " . count($products) . " selected products in $exportFormat format");

exit();
?>
