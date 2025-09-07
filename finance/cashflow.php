<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Analysis - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item active">Cash Flow Analysis</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-currency-exchange"></i> Cash Flow Analysis</h1>
                    <p class="header-subtitle">Monitor cash inflows, outflows and liquidity positions</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-cash fs-1 text-info mb-3"></i>
                        <h3 class="text-muted">Cash Flow Analysis Module</h3>
                        <p class="text-muted">Features coming soon: Cash flow statements, forecasting, liquidity analysis</p>
                        <a href="index.php" class="btn btn-primary mt-3">
                            <i class="bi bi-arrow-left"></i> Back to Finance Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
