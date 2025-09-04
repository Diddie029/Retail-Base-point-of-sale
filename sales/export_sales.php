<?php
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../include/db.php';

try {
    // Get filter parameters (same as index.php)
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $payment_method = $_GET['payment_method'] ?? 'all';
    $search = $_GET['search'] ?? '';
    
    // Build query conditions
    $conditions = [];
    $params = [];
    
    if ($start_date) {
        $conditions[] = "DATE(s.created_at) >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $conditions[] = "DATE(s.created_at) <= ?";
        $params[] = $end_date;
    }
    if ($payment_method && $payment_method !== 'all') {
        $conditions[] = "s.payment_method = ?";
        $params[] = $payment_method;
    }
    if ($search) {
        $conditions[] = "(s.customer_name LIKE ? OR s.customer_phone LIKE ? OR s.id LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    // Get sales data for export
    $export_query = "
        SELECT 
            s.id as sale_id,
            s.created_at,
            s.customer_name,
            s.customer_phone,
            s.customer_email,
            s.total_amount,
            s.tax_amount,
            s.discount,
            s.final_amount,
            s.payment_method,
            s.cash_received,
            s.change_amount,
            s.notes,
            u.username as cashier_name,
            COUNT(si.id) as item_count,
            GROUP_CONCAT(DISTINCT p.name SEPARATOR '; ') as products
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN products p ON si.product_id = p.id
        $where_clause
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ";
    
    $stmt = $conn->prepare($export_query);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    $filename = 'sales_export_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create file pointer for output
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    $headers = [
        'Sale ID',
        'Date',
        'Time',
        'Customer Name',
        'Customer Phone',
        'Customer Email',
        'Items Count',
        'Products',
        'Subtotal (KES)',
        'Tax Amount (KES)',
        'Discount (KES)',
        'Final Amount (KES)',
        'Payment Method',
        'Cash Received (KES)',
        'Change Amount (KES)',
        'Cashier',
        'Notes'
    ];
    
    fputcsv($output, $headers);
    
    // Add sales data
    foreach ($sales as $sale) {
        $row = [
            $sale['sale_id'],
            date('Y-m-d', strtotime($sale['created_at'])),
            date('H:i:s', strtotime($sale['created_at'])),
            $sale['customer_name'] ?: 'Walking Customer',
            $sale['customer_phone'] ?: '',
            $sale['customer_email'] ?: '',
            $sale['item_count'],
            $sale['products'] ?: '',
            number_format($sale['total_amount'], 2),
            number_format($sale['tax_amount'] ?: 0, 2),
            number_format($sale['discount'] ?: 0, 2),
            number_format($sale['final_amount'], 2),
            ucfirst(str_replace('_', ' ', $sale['payment_method'])),
            $sale['cash_received'] ? number_format($sale['cash_received'], 2) : '',
            $sale['change_amount'] ? number_format($sale['change_amount'], 2) : '',
            $sale['cashier_name'] ?: 'Unknown',
            $sale['notes'] ?: ''
        ];
        
        fputcsv($output, $row);
    }
    
    // Add summary row
    if (!empty($sales)) {
        $total_sales = count($sales);
        $total_revenue = array_sum(array_column($sales, 'final_amount'));
        $avg_sale = $total_revenue / $total_sales;
        
        fputcsv($output, []); // Empty row
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Sales:', $total_sales]);
        fputcsv($output, ['Total Revenue (KES):', number_format($total_revenue, 2)]);
        fputcsv($output, ['Average Sale (KES):', number_format($avg_sale, 2)]);
        fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        
        if ($payment_method !== 'all') {
            fputcsv($output, ['Payment Method Filter:', ucfirst(str_replace('_', ' ', $payment_method))]);
        }
        
        if ($search) {
            fputcsv($output, ['Search Filter:', $search]);
        }
    }
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    // If error occurs, redirect back with error message
    header('Location: index.php?error=' . urlencode('Export failed: ' . $e->getMessage()));
    exit();
}
?>
