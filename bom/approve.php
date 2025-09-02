<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];

// Check permissions
$role_id = $_SESSION['role_id'] ?? 0;
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

$can_approve_boms = hasPermission('approve_boms', $permissions);

if (!$can_approve_boms) {
    header("Location: index.php?error=permission_denied");
    exit();
}

// Get BOM ID
$bom_id = intval($_GET['id'] ?? 0);
if (!$bom_id) {
    header("Location: index.php?error=invalid_bom_id");
    exit();
}

try {
    $conn->beginTransaction();

    // Get BOM details
    $stmt = $conn->prepare("SELECT bom_number, status FROM bom_headers WHERE id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
    $stmt->execute();
    $bom = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bom) {
        throw new Exception("BOM not found");
    }

    if ($bom['status'] !== 'draft') {
        throw new Exception("Only draft BOMs can be approved");
    }

    // Update BOM status to active
    $stmt = $conn->prepare("
        UPDATE bom_headers SET
            status = 'active',
            approved_by = :approved_by,
            approved_at = NOW(),
            updated_at = NOW()
        WHERE id = :bom_id
    ");
    $stmt->bindParam(':approved_by', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
    $stmt->execute();

    // Log activity
    logActivity($conn, $user_id, 'bom_approved', "Approved BOM: " . $bom['bom_number']);

    $conn->commit();

    header("Location: view.php?id=$bom_id&success=bom_approved");
    exit();

} catch (Exception $e) {
    $conn->rollBack();
    error_log("BOM approval failed: " . $e->getMessage());
    header("Location: index.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>
