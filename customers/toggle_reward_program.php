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

// Check if user has permission to edit customers
if (!hasPermission('edit_customers', $permissions)) {
    header("Location: view.php?id=" . ($_POST['customer_id'] ?? '') . "&error=access_denied");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $activate = $_POST['activate'] === 'true';
    
    if ($customer_id <= 0) {
        header("Location: index.php?error=invalid_customer");
        exit();
    }
    
    try {
        // Update customer reward program status
        $stmt = $conn->prepare("
            UPDATE customers 
            SET reward_program_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$activate ? 1 : 0, $customer_id]);
        
        if ($stmt->rowCount() > 0) {
            $action = $activate ? 'activated' : 'deactivated';
            $success_message = "Customer reward program {$action} successfully!";
            header("Location: view.php?id={$customer_id}&success=" . urlencode($success_message));
        } else {
            header("Location: view.php?id={$customer_id}&error=customer_not_found");
        }
        exit();
        
    } catch (Exception $e) {
        header("Location: view.php?id={$customer_id}&error=" . urlencode('Error updating reward program: ' . $e->getMessage()));
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>
