<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is POS authenticated or has an active till
$pos_authenticated = isset($_SESSION['pos_authenticated']) && $_SESSION['pos_authenticated'] === true;
$selected_till_id = $_SESSION['selected_till_id'] ?? null;
$till_closed = false;
$has_active_till = false;

// Check if till is closed
if ($selected_till_id) {
    try {
        $stmt = $conn->prepare("SELECT is_closed FROM register_tills WHERE id = ?");
        $stmt->execute([$selected_till_id]);
        $till = $stmt->fetch(PDO::FETCH_ASSOC);
        $till_closed = $till ? $till['is_closed'] == 1 : false;
        $has_active_till = true; // User has a till assigned
    } catch (PDOException $e) {
        error_log("Error checking till status: " . $e->getMessage());
    }
}

// Prevent logout if:
// 1. User is POS authenticated, OR
// 2. User has a till assigned but it's not closed
if ($pos_authenticated || ($has_active_till && !$till_closed)) {
    if ($pos_authenticated) {
        $error_message = "Cannot logout while signed in to POS. Please sign out from POS first.";
    } else {
        $error_message = "Cannot logout while till is open. You must close your till first.";
    }
    
    // Store error message in session
    $_SESSION['logout_error'] = $error_message;
    
    // Redirect back to dashboard with error
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>