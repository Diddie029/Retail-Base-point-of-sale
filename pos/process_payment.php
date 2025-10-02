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

// Check POS authentication
if (!isPOSAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'POS authentication required']);
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

    // Validate required fields - support both single and split payments
    $requiredFields = ['amount', 'subtotal', 'tax', 'items'];
    foreach ($requiredFields as $field) {
        if (!isset($paymentData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Validate payment methods - support both single method and split payments
    $isSplitPayment = isset($paymentData['split_payments']) && is_array($paymentData['split_payments']);
    if ($isSplitPayment) {
        if (empty($paymentData['split_payments'])) {
            throw new Exception("Split payments array cannot be empty");
        }

        // Validate each split payment
        $totalSplitAmount = 0;
        foreach ($paymentData['split_payments'] as $index => $splitPayment) {
            if (!isset($splitPayment['method']) || !isset($splitPayment['amount'])) {
                throw new Exception("Split payment $index missing method or amount");
            }
            if ($splitPayment['amount'] <= 0) {
                throw new Exception("Split payment $index amount must be greater than 0");
            }
            $totalSplitAmount += $splitPayment['amount'];
        }

        // Verify split payments total matches transaction total
        if (abs($totalSplitAmount - $paymentData['amount']) > 0.01) {
            throw new Exception("Split payments total (" . number_format($totalSplitAmount, 2) . ") does not match transaction total (" . number_format($paymentData['amount'], 2) . ")");
        }
    } else {
        // Single payment - ensure method is provided
        if (!isset($paymentData['method'])) {
            throw new Exception("Missing required field: method");
        }
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Generate transaction ID
        $transaction_id = generateTransactionId();
        
        // Generate receipt ID first
        $receipt_id = generateSequentialReceiptId($conn);
        
        // Create sale record
        $stmt = $conn->prepare("
            INSERT INTO sales (
                user_id, till_id, quotation_id, receipt_id, customer_id, customer_name, customer_phone, customer_email,
                total_amount, discount, tax_amount, final_amount,
                payment_method, split_payment, notes, sale_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $customer_id = $paymentData['customer_id'] ?? null;
        $customer_name = $paymentData['customer_name'] ?? 'Walk-in Customer';
        $customer_phone = $paymentData['customer_phone'] ?? '';
        $customer_email = $paymentData['customer_email'] ?? '';
        $customer_type = $paymentData['customer_type'] ?? 'walk_in';
        $tax_exempt = $paymentData['tax_exempt'] ?? false;
        $discount = $paymentData['discount'] ?? 0;
        $notes = $paymentData['notes'] ?? '';

        // Determine payment method for sales record
        $primaryPaymentMethod = $isSplitPayment ? 'split_payment' : $paymentData['method'];

        $quotation_id = $paymentData['quotation_id'] ?? null;
        
        $stmt->execute([
            $user_id,
            $_SESSION['selected_till_id'],
            $quotation_id,
            $receipt_id,
            $customer_id,
            $customer_name,
            $customer_phone,
            $customer_email,
            $paymentData['amount'],
            $discount,
            $paymentData['tax'],
            $paymentData['amount'],
            $primaryPaymentMethod,
            $isSplitPayment ? 1 : 0,
            $notes
        ]);

        $sale_id = $conn->lastInsertId();

        // Check if this sale came from a quotation and update quotation status
        if (isset($paymentData['quotation_id']) && !empty($paymentData['quotation_id'])) {
            $quotation_id = $paymentData['quotation_id'];
            $update_quotation = "
                UPDATE quotations 
                SET quotation_status = 'converted', 
                    updated_at = NOW() 
                WHERE id = :quotation_id
            ";
            $stmt = $conn->prepare($update_quotation);
            $stmt->execute([':quotation_id' => $quotation_id]);
        }

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
                
                // Check if it's a constraint violation related to product_id
                if (strpos($e->getMessage(), 'product_id') !== false && strpos($e->getMessage(), 'cannot be null') !== false) {
                    error_log("Database constraint violation: product_id cannot be null");
                    error_log("This usually means the database schema wasn't updated properly.");
                    error_log("Please run the fix_sale_items_constraint.php script to resolve this issue.");

                    throw new Exception(
                        "Database configuration error: The sale_items table doesn't allow NULL product_id values. " .
                        "This prevents adding custom/manual items to sales. " .
                        "Please contact your system administrator to run the database fix script."
                    );
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
                // Check minimum redemption points requirement
                $minimumRedemption = $loyaltySettings['minimum_redemption_points'] ?? 100;
                if ($loyaltyPointsToUse < $minimumRedemption) {
                    throw new Exception("Minimum redemption is {$minimumRedemption} points");
                }
                
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

        // Create payment records - support both single and split payments
        $stmt = $conn->prepare("
            INSERT INTO sale_payments (
                sale_id, payment_method, amount, reference, received_at
            ) VALUES (?, ?, ?, ?, NOW())
        ");

        $paymentRecords = [];

        if ($isSplitPayment) {
            // Process multiple payments
            $totalSplitAmount = 0;
            $loyaltyPointsUsedInSplit = 0;

            foreach ($paymentData['split_payments'] as $splitPayment) {
                $splitMethod = $splitPayment['method'];
                $splitAmount = floatval($splitPayment['amount']);

                // Validate split payment amount
                if ($splitAmount <= 0) {
                    throw new Exception("Invalid split payment amount for method: $splitMethod");
                }

                $totalSplitAmount += $splitAmount;

                // Handle loyalty points in split payment
                if ($splitMethod === 'loyalty_points') {
                    $splitCustomerId = $splitPayment['customer_id'] ?? null;
                    $splitPointsToUse = $splitPayment['points_to_use'] ?? 0;

                    if (!$splitCustomerId || !$splitPointsToUse) {
                        throw new Exception("Invalid loyalty points data in split payment");
                    }

                    // Check minimum redemption points requirement
                    $minimumRedemption = $loyaltySettings['minimum_redemption_points'] ?? 100;
                    if ($splitPointsToUse < $minimumRedemption) {
                        throw new Exception("Minimum redemption is {$minimumRedemption} points");
                    }

                    // Validate customer has enough points
                    $currentBalance = getCustomerLoyaltyBalance($conn, $splitCustomerId);
                    if ($currentBalance < $splitPointsToUse) {
                        throw new Exception("Insufficient loyalty points for customer");
                    }

                    // Redeem loyalty points
                    if (!redeemLoyaltyPoints($conn, $splitCustomerId, $splitPointsToUse,
                        "Redeemed in split payment for purchase #$sale_id", $transaction_id)) {
                        throw new Exception("Failed to redeem loyalty points in split payment");
                    }

                    $loyaltyPointsUsedInSplit += $splitPointsToUse;

                    // Override customer_id for the sale if not already set
                    if (!$customer_id) {
                        $customer_id = $splitCustomerId;
                    }
                }

                $payment_reference = generatePaymentReference(getPaymentReferenceType($splitMethod));

                $stmt->execute([
                    $sale_id,
                    $splitMethod,
                    $splitAmount,
                    $payment_reference
                ]);

                $paymentRecords[] = [
                    'method' => $splitMethod,
                    'amount' => $splitAmount,
                    'reference' => $payment_reference,
                    'cash_received' => $splitPayment['cash_received'] ?? null,
                    'change_due' => $splitPayment['change_due'] ?? null,
                    'points_used' => $splitMethod === 'loyalty_points' ? $splitPayment['points_to_use'] : null
                ];
            }

            // Validate total split amount matches expected amount
            if (abs($totalSplitAmount - $paymentData['amount']) > 0.01) {
                throw new Exception("Split payment total ($totalSplitAmount) does not match expected amount ({$paymentData['amount']})");
            }

            // Set loyalty points used for the transaction
            $loyaltyPointsUsed = $loyaltyPointsUsedInSplit;
        } else {
            // Single payment
            $payment_reference = generatePaymentReference(getPaymentReferenceType($paymentData['method']));

            $stmt->execute([
                $sale_id,
                $paymentData['method'],
                $finalAmount,
                $payment_reference
            ]);

            $paymentRecords[] = [
                'method' => $paymentData['method'],
                'amount' => $finalAmount,
                'reference' => $payment_reference,
                'cash_received' => $paymentData['cash_received'] ?? null,
                'change_due' => $paymentData['change_due'] ?? null
            ];
        }

        // Award loyalty points if customer is not walk-in and loyalty program is enabled
        $loyaltyPointsEarned = 0;
        
        // For split payments, ensure we have customer_id for earning points even if no loyalty redemption
        if ($isSplitPayment && !$customer_id && isset($paymentData['customer_id'])) {
            $customer_id = $paymentData['customer_id'];
            $customer_type = $paymentData['customer_type'] ?? 'walk_in';
        }
        
        if ($customer_id && $customer_type !== 'walk_in') {
            if ($loyaltySettings['enable_loyalty_program']) {
                $customer = getCustomerById($conn, $customer_id);
                if ($customer) {
                    // Use loyalty_eligible_amount if provided (for split payments), otherwise use finalAmount
                    $loyaltyEligibleAmount = $paymentData['loyalty_eligible_amount'] ?? $finalAmount;
                    $taxAmount = $paymentData['tax'] ?? 0;
                    $loyaltyPointsEarned = calculateLoyaltyPoints($conn, $loyaltyEligibleAmount, $customer['membership_level'], $taxAmount);
                    
                    if ($loyaltyPointsEarned > 0) {
                        addLoyaltyPoints($conn, $customer_id, $loyaltyPointsEarned, 
                            "Points earned from purchase #$sale_id", $transaction_id);
                    }
                }
            }
        }

        // Update quotation status to 'converted' if this sale was created from a quotation
        if ($quotation_id) {
            $stmt = $conn->prepare("UPDATE quotations SET quotation_status = 'converted', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$quotation_id]);
        }

        // Clear cart from session
        unset($_SESSION['cart']);

        // Commit transaction
        $conn->commit();

        // Receipt ID was already generated and stored above

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
            'is_split_payment' => $isSplitPayment,
            'payment_records' => $paymentRecords,
            // Legacy fields for backward compatibility
            'method' => $primaryPaymentMethod,
            'cash_received' => !$isSplitPayment ? ($paymentData['cash_received'] ?? null) : null,
            'change_due' => !$isSplitPayment ? ($paymentData['change_due'] ?? null) : null,
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
 * Get payment reference type for generating reference
 */
function getPaymentReferenceType($paymentMethod) {
    $typeMap = [
        'mobile_money' => 'mobile',
        'credit_card' => 'card',
        'debit_card' => 'card',
        'pos_card' => 'card',
        'cash' => 'cash',
        'loyalty_points' => 'loyalty',
        'bank_transfer' => 'bank'
    ];

    return $typeMap[$paymentMethod] ?? 'pay';
}

/**
 * Generate payment reference
 */
function generatePaymentReference($type) {
    $prefixes = [
        'mobile' => 'MM',
        'card' => 'CARD',
        'cash' => 'CASH',
        'bank' => 'BANK',
        'loyalty' => 'LP'
    ];

    $prefix = $prefixes[$type] ?? 'PAY';
    $timestamp = date('YmdHis');
    $random = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);

    return $prefix . $timestamp . $random;
}

?>
