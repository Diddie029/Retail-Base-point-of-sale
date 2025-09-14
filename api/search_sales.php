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

// Set content type to JSON
header('Content-Type: application/json');

// Get search parameters
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if (empty($search_term)) {
    echo json_encode(['success' => false, 'error' => 'Search term is required']);
    exit();
}

// Handle # prefix for receipt numbers (e.g., #000127 -> 000127)
if (strpos($search_term, '#') === 0) {
    $search_term = substr($search_term, 1);
}

try {
    // Search sales by receipt number, transaction ID, or customer name
    $stmt = $conn->prepare("
        SELECT 
            s.id,
            s.customer_name,
            s.customer_phone,
            s.customer_email,
            s.total_amount,
            s.final_amount,
            s.payment_method,
            s.sale_date,
            s.created_at,
            u.username as cashier_name,
            CASE 
                WHEN s.id < 1000 THEN LPAD(s.id, 3, '0')
                ELSE s.id
            END as receipt_number,
            CONCAT('TXN', UPPER(SUBSTRING(MD5(CONCAT(s.id, s.created_at)), 1, 8))) as transaction_id
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE 
            s.id = :exact_search
            OR s.id LIKE :search_term
            OR s.customer_name LIKE :search_term
            OR s.customer_phone LIKE :search_term
            OR CONCAT('TXN', UPPER(SUBSTRING(MD5(CONCAT(s.id, s.created_at)), 1, 8))) LIKE :search_term
            OR LPAD(s.id, 3, '0') = :exact_search
            OR LPAD(s.id, 3, '0') LIKE :search_term
            OR LPAD(s.id, 6, '0') = :exact_search
            OR LPAD(s.id, 6, '0') LIKE :search_term
        ORDER BY s.created_at DESC
        LIMIT :limit
    ");
    
    $search_pattern = '%' . $search_term . '%';
    $stmt->bindParam(':search_term', $search_pattern);
    $stmt->bindParam(':exact_search', $search_term);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if any of these sales already have invoices
    $sale_ids = array_column($sales, 'id');
    $existing_invoices = [];
    
    if (!empty($sale_ids)) {
        $placeholders = str_repeat('?,', count($sale_ids) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT sale_id, invoice_number, invoice_status 
            FROM invoices 
            WHERE sale_id IN ($placeholders)
        ");
        $stmt->execute($sale_ids);
        $existing_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Create a lookup array for existing invoices
    $invoice_lookup = [];
    foreach ($existing_invoices as $invoice) {
        $invoice_lookup[$invoice['sale_id']] = [
            'invoice_number' => $invoice['invoice_number'],
            'status' => $invoice['invoice_status']
        ];
    }
    
    // Add invoice status to each sale
    foreach ($sales as &$sale) {
        $sale['has_invoice'] = isset($invoice_lookup[$sale['id']]);
        if ($sale['has_invoice']) {
            $sale['invoice_number'] = $invoice_lookup[$sale['id']]['invoice_number'];
            $sale['invoice_status'] = $invoice_lookup[$sale['id']]['status'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'sales' => $sales,
        'total' => count($sales)
    ]);
    
} catch (PDOException $e) {
    error_log("Error searching sales: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}
?>
