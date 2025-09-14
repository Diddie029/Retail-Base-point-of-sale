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

$user_id = $_SESSION['user_id'];

// Set content type to JSON
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Validate required fields
if (!isset($data['quotation_id']) || !isset($data['quotationData'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$quotation_id = (int)$data['quotation_id'];
$quotationData = $data['quotationData'];

// Additional validation
if (!$quotation_id || !is_array($quotationData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid quotation data']);
    exit();
}

// Validate customer name
if (empty($quotationData['customer_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Customer name is required']);
    exit();
}

// Validate items
if (empty($quotationData['items']) || !is_array($quotationData['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one item is required']);
    exit();
}

// Validate each item
foreach ($quotationData['items'] as $item) {
    if (empty($item['product_id']) || !is_numeric($item['product_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All items must have a valid product ID']);
        exit();
    }

    if (!isset($item['quantity']) || $item['quantity'] <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All items must have a quantity greater than 0']);
        exit();
    }

    if (!isset($item['unit_price']) || $item['unit_price'] < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All items must have a valid unit price']);
        exit();
    }
}

// Validate valid_until date
if (empty($quotationData['valid_until'])) {
    // Use default valid days from settings
    $settings = [];
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'quotation_%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $validDays = (int)($settings['quotation_valid_days'] ?? 30);
    $quotationData['valid_until'] = date('Y-m-d', strtotime("+$validDays days"));
}

// Verify that the quotation exists and belongs to current user
try {
    $stmt = $conn->prepare("SELECT id, user_id FROM quotations WHERE id = :quotation_id");
    $stmt->execute([':quotation_id' => $quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Quotation not found']);
        exit();
    }

    if ($quotation['user_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }

    // Update quotation
    $result = updateQuotation($conn, $quotation_id, $quotationData);

    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'quotation_id' => $quotation_id,
            'message' => 'Quotation updated successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to update quotation'
        ]);
    }

} catch (PDOException $e) {
    error_log("Error updating quotation: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
?>