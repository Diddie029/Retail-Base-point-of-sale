<?php
// Cash Drop Details Handler
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$role_name = $_SESSION['role_name'] ?? 'User';

// Set JSON response header
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'get_drop_details') {
    $drop_id = intval($_POST['drop_id'] ?? 0);

    if (!$drop_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid drop ID']);
        exit();
    }

    try {
        // Get drop details with additional information
        $stmt = $conn->prepare("
            SELECT
                cd.*,
                rt.till_name,
                rt.till_code,
                rt.current_balance as till_current_balance,
                u.username as dropped_by_name,
                cu.username as confirmed_by_name,
                CASE
                    WHEN cd.status = 'pending' THEN 'Pending'
                    WHEN cd.status = 'confirmed' THEN 'Confirmed'
                    WHEN cd.status = 'cancelled' THEN 'Cancelled'
                    ELSE 'Unknown'
                END as status_text
            FROM cash_drops cd
            LEFT JOIN register_tills rt ON cd.till_id = rt.id
            LEFT JOIN users u ON cd.user_id = u.id
            LEFT JOIN users cu ON cd.confirmed_by = cu.id
            WHERE cd.id = ?
        ");
        $stmt->execute([$drop_id]);
        $drop = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$drop) {
            echo json_encode(['success' => false, 'message' => 'Drop not found']);
            exit();
        }

        // Calculate time to confirm
        $time_to_confirm = 'N/A';
        if ($drop['confirmed_at'] && $drop['drop_date']) {
            $drop_time = strtotime($drop['drop_date']);
            $confirm_time = strtotime($drop['confirmed_at']);
            $time_diff = $confirm_time - $drop_time;

            if ($time_diff < 3600) { // Less than 1 hour
                $minutes = round($time_diff / 60);
                $time_to_confirm = $minutes . ' minute' . ($minutes != 1 ? 's' : '');
            } elseif ($time_diff < 86400) { // Less than 24 hours
                $hours = round($time_diff / 3600);
                $time_to_confirm = $hours . ' hour' . ($hours != 1 ? 's' : '');
            } else {
                $days = round($time_diff / 86400);
                $time_to_confirm = $days . ' day' . ($days != 1 ? 's' : '');
            }
        }

        // Get settings for currency formatting
        $settings = getSystemSettings($conn);
        $currency_symbol = $settings['currency_symbol'] ?? 'KES';

        // Format amounts
        $drop['formatted_amount'] = $currency_symbol . ' ' . number_format($drop['drop_amount'], 2);
        $drop['formatted_balance'] = $drop['till_current_balance'] ?
            $currency_symbol . ' ' . number_format($drop['till_current_balance'], 2) : 'N/A';
        $drop['time_to_confirm'] = $time_to_confirm;

        echo json_encode([
            'success' => true,
            'drop' => $drop
        ]);

    } catch (Exception $e) {
        error_log("Cash Drop Details Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load drop details']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>
