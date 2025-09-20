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
if (!hasPermission('manage_inventory', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=access_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default settings if not set
$defaults = [
    'currency_symbol' => 'KES',
    'currency_position' => 'before',
    'currency_decimal_places' => '2'
];

foreach($defaults as $key => $value) {
    if(!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Helper function for currency formatting using admin settings
function formatCurrencyWithSettings($amount, $settings) {
    $symbol = $settings['currency_symbol'] ?? 'KES';
    $position = $settings['currency_position'] ?? 'before';
    $decimals = (int)($settings['currency_decimal_places'] ?? 2);

    $formatted_amount = number_format((float)$amount, $decimals);

    if ($position === 'after') {
        return $formatted_amount . ' ' . $symbol;
    } else {
        return $symbol . ' ' . $formatted_amount;
    }
}

// Get selected products and their custom quantities
$product_ids = $_GET['products'] ?? '';
$quantities_param = $_GET['quantities'] ?? '';
$custom_quantities = [];

if (!empty($quantities_param)) {
    $custom_quantities = json_decode(urldecode($quantities_param), true) ?? [];
}

if (empty($product_ids)) {
    header("Location: shelf_labels.php?error=no_products_selected");
    exit();
}

$product_ids_array = explode(',', $product_ids);
$placeholders = str_repeat('?,', count($product_ids_array) - 1) . '?';

$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name,
           CASE
               WHEN p.sale_price IS NOT NULL
                    AND (p.sale_start_date IS NULL OR p.sale_start_date <= NOW())
                    AND (p.sale_end_date IS NULL OR p.sale_end_date >= NOW())
               THEN 1 ELSE 0 END as is_on_sale
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE p.id IN ($placeholders)
    ORDER BY p.name ASC
");
$stmt->execute($product_ids_array);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle export
if ($_POST && isset($_POST['export_labels'])) {
    $export_format = $_POST['export_format'] ?? 'csv';
    
    if ($export_format === 'csv') {
        $filename = 'shelf_labels_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Product Name', 'SKU', 'Category', 'Original Price', 'Sale Price', 'Current Price', 'Labels to Print', 'Date']);

        foreach ($products as $product) {
            $product_id = $product['id'];
            $original_price = (float)($product['price'] ?? 0);
            $sale_price = $product['is_on_sale'] && !empty($product['sale_price']) ? (float)$product['sale_price'] : null;
            $current_price = $sale_price ?? $original_price;
            $labels_to_print = isset($custom_quantities[$product_id])
                ? max(1, (int)$custom_quantities[$product_id])
                : 1; // Default to 1 label if no custom quantity specified

            fputcsv($output, [
                $product['name'],
                $product['sku'] ?? 'N/A',
                $product['category_name'] ?? 'Uncategorized',
                formatCurrencyWithSettings($original_price, $settings),
                $sale_price ? formatCurrencyWithSettings($sale_price, $settings) : '',
                formatCurrencyWithSettings($current_price, $settings),
                $labels_to_print,
                date('Y-m-d')
            ]);
        }
        fclose($output);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Shelf Labels</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <h1>Export Shelf Labels</h1>
                <a href="shelf_labels.php" class="btn btn-outline-secondary">Back</a>
            </div>
        </header>

        <main class="content">
            <div class="card">
                <div class="card-body">
                    <h5>Export <?php echo count($products); ?> Products</h5>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Export Format</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="export_format" value="csv" id="csv" checked>
                                <label class="form-check-label" for="csv">CSV File</label>
                            </div>
                        </div>
                        
                        <button type="submit" name="export_labels" class="btn btn-primary">
                            <i class="bi bi-download me-2"></i>Export Labels
                        </button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
