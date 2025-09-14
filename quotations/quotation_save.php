<?php
session_start();
require_once __DIR__ . '/../include/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Get JSON data
    $json = file_get_contents('php://input');
    $quotationData = json_decode($json, true);

    if (!$quotationData) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit();
    }

    // Add user_id to quotation data
    $quotationData['user_id'] = $user_id;

    // Create quotation
    $result = createQuotation($conn, $quotationData);

    echo json_encode($result);

} catch (Exception $e) {
    error_log("Error saving quotation: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
