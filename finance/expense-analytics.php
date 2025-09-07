<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';
if (!isset($_SESSION['user_id'])) header('Location: ../auth/login.php');
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $settings[$row['setting_key']] = $row['setting_value'];
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Expense Analytics - POS System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/dashboard.css"></head><body>
<?php include '../include/navmenu.php'; ?>
<div class="main-content"><header class="header"><div class="header-content"><div class="header-title">
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li><li class="breadcrumb-item active">Expense Analytics</li></ol></nav>
<h1><i class="bi bi-pie-chart"></i> Expense Analytics</h1></div></div></header>
<main class="content"><div class="container-fluid"><div class="card"><div class="card-body text-center py-5">
<i class="bi bi-pie-chart fs-1 text-warning mb-3"></i><h3 class="text-muted">Expense Analytics Module</h3>
<p class="text-muted">Advanced expense analysis and cost optimization tools coming soon.</p>
<a href="index.php" class="btn btn-primary mt-3"><i class="bi bi-arrow-left"></i> Back to Finance Dashboard</a>
</div></div></div></main></div></body></html>
