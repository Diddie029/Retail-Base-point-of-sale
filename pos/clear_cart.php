<?php
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

// Clear cart from session
unset($_SESSION['cart']);

// Return success response
echo json_encode(['success' => true, 'message' => 'Cart cleared successfully']);
?>
