<?php
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../include/db.php';

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['sale_id'])) {
        throw new Exception('Sale ID is required');
    }
    
    $saleId = (int)$input['sale_id'];
    $userId = $_SESSION['user_id'];
    
    // Validate sale ID
    if ($saleId <= 0) {
        throw new Exception('Invalid sale ID');
    }
    
    // Check if sale exists
    $stmt = $conn->prepare("SELECT id FROM sales WHERE id = :sale_id");
    $stmt->execute([':sale_id' => $saleId]);
    if (!$stmt->fetch()) {
        throw new Exception('Sale not found');
    }
    
    // Check if invoice already exists for this sale
    $stmt = $conn->prepare("SELECT id, invoice_number FROM invoices WHERE sale_id = :sale_id");
    $stmt->execute([':sale_id' => $saleId]);
    $existingInvoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingInvoice) {
        throw new Exception('Invoice already exists for this sale. Invoice #' . $existingInvoice['invoice_number']);
    }
    
    // Create invoice using the existing function
    $result = createInvoiceFromSale($conn, $saleId, $userId);
    
    if ($result['success']) {
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, details) 
            VALUES (:user_id, 'invoice_created', :details)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':details' => json_encode([
                'sale_id' => $saleId,
                'invoice_id' => $result['invoice_id'],
                'invoice_number' => $result['invoice_number']
            ])
        ]);
        
        echo json_encode([
            'success' => true,
            'invoice_id' => $result['invoice_id'],
            'invoice_number' => $result['invoice_number'],
            'message' => 'Invoice created successfully'
        ]);
    } else {
        throw new Exception($result['error']);
    }
    
} catch (Exception $e) {
    error_log("Invoice creation error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
