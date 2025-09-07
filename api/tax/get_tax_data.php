<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get tax categories
    $stmt = $conn->query("
        SELECT id, name, description 
        FROM tax_categories 
        WHERE is_active = 1 
        ORDER BY name
    ");
    $tax_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tax rates grouped by category
    $stmt = $conn->query("
        SELECT tr.*, tc.name as category_name
        FROM tax_rates tr
        JOIN tax_categories tc ON tr.tax_category_id = tc.id
        WHERE tr.is_active = 1
        ORDER BY tc.name, tr.effective_date DESC
    ");
    $tax_rates_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group tax rates by category
    $tax_rates = [];
    foreach ($tax_rates_raw as $rate) {
        $category_id = $rate['tax_category_id'];
        if (!isset($tax_rates[$category_id])) {
            $tax_rates[$category_id] = [];
        }
        $tax_rates[$category_id][] = $rate;
    }

    // Get customer exemptions
    $stmt = $conn->query("
        SELECT customer_id, exemption_type, exemption_reason, certificate_number, 
               effective_date, end_date, is_active
        FROM tax_exemptions 
        WHERE exemption_type = 'customer' AND is_active = 1
    ");
    $customer_exemptions_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group customer exemptions by customer ID
    $customer_exemptions = [];
    foreach ($customer_exemptions_raw as $exemption) {
        $customer_id = $exemption['customer_id'];
        if (!isset($customer_exemptions[$customer_id])) {
            $customer_exemptions[$customer_id] = [];
        }
        $customer_exemptions[$customer_id][] = $exemption;
    }

    // Get product exemptions
    $stmt = $conn->query("
        SELECT product_id, exemption_type, exemption_reason, certificate_number, 
               effective_date, end_date, is_active
        FROM tax_exemptions 
        WHERE exemption_type = 'product' AND is_active = 1
    ");
    $product_exemptions_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group product exemptions by product ID
    $product_exemptions = [];
    foreach ($product_exemptions_raw as $exemption) {
        $product_id = $exemption['product_id'];
        if (!isset($product_exemptions[$product_id])) {
            $product_exemptions[$product_id] = [];
        }
        $product_exemptions[$product_id][] = $exemption;
    }

    echo json_encode([
        'success' => true,
        'tax_categories' => $tax_categories,
        'tax_rates' => $tax_rates,
        'customer_exemptions' => $customer_exemptions,
        'product_exemptions' => $product_exemptions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load tax data: ' . $e->getMessage()
    ]);
}
