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

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['sale_id']) || !isset($input['taxes'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid input data']);
    exit();
}

$sale_id = (int)$input['sale_id'];
$taxes = $input['taxes'];

try {
    $conn->beginTransaction();

    // Delete existing tax records for this sale
    $stmt = $conn->prepare("DELETE FROM sale_taxes WHERE sale_id = ?");
    $stmt->execute([$sale_id]);

    // Insert new tax records
    if (!empty($taxes)) {
        $stmt = $conn->prepare("
            INSERT INTO sale_taxes (sale_id, tax_rate_id, tax_category_name, tax_name, tax_rate, taxable_amount, tax_amount, is_compound)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($taxes as $tax) {
            $stmt->execute([
                $sale_id,
                $tax['tax_rate_id'],
                $tax['tax_category_name'],
                $tax['tax_name'],
                $tax['tax_rate'],
                $tax['taxable_amount'],
                $tax['tax_amount'],
                $tax['is_compound'] ? 1 : 0
            ]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save sale taxes: ' . $e->getMessage()
    ]);
}
