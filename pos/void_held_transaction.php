<?php
// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
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

try {
    // Get void data from request
    $input = file_get_contents('php://input');
    $voidData = json_decode($input, true);

    if (!$voidData) {
        throw new Exception('Invalid void data');
    }

    // Validate required fields
    $requiredFields = ['held_transaction_id', 'void_reason'];
    foreach ($requiredFields as $field) {
        if (!isset($voidData[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $held_transaction_id = intval($voidData['held_transaction_id']);
    $void_reason = trim($voidData['void_reason']);

    if (empty($void_reason)) {
        throw new Exception('Void reason is required');
    }

    // Get held transaction details
    $stmt = $conn->prepare("
        SELECT ht.*, u.username as cashier_name, rt.till_name
        FROM held_transactions ht
        LEFT JOIN users u ON ht.user_id = u.id
        LEFT JOIN register_tills rt ON ht.till_id = rt.id
        WHERE ht.id = ? AND ht.status = 'held'
    ");
    $stmt->execute([$held_transaction_id]);
    $held_transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$held_transaction) {
        throw new Exception('Held transaction not found or already processed');
    }

    // Decode cart data to get totals and re-evaluate totals based on current product prices
    $cartData = json_decode($held_transaction['cart_data'], true);
    $total_amount = 0;
    if (!empty($cartData['items'])) {
        foreach ($cartData['items'] as $item) {
            $product_id = $item['product_id'] ?? $item['id'] ?? null;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : (isset($item['qty']) ? floatval($item['qty']) : 1);
            $unit_price = null;
            if ($product_id) {
                $pstmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
                $pstmt->execute([$product_id]);
                $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
                if ($prod) {
                    $unit_price = (float)getCurrentProductPrice($prod);
                }
            }
            if ($unit_price === null) {
                if (isset($item['unit_price'])) $unit_price = (float)$item['unit_price'];
                elseif (isset($item['price'])) $unit_price = (float)$item['price'];
                else $unit_price = 0;
            }
            $total_amount += $unit_price * $quantity;
        }
    }

    // Start transaction
    $conn->beginTransaction();

    try {
        // Update held transaction status to deleted
        $stmt = $conn->prepare("
            UPDATE held_transactions 
            SET status = 'deleted', 
                reason = CONCAT(COALESCE(reason, ''), ' | Voided: ', ?),
                updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$void_reason, $held_transaction_id]);

        // Record void transaction for audit trail
        $stmt = $conn->prepare("
            INSERT INTO void_transactions (
                user_id, till_id, void_type, product_id, product_name, 
                quantity, unit_price, total_amount, void_reason
            ) VALUES (?, ?, 'held_transaction', NULL, ?, 0, 0, ?, ?)
        ");

        $stmt->execute([
            $user_id,
            $held_transaction['till_id'],
            'Held Transaction #' . $held_transaction_id,
            $total_amount,
            'Held transaction voided: ' . $void_reason
        ]);

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Held transaction voided successfully',
            'voided_amount' => $total_amount,
            'held_transaction_info' => [
                'id' => $held_transaction['id'],
                'cashier_name' => $held_transaction['cashier_name'],
                'till_name' => $held_transaction['till_name'],
                'original_reason' => $held_transaction['reason']
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>
