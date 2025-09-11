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
$export_format = $_GET['export'] ?? 'csv';

// Build date filter for queries
$date_filter = "DATE(s.sale_date) BETWEEN ? AND ?";
$params = [$date_from, $date_to];

if (!empty($cashier_id)) {
    $cashier_filter = "AND s.user_id = ?";
    $params[] = $cashier_id;
} else {
    $cashier_filter = "";
}

// Get cashier performance data
$performance_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(s.id) as total_sales,
        COALESCE(SUM(s.final_amount), 0) as total_revenue,
        COALESCE(AVG(s.final_amount), 0) as avg_sale_amount,
        COUNT(DISTINCT DATE(s.sale_date)) as days_worked,
        COALESCE(SUM(s.final_amount) / COUNT(DISTINCT DATE(s.sale_date)), 0) as daily_average
    FROM users u
    LEFT JOIN sales s ON u.id = s.user_id AND $date_filter
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_revenue DESC
");
$performance_stmt->execute($params);
$cashier_performance = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get void transactions summary by cashier
$void_summary_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(vt.id) as total_voids,
        COALESCE(SUM(vt.total_amount), 0) as total_voided_amount,
        COALESCE(AVG(vt.total_amount), 0) as avg_void_amount
    FROM users u
    LEFT JOIN void_transactions vt ON u.id = vt.user_id AND $date_filter
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_voided_amount DESC
");
$void_summary_stmt->execute($params);
$void_summary = $void_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get held transactions summary by cashier
$held_summary_stmt = $conn->prepare("
    SELECT 
        u.id,
        u.username,
        COUNT(ht.id) as total_held,
        COALESCE(SUM(ht.total_amount), 0) as total_held_amount
    FROM users u
    LEFT JOIN held_transactions ht ON u.id = ht.user_id AND $date_filter AND ht.status = 'held'
    WHERE u.role = 'cashier' OR u.role = 'admin'
    $cashier_filter
    GROUP BY u.id, u.username
    ORDER BY total_held_amount DESC
");
$held_summary_stmt->execute($params);
$held_summary = $held_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge all data
$cashier_data = [];
foreach ($cashier_performance as $perf) {
    $cashier_data[$perf['id']] = $perf;
}

foreach ($void_summary as $void) {
    if (isset($cashier_data[$void['id']])) {
        $cashier_data[$void['id']] = array_merge($cashier_data[$void['id']], $void);
    } else {
        $cashier_data[$void['id']] = $void;
    }
}

foreach ($held_summary as $held) {
    if (isset($cashier_data[$held['id']])) {
        $cashier_data[$held['id']] = array_merge($cashier_data[$held['id']], $held);
    } else {
        $cashier_data[$held['id']] = $held;
    }
}

if ($export_format === 'csv') {
    // Export as CSV
    $filename = 'cashier_reports_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'Cashier ID',
        'Username',
        'Total Sales',
        'Total Revenue',
        'Average Sale Amount',
        'Days Worked',
        'Daily Average',
        'Total Voids',
        'Total Voided Amount',
        'Average Void Amount',
        'Total Held',
        'Total Held Amount',
        'Void Rate (%)',
        'Performance Rating'
    ]);
    
    // CSV Data
    foreach ($cashier_data as $cashier) {
        $void_rate = $cashier['total_sales'] > 0 ? ($cashier['total_voids'] / $cashier['total_sales']) * 100 : 0;
        
        if ($void_rate <= 2) {
            $rating = 'Excellent';
        } elseif ($void_rate <= 5) {
            $rating = 'Good';
        } elseif ($void_rate <= 10) {
            $rating = 'Fair';
        } else {
            $rating = 'Needs Improvement';
        }
        
        fputcsv($output, [
            $cashier['id'],
            $cashier['username'],
            $cashier['total_sales'] ?? 0,
            number_format($cashier['total_revenue'] ?? 0, 2),
            number_format($cashier['avg_sale_amount'] ?? 0, 2),
            $cashier['days_worked'] ?? 0,
            number_format($cashier['daily_average'] ?? 0, 2),
            $cashier['total_voids'] ?? 0,
            number_format($cashier['total_voided_amount'] ?? 0, 2),
            number_format($cashier['avg_void_amount'] ?? 0, 2),
            $cashier['total_held'] ?? 0,
            number_format($cashier['total_held_amount'] ?? 0, 2),
            number_format($void_rate, 2),
            $rating
        ]);
    }
    
    fclose($output);
    exit();
    
} elseif ($export_format === 'pdf') {
    // Export as PDF (simplified HTML version for now)
    $filename = 'cashier_reports_' . date('Y-m-d') . '.html';
    
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Cashier Performance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .summary { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .summary h3 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
        .rating-excellent { color: #28a745; font-weight: bold; }
        .rating-good { color: #17a2b8; font-weight: bold; }
        .rating-fair { color: #ffc107; font-weight: bold; }
        .rating-poor { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Cashier Performance Report</h1>
        <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        <p>Period: ' . $date_from . ' to ' . $date_to . '</p>
    </div>';
    
    // Calculate totals
    $total_revenue = array_sum(array_column($cashier_data, 'total_revenue'));
    $total_voids = array_sum(array_column($cashier_data, 'total_voids'));
    $total_voided_amount = array_sum(array_column($cashier_data, 'total_voided_amount'));
    $total_held = array_sum(array_column($cashier_data, 'total_held'));
    
    echo '<div class="summary">
        <h3>Summary</h3>
        <p><strong>Total Revenue:</strong> ' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($total_revenue, 2) . '</p>
        <p><strong>Total Voids:</strong> ' . number_format($total_voids) . '</p>
        <p><strong>Total Voided Amount:</strong> ' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($total_voided_amount, 2) . '</p>
        <p><strong>Total Held Transactions:</strong> ' . number_format($total_held) . '</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Cashier</th>
                <th>Total Sales</th>
                <th>Total Revenue</th>
                <th>Avg Sale</th>
                <th>Days Worked</th>
                <th>Daily Avg</th>
                <th>Voids</th>
                <th>Void Amount</th>
                <th>Held</th>
                <th>Void Rate</th>
                <th>Rating</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($cashier_data as $cashier) {
        $void_rate = $cashier['total_sales'] > 0 ? ($cashier['total_voids'] / $cashier['total_sales']) * 100 : 0;
        
        if ($void_rate <= 2) {
            $rating = 'Excellent';
            $rating_class = 'rating-excellent';
        } elseif ($void_rate <= 5) {
            $rating = 'Good';
            $rating_class = 'rating-good';
        } elseif ($void_rate <= 10) {
            $rating = 'Fair';
            $rating_class = 'rating-fair';
        } else {
            $rating = 'Needs Improvement';
            $rating_class = 'rating-poor';
        }
        
        echo '<tr>
            <td>' . htmlspecialchars($cashier['username']) . '</td>
            <td>' . number_format($cashier['total_sales'] ?? 0) . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($cashier['total_revenue'] ?? 0, 2) . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($cashier['avg_sale_amount'] ?? 0, 2) . '</td>
            <td>' . ($cashier['days_worked'] ?? 0) . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($cashier['daily_average'] ?? 0, 2) . '</td>
            <td>' . ($cashier['total_voids'] ?? 0) . '</td>
            <td>' . ($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($cashier['total_voided_amount'] ?? 0, 2) . '</td>
            <td>' . ($cashier['total_held'] ?? 0) . '</td>
            <td>' . number_format($void_rate, 1) . '%</td>
            <td class="' . $rating_class . '">' . $rating . '</td>
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
