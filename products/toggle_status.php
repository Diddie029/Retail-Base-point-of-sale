<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: products.php');
    exit();
}

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("SELECT p.name FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role_id = :role_id");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Allow Admins to bypass the permission check
$role_name = $_SESSION['role_name'] ?? '';
if (!((strtolower($role_name) === 'admin') || hasPermission('manage_products', $permissions))) {
    $_SESSION['error'] = 'You do not have permission to perform this action.';
    header('Location: products.php');
    exit();
}

$product_id = intval($_POST['product_id'] ?? 0);
if ($product_id <= 0) {
    $_SESSION['error'] = 'Invalid product id.';
    header('Location: products.php');
    exit();
}

// Get current status
$stmt = $conn->prepare("SELECT status FROM products WHERE id = :id");
$stmt->bindParam(':id', $product_id);
$stmt->execute();
$current = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current) {
    $_SESSION['error'] = 'Product not found.';
    header('Location: products.php');
    exit();
}

$new_status = ($current['status'] === 'active') ? 'inactive' : 'active';

$update = $conn->prepare("UPDATE products SET status = :status, updated_at = NOW() WHERE id = :id");
$update->bindParam(':status', $new_status);
$update->bindParam(':id', $product_id);

if ($update->execute()) {
    $_SESSION['success'] = ($new_status === 'active') ? 'Product activated successfully.' : 'Product suspended successfully.';
    logActivity($conn, $user_id, 'toggle_product_status', "Toggled product #$product_id to $new_status");
} else {
    $_SESSION['error'] = 'Failed to update product status.';
}

// Redirect back to view page
header('Location: view.php?id=' . $product_id);
exit();
