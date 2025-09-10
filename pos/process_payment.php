<?php
// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Check if till is selected
if (!isset($_SESSION['selected_till_id']) || empty($_SESSION['selected_till_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select a till before processing payment']);
    exit();
}

try {
    // Get payment data from request
    $input = file_get_contents('php://input');
    $paymentData = json_decode($input, true);

    if (!$paymentData) {
        throw new Exception('Invalid payment data');
    }

    // Validate required fields
    $requiredFields = ['method', 'amount', 'subtotal', 'tax', 'items'];
    foreach ($requiredFields as $field) {
        if (!isset($paymentData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Generate transaction ID
        $transaction_id = generateTransactionId();
        
        // Create sale record
        $stmt = $conn->prepare("
            INSERT INTO sales (
                user_id, till_id, customer_id, customer_name, customer_phone, customer_email, 
                total_amount, discount, tax_amount, final_amount, 
                payment_method, notes, sale_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $customer_id = $paymentData['customer_id'] ?? null;
        $customer_name = $paymentData['customer_name'] ?? 'Walk-in Customer';
        $customer_phone = $paymentData['customer_phone'] ?? '';
        $customer_email = $paymentData['customer_email'] ?? '';
        $customer_type = $paymentData['customer_type'] ?? 'walk_in';
        $tax_exempt = $paymentData['tax_exempt'] ?? false;
        $discount = $paymentData['discount'] ?? 0;
        $notes = $paymentData['notes'] ?? '';

        $stmt->execute([
            $user_id,
            $_SESSION['selected_till_id'],
            $customer_id,
            $customer_name,
            $customer_phone,
            $customer_email,
            $paymentData['amount'],
            $discount,
            $paymentData['tax'],
            $paymentData['amount'],
            $paymentData['method'],
            $notes
        ]);

        $sale_id = $conn->lastInsertId();

        // Create sale items
        $stmt = $conn->prepare("
            INSERT INTO sale_items (
                sale_id, product_id, product_name, quantity, unit_price, price, total_price
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (empty($paymentData['items']) || !is_array($paymentData['items'])) {
            throw new Exception("No items provided for sale");
        }

        foreach ($paymentData['items'] as $item) {
            $product_id = $item['product_id'] ?? null;
            $product_name = $item['name'] ?? 'Unknown Item';
            $quantity = $item['quantity'] ?? 1;
            $unit_price = $item['price'] ?? 0;
            $total_price = $unit_price * $quantity;

            // Validate required fields
            if (empty($product_name)) {
                throw new Exception("Product name is required for all items");
            }
            if ($quantity <= 0) {
                throw new Exception("Quantity must be greater than 0");
            }
            if ($unit_price < 0) {
                throw new Exception("Unit price cannot be negative");
            }

            // Log the data being inserted for debugging
            error_log("Inserting sale item: sale_id=$sale_id, product_id=" . ($product_id ?? 'NULL') . ", product_name=$product_name, quantity=$quantity, unit_price=$unit_price, total_price=$total_price");

            try {
                $stmt->execute([
                    $sale_id,
                    $product_id,
                    $product_name,
                    $quantity,
                    $unit_price,
                    $unit_price,
                    $total_price
                ]);
                error_log("Successfully inserted sale item with product_id=" . ($product_id ?? 'NULL'));
            } catch (PDOException $e) {
                error_log("Error inserting sale item: " . $e->getMessage());
                error_log("Item data: " . json_encode($item));
                error_log("Processed data: sale_id=$sale_id, product_id=" . ($product_id ?? 'NULL') . ", product_name=$product_name, quantity=$quantity, unit_price=$unit_price, total_price=$total_price");
                
                // Check if it's a constraint violation
                if (strpos($e->getMessage(), 'product_id') !== false && strpos($e->getMessage(), 'cannot be null') !== false) {
                    error_log("Database constraint error detected. Attempting to fix product_id column...");
                    
                    // Try to fix the database constraint
                    try {
                        $conn->exec("ALTER TABLE sale_items DROP FOREIGN KEY IF EXISTS sale_items_ibfk_2");
                        $conn->exec("ALTER TABLE sale_items MODIFY COLUMN product_id INT NULL");
                        $conn->exec("ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");
                        error_log("Database constraint fixed. Retrying insert...");
                        
                        // Retry the insert
                        $stmt->execute([
                            $sale_id,
                            $product_id,
                            $product_name,
                            $quantity,
                            $unit_price,
                            $unit_price,
                            $total_price
                        ]);
                        error_log("Successfully inserted sale item after constraint fix");
                    } catch (Exception $fixError) {
                        error_log("Failed to fix database constraint: " . $fixError->getMessage());
                        throw new Exception("Failed to add item to sale: " . $e->getMessage());
                    }
                } else {
                    throw new Exception("Failed to add item to sale: " . $e->getMessage());
                }
            }

            // Update product inventory if product_id exists
            if ($product_id) {
                $updateStmt = $conn->prepare("
                    UPDATE products 
                    SET quantity = quantity - ? 
                    WHERE id = ? AND track_inventory = 1
                ");
                $updateStmt->execute([$quantity, $product_id]);
            }
        }

        // Get loyalty settings for the entire transaction
        $loyaltySettings = getLoyaltySettings($conn);
        
        // Handle loyalty points redemption if applicable
        $loyaltyPointsUsed = 0;
        $loyaltyDiscount = 0;
        $finalAmount = $paymentData['amount'];
        
        if (isset($paymentData['use_loyalty_points']) && $paymentData['use_loyalty_points'] && $customer_id) {
            $loyaltyPointsToUse = $paymentData['loyalty_points_to_use'] ?? 0;
            
            if ($loyaltyPointsToUse > 0) {
                // Check if customer has enough points
                $currentBalance = getCustomerLoyaltyBalance($conn, $customer_id);
                
                if ($currentBalance >= $loyaltyPointsToUse) {
                    // Get conversion rate from loyalty settings
                    $pointsToCurrencyRate = $loyaltySettings['points_to_currency_rate'] ?? 100;
                    
                    // Calculate discount from loyalty points using proper rate
                    $loyaltyDiscount = calculateLoyaltyPointsValue($loyaltyPointsToUse, $pointsToCurrencyRate);
                    
                    // Ensure discount doesn't exceed total amount
                    if ($loyaltyDiscount > $finalAmount) {
                        $loyaltyDiscount = $finalAmount;
                        $loyaltyPointsToUse = (int)($loyaltyDiscount * $pointsToCurrencyRate); // Convert back to points
                    }
                    
                    // Redeem loyalty points
                    if (redeemLoyaltyPoints($conn, $customer_id, $loyaltyPointsToUse, 
                        "Redeemed for purchase #$sale_id", $transaction_id)) {
                        $loyaltyPointsUsed = $loyaltyPointsToUse;
                        $finalAmount = $paymentData['amount'] - $loyaltyDiscount;
                    }
                }
            }
        }

        // Create payment record
        $stmt = $conn->prepare("
            INSERT INTO sale_payments (
                sale_id, payment_method, amount, reference, received_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");

        $payment_reference = null;

        // Set payment-specific data
        if ($paymentData['method'] === 'mobile_money') {
            $payment_reference = generatePaymentReference('mobile');
        } elseif (in_array($paymentData['method'], ['credit_card', 'debit_card', 'pos_card'])) {
            $payment_reference = generatePaymentReference('card');
        } elseif ($paymentData['method'] === 'cash') {
            $payment_reference = generatePaymentReference('cash');
        } elseif ($paymentData['method'] === 'loyalty_points') {
            $payment_reference = generatePaymentReference('loyalty');
        }

        $stmt->execute([
            $sale_id,
            $paymentData['method'],
            $finalAmount,
            $payment_reference
        ]);

        // Award loyalty points if customer is not walk-in and loyalty program is enabled
        $loyaltyPointsEarned = 0;
        if ($customer_id && $customer_type !== 'walk_in') {
            if ($loyaltySettings['enable_loyalty_program']) {
                $customer = getCustomerById($conn, $customer_id);
                if ($customer) {
                    $loyaltyPointsEarned = calculateLoyaltyPoints($conn, $finalAmount, $customer['membership_level']);
                    
                    if ($loyaltyPointsEarned > 0) {
                        addLoyaltyPoints($conn, $customer_id, $loyaltyPointsEarned, 
                            "Points earned from purchase #$sale_id", $transaction_id);
                    }
                }
            }
        }

        // Clear cart from session
        unset($_SESSION['cart']);

        // Commit transaction
        $conn->commit();

        // Generate sequential receipt ID
        $receipt_id = generateSequentialReceiptId($conn);

        // Return success response with cart totals
        echo json_encode([
            'success' => true,
            'transaction_id' => $transaction_id,
            'sale_id' => $sale_id,
            'receipt_id' => $receipt_id,
            'message' => 'Payment processed successfully',
            'subtotal' => $paymentData['subtotal'],
            'tax' => $paymentData['tax'],
            'amount' => $paymentData['amount'],
            'final_amount' => $finalAmount,
            'items' => $paymentData['items'],
            'method' => $paymentData['method'],
            'cash_received' => $paymentData['cash_received'] ?? null,
            'change_due' => $paymentData['change_due'] ?? null,
            'loyalty' => [
                'points_earned' => $loyaltyPointsEarned,
                'points_used' => $loyaltyPointsUsed,
                'discount_applied' => $loyaltyDiscount,
                'customer_balance' => $customer_id ? getCustomerLoyaltyBalance($conn, $customer_id) : 0,
                'program_enabled' => $loyaltySettings['enable_loyalty_program'] ?? false
            ]
        ]);

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Payment processing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Generate unique transaction ID (random with characters)
 */
function generateTransactionId() {
    // Get settings from database
    global $conn;
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $prefix = $settings['transaction_id_prefix'] ?? 'TXN';
    $length = (int)($settings['transaction_id_length'] ?? 6);
    $format = $settings['transaction_id_format'] ?? 'prefix-random';
    
    // Define character sets based on format
    $characters = '';
    switch($format) {
        case 'prefix-random':
        case 'random-only':
        case 'prefix-date-random':
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            break;
        case 'prefix-mixed':
        case 'mixed-only':
        case 'prefix-date-mixed':
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            break;
        case 'prefix-lowercase':
        case 'lowercase-only':
        case 'prefix-date-lowercase':
            $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
            break;
        case 'prefix-numbers':
        case 'numbers-only':
        case 'prefix-date-numbers':
            $characters = '0123456789';
            break;
        case 'prefix-letters':
        case 'letters-only':
        case 'prefix-date-letters':
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            break;
        default:
            $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    }
    
    // Generate random string
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    switch($format) {
        case 'prefix-random':
        case 'prefix-mixed':
        case 'prefix-lowercase':
        case 'prefix-numbers':
        case 'prefix-letters':
            return $prefix . $randomString;
        case 'random-only':
        case 'mixed-only':
        case 'lowercase-only':
        case 'numbers-only':
        case 'letters-only':
            return $randomString;
        case 'prefix-date-random':
        case 'prefix-date-mixed':
        case 'prefix-date-lowercase':
        case 'prefix-date-numbers':
        case 'prefix-date-letters':
            $currentDate = date('Ymd');
            return $prefix . $currentDate . $randomString;
        default:
            return $prefix . $randomString;
    }
}

/**
 * Generate payment reference
 */
function generatePaymentReference($type) {
    $prefixes = [
        'mobile' => 'MM',
        'card' => 'CARD',
        'cash' => 'CASH',
        'bank' => 'BANK'
    ];
    
    $prefix = $prefixes[$type] ?? 'PAY';
    $timestamp = date('YmdHis');
    $random = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    return $prefix . $timestamp . $random;
}

?>
