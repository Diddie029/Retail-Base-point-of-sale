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
$username = $_SESSION['username'] ?? 'User';
$role_name = $_SESSION['role'] ?? 'User';

// Get user permissions
$permissions = [];
if ($role_name !== 'Admin') {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p
        JOIN role_permissions rp ON p.id = rp.permission_id
        JOIN users u ON rp.role_id = u.role_id
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
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
$status = $_GET['status'] ?? '';
$export_format = $_GET['export'] ?? 'csv';

// Build the query
$where_conditions = ["DATE(ht.created_at) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];

if (!empty($cashier_id)) {
    $where_conditions[] = "ht.user_id = ?";
    $params[] = $cashier_id;
}

if (!empty($status)) {
    $where_conditions[] = "ht.status = ?";
    $params[] = $status;
}

$where_clause = implode(' AND ', $where_conditions);

// Get held transactions
$stmt = $conn->prepare("
    SELECT ht.*, u.username as cashier_name, rt.till_name, rt.till_code
    FROM held_transactions ht
    LEFT JOIN users u ON ht.user_id = u.id
    LEFT JOIN register_tills rt ON ht.till_id = rt.id
    WHERE $where_clause
    ORDER BY ht.created_at DESC
");
$stmt->execute($params);
$held_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$summary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_held,
        COUNT(DISTINCT ht.user_id) as cashiers_involved,
        SUM(JSON_UNQUOTE(JSON_EXTRACT(ht.cart_data, '$.total'))) as total_held_amount,
        COUNT(CASE WHEN ht.status = 'held' THEN 1 END) as currently_held,
        COUNT(CASE WHEN ht.status = 'resumed' THEN 1 END) as resumed,
        COUNT(CASE WHEN ht.status = 'deleted' THEN 1 END) as deleted,
        COUNT(CASE WHEN ht.status = 'completed' THEN 1 END) as completed
    FROM held_transactions ht
    WHERE $where_clause
");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

if ($export_format === 'csv') {
    // CSV Export
    $filename = 'held_transactions_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, [
        'Transaction ID',
        'Date',
        'Time',
        'Cashier',
        'Till Name',
        'Till Code',
        'Status',
        'Total Amount',
        'Items Count',
        'Reason',
        'Customer Reference',
        'Created At',
        'Updated At'
    ]);
    
    // CSV Data
    foreach ($held_transactions as $held) {
        $cart_data = json_decode($held['cart_data'], true);
        $total_amount = $cart_data['total'] ?? 0;
        $items_count = count($cart_data['items'] ?? []);
        
        fputcsv($output, [
            $held['id'],
            date('Y-m-d', strtotime($held['created_at'])),
            date('H:i:s', strtotime($held['created_at'])),
            $held['cashier_name'] ?? 'Unknown',
            $held['till_name'] ?? 'N/A',
            $held['till_code'] ?? '',
            ucfirst($held['status']),
            number_format($total_amount, 2),
            $items_count,
            $held['reason'] ?? '',
            $held['customer_reference'] ?? '',
            date('Y-m-d H:i:s', strtotime($held['created_at'])),
            $held['updated_at'] ? date('Y-m-d H:i:s', strtotime($held['updated_at'])) : ''
        ]);
    }
    
    fclose($output);
    exit();
} else {
    // PDF Export
    require_once '../vendor/autoload.php';
    
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'orientation' => 'L',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 16,
        'margin_bottom' => 16,
        'margin_header' => 9,
        'margin_footer' => 9
    ]);
    
    $mpdf->SetTitle('Held Transactions Report');
    $mpdf->SetAuthor('Point of Sale System');
    
    $html = '
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { color: #333; margin: 0; }
        .header p { color: #666; margin: 5px 0; }
        .summary { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        .summary h3 { color: #333; margin-top: 0; }
        .summary-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .summary-label { font-weight: bold; }
        .summary-value { color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .status-held { background-color: #fff3cd; }
        .status-resumed { background-color: #d4edda; }
        .status-deleted { background-color: #f8d7da; }
        .status-completed { background-color: #d1ecf1; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
    </style>
    
    <div class="header">
        <h1>Held Transactions Report</h1>
        <p>Generated on ' . date('F d, Y \a\t H:i:s') . '</p>
        <p>Period: ' . date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to)) . '</p>
    </div>
    
    <div class="summary">
        <h3>Summary Statistics</h3>
        <div class="summary-row">
            <span class="summary-label">Total Held Transactions:</span>
            <span class="summary-value">' . number_format($summary['total_held']) . '</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Currently Held:</span>
            <span class="summary-value">' . number_format($summary['currently_held']) . '</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Resumed:</span>
            <span class="summary-value">' . number_format($summary['resumed']) . '</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Deleted:</span>
            <span class="summary-value">' . number_format($summary['deleted']) . '</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Completed:</span>
            <span class="summary-value">' . number_format($summary['completed']) . '</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Total Value:</span>
            <span class="summary-value">' . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($summary['total_held_amount'], 2) . '</span>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Date/Time</th>
                <th>Cashier</th>
                <th>Till</th>
                <th>Status</th>
                <th>Amount</th>
                <th>Items</th>
                <th>Reason</th>
                <th>Customer Ref</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($held_transactions as $held) {
        $cart_data = json_decode($held['cart_data'], true);
        $total_amount = $cart_data['total'] ?? 0;
        $items_count = count($cart_data['items'] ?? []);
        
        echo '<tr>
            <td>' . $held['id'] . '</td>
            <td>' . date('Y-m-d H:i:s', strtotime($held['created_at'])) . '</td>
            <td>' . htmlspecialchars($held['cashier_name'] ?? 'Unknown') . '</td>
            <td>' . htmlspecialchars($held['till_name'] ?? 'N/A') . '</td>
            <td class="status-' . $held['status'] . '">' . ucfirst($held['status']) . '</td>
            <td class="text-right">' . htmlspecialchars($settings['currency_symbol'] ?? 'KES') . ' ' . number_format($total_amount, 2) . '</td>
            <td class="text-center">' . $items_count . '</td>
            <td>' . htmlspecialchars($held['reason'] ?? '') . '</td>
            <td>' . htmlspecialchars($held['customer_reference'] ?? '') . '</td>
        </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    </div>';
    
    $mpdf->WriteHTML($html);
    $mpdf->Output('held_transactions_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
    exit();
}
?>
