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

$can_manage_boms = hasPermission('manage_boms', $permissions);

if (!$can_manage_boms) {
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

    // Get BOM details for logging
    $stmt = $conn->prepare("SELECT bom_number, product_id FROM bom_headers WHERE id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
    $stmt->execute();
    $bom = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bom) {
        throw new Exception("BOM not found");
    }

    // Delete BOM components
    $stmt = $conn->prepare("DELETE FROM bom_components WHERE bom_id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id);
    $stmt->execute();

    // Delete BOM versions
    $stmt = $conn->prepare("DELETE FROM bom_versions WHERE bom_id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id);
    $stmt->execute();

    // Delete production orders (this will cascade to production order items)
    $stmt = $conn->prepare("DELETE FROM bom_production_orders WHERE bom_id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id);
    $stmt->execute();

    // Delete BOM header
    $stmt = $conn->prepare("DELETE FROM bom_headers WHERE id = :bom_id");
    $stmt->bindParam(':bom_id', $bom_id);
    $stmt->execute();

    // Update product to remove BOM reference
    $stmt = $conn->prepare("UPDATE products SET is_bom = 0, bom_id = NULL WHERE id = :product_id");
    $stmt->bindParam(':product_id', $bom['product_id'], PDO::PARAM_INT);
    $stmt->execute();

    // Log activity
    logActivity($conn, $user_id, 'bom_deleted', "Deleted BOM: " . $bom['bom_number']);

    $conn->commit();

    header("Location: index.php?success=bom_deleted");
    exit();

} catch (Exception $e) {
    $conn->rollBack();
    error_log("BOM deletion failed: " . $e->getMessage());
    header("Location: index.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>
