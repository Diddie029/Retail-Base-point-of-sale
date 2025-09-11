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

// Check if user has permission to view reports
$hasAccess = isAdmin($role_name) || 
             hasPermission('view_analytics', $permissions) || 
             hasPermission('manage_sales', $permissions) || 
             hasPermission('manage_users', $permissions) ||
             hasPermission('view_finance', $permissions);

if (!$hasAccess) {
    header('Location: ../dashboard/dashboard.php');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$cashier_id = $_GET['cashier_id'] ?? '';
$void_type = $_GET['void_type'] ?? '';
$export_format = $_GET['export'] ?? 'csv';

// Build the query
$where_conditions = ["DATE(vt.voided_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($cashier_id)) {
    $where_conditions[] = "vt.user_id = ?";
    $params[] = $cashier_id;
}

if (!empty($void_type)) {
    $where_conditions[] = "vt.void_type = ?";
    $params[] = $void_type;
}

$where_clause = implode(' AND ', $where_conditions);

// Get void transactions
$stmt = $conn->prepare("
    SELECT vt.*, u.username as cashier_name, rt.till_name, rt.till_code
    FROM void_transactions vt
    LEFT JOIN users u ON vt.user_id = u.id
    LEFT JOIN register_tills rt ON vt.till_id = rt.id
    WHERE $where_clause
    ORDER BY vt.voided_at DESC
");
$stmt->execute($params);
$void_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_voids,
        COUNT(DISTINCT vt.user_id) as cashiers_involved,
        SUM(vt.total_amount) as total_voided_amount,
        AVG(vt.total_amount) as avg_void_amount
    FROM void_transactions vt
    WHERE $where_clause
");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

if ($export_format === 'csv') {
    // Export as CSV
    $filename = 'void_transactions_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'ID',
        'Date',
        'Time',
        'Cashier',
        'Till Name',
        'Till Code',
        'Void Type',
        'Product Name',
        'Product ID',
        'Quantity',
        'Unit Price',
        'Total Amount',
        'Void Reason'
    ]);
    
    // CSV Data
    foreach ($void_transactions as $void) {
        fputcsv($output, [
            $void['id'],
            date('Y-m-d', strtotime($void['voided_at'])),
            date('H:i:s', strtotime($void['voided_at'])),
            $void['cashier_name'] ?? 'Unknown',
            $void['till_name'] ?? 'N/A',
            $void['till_code'] ?? '',
            ucfirst($void['void_type']),
            $void['product_name'],
            $void['product_id'],
            $void['quantity'],
            number_format($void['unit_price'], 2),
            number_format($void['total_amount'], 2),
            $void['void_reason']
        ]);
    }
    
    fclose($output);
    exit();
    
} elseif ($export_format === 'pdf') {
    // Export as PDF (simplified HTML version for now)
    $filename = 'void_transactions_' . date('Y-m-d') . '.html';
    
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Void Transactions Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .summary { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .summary h3 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Void Transactions Report</h1>
        <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        <p>Period: ' . $date_from . ' to ' . $date_to . '</p>
    </div>
    
    <div class="summary">
        <h3>Summary</h3>
        <p><strong>Total Voids:</strong> ' . number_format($summary['total_voids']) . '</p>
        <p><strong>Total Amount:</strong> ' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($summary['total_voided_amount'], 2) . '</p>
        <p><strong>Cashiers Involved:</strong> ' . number_format($summary['cashiers_involved']) . '</p>
        <p><strong>Average Void Amount:</strong> ' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($summary['avg_void_amount'], 2) . '</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Date & Time</th>
                <th>Cashier</th>
                <th>Till</th>
                <th>Type</th>
                <th>Product</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total Amount</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($void_transactions as $void) {
        echo '<tr>
            <td>' . $void['id'] . '</td>
            <td>' . date('Y-m-d H:i:s', strtotime($void['voided_at'])) . '</td>
            <td>' . htmlspecialchars($void['cashier_name'] ?? 'Unknown') . '</td>
            <td>' . htmlspecialchars($void['till_name'] ?? 'N/A') . '</td>
            <td>' . ucfirst($void['void_type']) . '</td>
            <td>' . htmlspecialchars($void['product_name']) . '</td>
            <td>' . $void['quantity'] . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($void['unit_price'], 2) . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($void['total_amount'], 2) . '</td>
            <td>' . htmlspecialchars($void['void_reason']) . '</td>
        </tr>';
    }
    
    echo '</tbody>
    </table>
    
    <div class="footer">
        <p>Report generated by ' . htmlspecialchars($settings['company_name'] ?? 'POS System') . '</p>
    </div>
</body>
</html>';
    
    exit();
}
?>
