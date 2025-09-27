<?php
// Cash Drop Audit Trail Handler
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

if ($action === 'get_audit_trail') {
    $drop_id = intval($_POST['drop_id'] ?? 0);

    if (!$drop_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid drop ID']);
        exit();
    }

    try {
        // Get the cash drop details
        $stmt = $conn->prepare("
            SELECT
                cd.*,
                rt.till_name,
                rt.till_code,
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
            echo json_encode(['success' => false, 'message' => 'Cash drop not found']);
            exit();
        }

        // Get audit trail from security logs
        $stmt = $conn->prepare("
            SELECT
                sl.*,
                CASE
                    WHEN sl.severity = 'low' THEN 'Low'
                    WHEN sl.severity = 'medium' THEN 'Medium'
                    WHEN sl.severity = 'high' THEN 'High'
                    WHEN sl.severity = 'critical' THEN 'Critical'
                    ELSE 'Unknown'
                END as severity_text
            FROM security_logs sl
            WHERE sl.event_type LIKE '%cash_drop%'
                AND sl.details LIKE ?
            ORDER BY sl.created_at ASC
        ");
        $stmt->execute(['%' . $drop_id . '%']);
        $audit_trail = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If no detailed audit trail, create basic events from cash drop data
        if (empty($audit_trail)) {
            $audit_trail = [];

            // Add drop creation event
            if ($drop['created_at']) {
                $audit_trail[] = [
                    'id' => 'drop_created_' . $drop_id,
                    'event_type' => 'cash_drop_created',
                    'details' => 'Cash drop was initiated by ' . ($drop['dropped_by_name'] ?: 'Unknown user'),
                    'severity' => 'medium',
                    'severity_text' => 'Medium',
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $drop['created_at']
                ];
            }

            // Add confirmation event
            if ($drop['confirmed_at'] && $drop['status'] === 'confirmed') {
                $audit_trail[] = [
                    'id' => 'drop_confirmed_' . $drop_id,
                    'event_type' => 'cash_drop_confirmed',
                    'details' => 'Cash drop was confirmed by ' . ($drop['confirmed_by_name'] ?: 'Unknown user'),
                    'severity' => 'medium',
                    'severity_text' => 'Medium',
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $drop['confirmed_at']
                ];
            }

            // Add cancellation event
            if ($drop['confirmed_at'] && $drop['status'] === 'cancelled') {
                $audit_trail[] = [
                    'id' => 'drop_cancelled_' . $drop_id,
                    'event_type' => 'cash_drop_cancelled',
                    'details' => 'Cash drop was cancelled',
                    'severity' => 'high',
                    'severity_text' => 'High',
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $drop['confirmed_at']
                ];
            }
        }

        // Get settings for currency formatting
        $settings = getSystemSettings($conn);
        $currency_symbol = $settings['currency_symbol'] ?? 'KES';

        // Format amounts
        $drop['formatted_amount'] = $currency_symbol . ' ' . number_format($drop['drop_amount'], 2);

        echo json_encode([
            'success' => true,
            'drop' => $drop,
            'audit_trail' => $audit_trail
        ]);

    } catch (Exception $e) {
        error_log("Cash Drop Audit Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load audit trail']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>
