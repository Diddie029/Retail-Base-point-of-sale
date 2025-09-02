<?php
/**
 * Walk-in Customer Usage Examples
 * This file demonstrates how to use the walk-in customer in various scenarios
 */

require_once '../include/db.php';
require_once '../include/functions.php';

// Example 1: Basic walk-in customer retrieval
echo "<h2>Example 1: Basic Walk-in Customer Retrieval</h2>";
$walk_in_customer = getWalkInCustomer($conn);

if ($walk_in_customer) {
    echo "<p>Walk-in Customer Details:</p>";
    echo "<ul>";
    echo "<li>ID: {$walk_in_customer['id']}</li>";
    echo "<li>Name: {$walk_in_customer['first_name']} {$walk_in_customer['last_name']}</li>";
    echo "<li>Customer Number: {$walk_in_customer['customer_number']}</li>";
    echo "<li>Type: {$walk_in_customer['customer_type']}</li>";
    echo "</ul>";
} else {
    echo "<p>Walk-in customer not found</p>";
}

// Example 2: Using walk-in customer in a sale transaction
echo "<h2>Example 2: POS Transaction with Walk-in Customer</h2>";

function createSaleWithWalkInCustomer($conn, $product_id, $quantity, $user_id) {
    try {
        // Get walk-in customer
        $walk_in_id = getWalkInCustomerId($conn);

        if (!$walk_in_id) {
            return ['success' => false, 'message' => 'Walk-in customer not available'];
        }

        // Calculate total (simplified - in real scenario, get from products table)
        $total = 100.00; // Example price

        // Create sale record
        $stmt = $conn->prepare("
            INSERT INTO sales (user_id, customer_name, customer_phone, customer_email, total_amount, final_amount, payment_method, created_at)
            VALUES (?, 'Walk-in Customer', '', '', ?, ?, 'cash', NOW())
        ");

        $stmt->execute([$user_id, $total, $total]);

        $sale_id = $conn->lastInsertId();

        // Create sale item (simplified)
        $item_stmt = $conn->prepare("
            INSERT INTO sale_items (sale_id, product_id, quantity, price)
            VALUES (?, ?, ?, ?)
        ");

        $item_stmt->execute([$sale_id, $product_id, $quantity, $total / $quantity]);

        return [
            'success' => true,
            'sale_id' => $sale_id,
            'message' => 'Sale created successfully with walk-in customer'
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Example usage (commented out to prevent actual database writes)
/*
$result = createSaleWithWalkInCustomer($conn, 1, 2, 1);
if ($result['success']) {
    echo "<p>Sale created with ID: {$result['sale_id']}</p>";
} else {
    echo "<p>Error: {$result['message']}</p>";
}
*/

// Example 3: Checking customer type before operations
echo "<h2>Example 3: Customer Type Validation</h2>";

function processCustomerTransaction($customer_id, $conn) {
    // Check if this is a walk-in customer
    if (isWalkInCustomer($customer_id, $conn)) {
        return [
            'type' => 'walk_in',
            'message' => 'Processing walk-in customer transaction',
            'skip_loyalty' => false, // Walk-in customers can still earn loyalty points
            'skip_marketing' => true  // Don't send marketing emails to walk-in
        ];
    } else {
        return [
            'type' => 'registered',
            'message' => 'Processing registered customer transaction',
            'skip_loyalty' => false,
            'skip_marketing' => false
        ];
    }
}

// Example usage
$walk_in_id = getWalkInCustomerId($conn);
if ($walk_in_id) {
    $transaction_info = processCustomerTransaction($walk_in_id, $conn);
    echo "<p>Transaction Type: {$transaction_info['type']}</p>";
    echo "<p>Message: {$transaction_info['message']}</p>";
}

// Example 4: API integration example
echo "<h2>Example 4: API Integration</h2>";
echo "<p>To integrate with external POS systems, use the API endpoint:</p>";
echo "<code>GET /api/get_walk_in_customer.php</code>";
echo "<p>This returns JSON data that can be used in mobile apps, web POS, or third-party integrations.</p>";

// Example 5: Bulk operations with walk-in customer
echo "<h2>Example 5: Bulk Operations</h2>";

function getAllNonWalkInCustomers($conn) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_type != 'walk_in' ORDER BY first_name");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$regular_customers = getAllNonWalkInCustomers($conn);
echo "<p>Found " . count($regular_customers) . " regular customers (excluding walk-in)</p>";

// Example 6: Reporting with walk-in customer
echo "<h2>Example 6: Reporting Examples</h2>";

function getSalesByCustomerType($conn) {
    $stmt = $conn->query("
        SELECT
            CASE
                WHEN c.customer_type = 'walk_in' THEN 'Walk-in'
                ELSE 'Registered'
            END as customer_type,
            COUNT(s.id) as total_sales,
            SUM(s.final_amount) as total_amount
        FROM sales s
        LEFT JOIN customers c ON (
            CASE
                WHEN s.customer_name = 'Walk-in Customer' THEN c.customer_type = 'walk_in'
                ELSE c.id IS NULL
            END
        )
        GROUP BY customer_type
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Example usage (commented out)
/*
$sales_report = getSalesByCustomerType($conn);
echo "<p>Sales by Customer Type:</p>";
echo "<ul>";
foreach ($sales_report as $report) {
    echo "<li>{$report['customer_type']}: {$report['total_sales']} sales, $" . number_format($report['total_amount'], 2) . "</li>";
}
echo "</ul>";
*/

echo "<h2>Summary</h2>";
echo "<p>The walk-in customer system provides:</p>";
echo "<ul>";
echo "<li>✅ Automatic default customer for all transactions</li>";
echo "<li>✅ Seamless integration with existing POS workflows</li>";
echo "<li>✅ Protected system customer that cannot be accidentally deleted</li>";
echo "<li>✅ API endpoints for external system integration</li>";
echo "<li>✅ Complete audit trail and reporting capabilities</li>";
echo "<li>✅ Flexible usage patterns for different business needs</li>";
echo "</ul>";

?>
