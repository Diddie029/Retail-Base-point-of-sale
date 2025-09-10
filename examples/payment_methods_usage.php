<?php
/**
 * Example: How to use payment methods functions from db.php
 */

require_once __DIR__ . '/../include/db.php';

// Get all active payment methods (including loyalty points and cash)
$payment_methods = getPaymentMethods($conn);

echo "<h2>Available Payment Methods:</h2>";
echo "<ul>";
foreach ($payment_methods as $method) {
    echo "<li>";
    echo "<strong>{$method['display_name']}</strong> ";
    echo "({$method['name']}) - ";
    echo "Category: {$method['category']} - ";
    echo "Icon: {$method['icon']} - ";
    echo "Color: {$method['color']}";
    echo "</li>";
}
echo "</ul>";

// Get specific payment method by name
$cash_method = getPaymentMethodByName($conn, 'cash');
if ($cash_method) {
    echo "<h3>Cash Payment Method:</h3>";
    echo "<pre>" . print_r($cash_method, true) . "</pre>";
}

$loyalty_method = getPaymentMethodByName($conn, 'loyalty_points');
if ($loyalty_method) {
    echo "<h3>Loyalty Points Payment Method:</h3>";
    echo "<pre>" . print_r($loyalty_method, true) . "</pre>";
}

// Example: Add a new payment method
$new_method_data = [
    'name' => 'mobile_money',
    'display_name' => 'Mobile Money',
    'description' => 'Pay via mobile money',
    'category' => 'digital',
    'icon' => 'bi-phone',
    'color' => '#17a2b8',
    'is_active' => 1,
    'requires_reconciliation' => 1,
    'sort_order' => 5
];

// Uncomment to add new payment method
// if (savePaymentMethod($conn, $new_method_data)) {
//     echo "<p>✅ Mobile Money payment method added successfully!</p>";
// } else {
//     echo "<p>❌ Failed to add Mobile Money payment method.</p>";
// }
?>
