<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user's role_id
$role_id = $_SESSION['role_id'] ?? null;

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

// Check if user has permission to view financial reports
if (!hasPermission('view_financial_reports', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Forecasting - POS System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/dashboard.css"></head><body>
<?php include '../include/navmenu.php'; ?>
<div class="main-content"><header class="header"><div class="header-content"><div class="header-title">
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li><li class="breadcrumb-item active">Forecasting</li></ol></nav>
<h1>Forecasting</h1></div></div></header>
<main class="content"><div class="container-fluid"><div class="card"><div class="card-body text-center py-5">
<i class="bi bi-tools fs-1 text-muted mb-3"></i><h3 class="text-muted">Forecasting Module</h3>
<p class="text-muted">This module is under development and will be available soon.</p>
<a href="index.php" class="btn btn-primary mt-3"><i class="bi bi-arrow-left"></i> Back to Finance Dashboard</a>
</div></div></div></main></div></body></html>
