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

// Check BOM permissions
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

// Get BOM details
$stmt = $conn->prepare("SELECT * FROM bom_headers WHERE id = :bom_id");
$stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
$stmt->execute();
$bom = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bom) {
    header("Location: index.php?error=bom_not_found");
    exit();
}

// For now, redirect to add.php with edit mode
// In a future enhancement, we could create a separate edit form
header("Location: add.php?edit=1&id=$bom_id");
exit();
?>
