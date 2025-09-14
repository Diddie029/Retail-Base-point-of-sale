<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/classes/TaxManager.php';

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON data');
    }

    $items = $input['items'] ?? [];
    $customer_id = $input['customer_id'] ?? null;
    $subtotal = floatval($input['subtotal'] ?? 0);

    if (empty($items)) {
        echo json_encode([
            'success' => true,
            'taxes' => [],
            'total_taxable_amount' => 0,
            'total_tax_amount' => 0,
            'customer_exempt' => false
        ]);
        exit();
    }

    // Initialize TaxManager
    $taxManager = new TaxManager($conn, $_SESSION['user_id']);
    
    // Calculate taxes
    $taxResult = $taxManager->calculateTaxes($items, $customer_id);
    
    echo json_encode([
        'success' => true,
        'taxes' => $taxResult['taxes'],
        'total_taxable_amount' => $taxResult['total_taxable_amount'],
        'total_tax_amount' => $taxResult['total_tax_amount'],
        'customer_exempt' => $taxResult['customer_exempt']
    ]);

} catch (Exception $e) {
    error_log("Tax calculation error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Tax calculation failed: ' . $e->getMessage(),
        'taxes' => [],
        'total_tax_amount' => 0
    ]);
}
?>
