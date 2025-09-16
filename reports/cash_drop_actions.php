<?php
// Cash Drop Actions Handler
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
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Set JSON response header
header('Content-Type: application/json');

// Handle different actions
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'approve_drop':
        handleApproveDrop($conn, $user_id, $username, $role_name, $permissions);
        break;

    case 'deny_drop':
        handleDenyDrop($conn, $user_id, $username, $role_name, $permissions);
        break;


    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function handleApproveDrop($conn, $user_id, $username, $role_name, $permissions) {
    // Check permissions
    $hasPermission = false;
    if (isAdmin($role_name)) {
        $hasPermission = true;
    }
    if (!$hasPermission && !empty($permissions)) {
        if (hasPermission('manage_sales', $permissions) || hasPermission('approve_cash_drop', $permissions) || hasPermission('cash_drop', $permissions)) {
            $hasPermission = true;
        }
    }

    if (!$hasPermission) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions to approve cash drops']);
        return;
    }

    $drop_id = intval($_POST['drop_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$drop_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid drop ID']);
        return;
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Get drop details
        $stmt = $conn->prepare("
            SELECT cd.*, rt.till_name
            FROM cash_drops cd
            LEFT JOIN register_tills rt ON cd.till_id = rt.id
            WHERE cd.id = ? AND cd.status = 'pending'
        ");
        $stmt->execute([$drop_id]);
        $drop = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$drop) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Drop not found or already processed']);
            return;
        }

        // Update drop status
        $update_notes = $drop['notes'];
        if ($notes) {
            $update_notes .= "\n\n[Approved by {$username} on " . date('Y-m-d H:i:s') . "]\n{$notes}";
        } else {
            $update_notes .= "\n\n[Approved by {$username} on " . date('Y-m-d H:i:s') . "]";
        }

        $stmt = $conn->prepare("
            UPDATE cash_drops
            SET status = 'confirmed',
                confirmed_by = ?,
                confirmed_at = NOW(),
                notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $update_notes, $drop_id]);

        // Log the approval action
        error_log("Cash Drop Approved - User: {$username} (ID: {$user_id}) approved drop ID: {$drop_id} for till: {$drop['till_name']}");

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Cash drop approved successfully']);

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Cash Drop Approval Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve cash drop: ' . $e->getMessage()]);
    }
}

function handleDenyDrop($conn, $user_id, $username, $role_name, $permissions) {
    // Check permissions
    $hasPermission = false;
    if (isAdmin($role_name)) {
        $hasPermission = true;
    }
    if (!$hasPermission && !empty($permissions)) {
        if (hasPermission('manage_sales', $permissions) || hasPermission('approve_cash_drop', $permissions) || hasPermission('cash_drop', $permissions)) {
            $hasPermission = true;
        }
    }

    if (!$hasPermission) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions to deny cash drops']);
        return;
    }

    $drop_id = intval($_POST['drop_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$drop_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid drop ID']);
        return;
    }

    if (!$reason) {
        echo json_encode(['success' => false, 'message' => 'Reason is required for denial']);
        return;
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Get drop details
        $stmt = $conn->prepare("
            SELECT cd.*, rt.till_name
            FROM cash_drops cd
            LEFT JOIN register_tills rt ON cd.till_id = rt.id
            WHERE cd.id = ? AND cd.status = 'pending'
        ");
        $stmt->execute([$drop_id]);
        $drop = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$drop) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Drop not found or already processed']);
            return;
        }

        // Restore till balance (add back the dropped amount)
        $stmt = $conn->prepare("
            UPDATE register_tills
            SET current_balance = current_balance + ?
            WHERE id = ?
        ");
        $stmt->execute([$drop['drop_amount'], $drop['till_id']]);

        // Update drop status with denial reason
        $denial_notes = $drop['notes'];
        $denial_notes .= "\n\n[DENIED by {$username} on " . date('Y-m-d H:i:s') . "]";
        $denial_notes .= "\nReason: {$reason}";
        if ($notes) {
            $denial_notes .= "\nNotes: {$notes}";
        }

        $stmt = $conn->prepare("
            UPDATE cash_drops
            SET status = 'cancelled',
                confirmed_by = ?,
                confirmed_at = NOW(),
                notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $denial_notes, $drop_id]);

        // Log the denial action
        error_log("Cash Drop Denied - User: {$username} (ID: {$user_id}) denied drop ID: {$drop_id} for till: {$drop['till_name']}, Reason: {$reason}");

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Cash drop denied successfully',
            'drop_id' => $drop_id,
            'till_name' => $drop['till_name']
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Cash Drop Denial Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to deny cash drop: ' . $e->getMessage()]);
    }
}

?>
