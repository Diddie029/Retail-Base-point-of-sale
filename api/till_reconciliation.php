<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get user info
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

// Check if user has permission to view sales or finance
$hasAccess = false;
if (isAdmin($role_name) || hasPermission('view_sales', $permissions) || 
    hasPermission('manage_sales', $permissions) || hasPermission('view_finance', $permissions)) {
    $hasAccess = true;
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Get system settings
$stmt = $conn->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$currency_symbol = $settings['currency_symbol'] ?? 'KES';

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_till_reconciliation':
        getTillReconciliation();
        break;
    case 'get_till_closing_history':
        getTillClosingHistory();
        break;
    case 'export_till_reconciliation':
        exportTillReconciliation();
        break;
    case 'get_days_not_closed':
        getDaysNotClosed();
        break;
    case 'close_missed_day':
        closeMissedDay();
        break;
    case 'close_all_missed_days':
        closeAllMissedDays();
        break;
    case 'close_all_no_activity_days':
        closeAllNoActivityDays();
        break;
    case 'get_till_closing_details':
        getTillClosingDetails();
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function getTillReconciliation() {
    global $conn, $currency_symbol;
    
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $till_id = $_GET['till_id'] ?? '';
    $status = $_GET['status'] ?? '';
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 40);
    $offset = ($page - 1) * $limit;
    
    try {
        // Build the base query for counting
        $count_sql = "
            SELECT COUNT(*) as total
            FROM till_closings tc
            LEFT JOIN register_tills rt ON tc.till_id = rt.id
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE DATE(tc.closed_at) BETWEEN :date_from AND :date_to
        ";
        
        $params = [
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ];
        
        if (!empty($till_id)) {
            $count_sql .= " AND tc.till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        if (!empty($status)) {
            if ($status === 'missed_day_closed') {
                $count_sql .= " AND tc.closing_notes LIKE '%Missed day closure%'";
            } else {
                $count_sql .= " AND tc.shortage_type = :status";
                $params[':status'] = $status;
            }
        }
        
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Build the main query with pagination
        $sql = "
            SELECT 
                tc.id,
                tc.till_id,
                tc.user_id,
                tc.opening_amount,
                tc.total_sales,
                tc.total_drops,
                tc.expected_balance,
                tc.actual_counted_amount,
                tc.difference,
                tc.shortage_type,
                tc.closing_notes,
                tc.closed_at,
                rt.till_name,
                u.username as cashier_name
            FROM till_closings tc
            LEFT JOIN register_tills rt ON tc.till_id = rt.id
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE DATE(tc.closed_at) BETWEEN :date_from AND :date_to
        ";
        
        if (!empty($till_id)) {
            $sql .= " AND tc.till_id = :till_id";
        }
        
        if (!empty($status)) {
            if ($status === 'missed_day_closed') {
                $sql .= " AND tc.closing_notes LIKE '%Missed day closure%'";
            } else {
                $sql .= " AND tc.shortage_type = :status";
            }
        }
        
        $sql .= " ORDER BY tc.closed_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':date_from', $date_from);
        $stmt->bindParam(':date_to', $date_to);
        if (!empty($till_id)) {
            $stmt->bindParam(':till_id', $till_id);
        }
        if (!empty($status) && $status !== 'missed_day_closed') {
            $stmt->bindParam(':status', $status);
        }
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $reconciliation = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add currency symbol to each record
        foreach ($reconciliation as &$record) {
            $record['currency_symbol'] = $currency_symbol;
        }
        
        // Calculate summary statistics (using all records, not just current page)
        $summary_sql = "
            SELECT 
                COUNT(*) as total_closings,
                SUM(CASE WHEN shortage_type = 'exact' THEN 1 ELSE 0 END) as exact_matches,
                SUM(CASE WHEN shortage_type = 'shortage' THEN 1 ELSE 0 END) as shortages,
                SUM(CASE WHEN shortage_type = 'excess' THEN 1 ELSE 0 END) as excess
            FROM till_closings tc
            WHERE DATE(tc.closed_at) BETWEEN :date_from AND :date_to
        ";
        
        $summary_params = [':date_from' => $date_from, ':date_to' => $date_to];
        if (!empty($till_id)) {
            $summary_sql .= " AND tc.till_id = :till_id";
            $summary_params[':till_id'] = $till_id;
        }
        if (!empty($status)) {
            if ($status === 'missed_day_closed') {
                $summary_sql .= " AND tc.closing_notes LIKE '%Missed day closure%'";
            } else {
                $summary_sql .= " AND tc.shortage_type = :status";
                $summary_params[':status'] = $status;
            }
        }
        
        $summary_stmt = $conn->prepare($summary_sql);
        $summary_stmt->execute($summary_params);
        $summary_data = $summary_stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_days = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24) + 1;
        $days_not_closed = $total_days - $summary_data['total_closings'];
        
        $summary = [
            'total_closings' => $summary_data['total_closings'],
            'exact_matches' => $summary_data['exact_matches'],
            'shortages' => $summary_data['shortages'],
            'excess' => $summary_data['excess'],
            'days_not_closed' => max(0, $days_not_closed),
            'total_days' => $total_days
        ];
        
        // Pagination info
        $total_pages = ceil($total_records / $limit);
        $pagination = [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'records_per_page' => $limit
        ];
        
        echo json_encode([
            'success' => true,
            'reconciliation' => $reconciliation,
            'summary' => $summary,
            'pagination' => $pagination
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function getTillClosingHistory() {
    global $conn, $currency_symbol;
    
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $till_id = $_GET['till_id'] ?? '';
    
    try {
        // Build the query
        $sql = "
            SELECT 
                tc.id,
                tc.till_id,
                tc.user_id,
                tc.opening_amount,
                tc.total_sales,
                tc.total_drops,
                tc.expected_balance,
                tc.actual_counted_amount,
                tc.difference,
                tc.shortage_type,
                tc.closing_notes,
                tc.closed_at,
                rt.till_name,
                u.username as cashier_name
            FROM till_closings tc
            LEFT JOIN register_tills rt ON tc.till_id = rt.id
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE DATE(tc.closed_at) BETWEEN :date_from AND :date_to
        ";
        
        $params = [
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ];
        
        if (!empty($till_id)) {
            $sql .= " AND tc.till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        $sql .= " ORDER BY tc.closed_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add currency symbol to each record
        foreach ($history as &$record) {
            $record['currency_symbol'] = $currency_symbol;
        }
        
        echo json_encode([
            'success' => true,
            'history' => $history
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function exportTillReconciliation() {
    global $conn, $currency_symbol;
    
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $till_id = $_GET['till_id'] ?? '';
    $status = $_GET['status'] ?? '';
    
    try {
        // Build the query (same as getTillReconciliation)
        $sql = "
            SELECT 
                tc.id,
                tc.till_id,
                tc.user_id,
                tc.opening_amount,
                tc.total_sales,
                tc.total_drops,
                tc.expected_balance,
                tc.actual_counted_amount,
                tc.difference,
                tc.shortage_type,
                tc.closing_notes,
                tc.closed_at,
                rt.till_name,
                u.username as cashier_name
            FROM till_closings tc
            LEFT JOIN register_tills rt ON tc.till_id = rt.id
            LEFT JOIN users u ON tc.user_id = u.id
            WHERE DATE(tc.closed_at) BETWEEN :date_from AND :date_to
        ";
        
        $params = [
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ];
        
        if (!empty($till_id)) {
            $sql .= " AND tc.till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        if (!empty($status)) {
            $sql .= " AND tc.shortage_type = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " ORDER BY tc.closed_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        $filename = 'till_reconciliation_' . $date_from . '_to_' . $date_to . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Create CSV output
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Date/Time',
            'Till Name',
            'Cashier',
            'Opening Amount',
            'Total Sales',
            'Total Drops',
            'Expected Balance',
            'Actual Amount',
            'Difference',
            'Status',
            'Notes'
        ]);
        
        // CSV data
        foreach ($data as $row) {
            $status_text = $row['shortage_type'] === 'exact' ? 'Exact' : 
                          ($row['shortage_type'] === 'shortage' ? 'Shortage' : 'Excess');
            
            fputcsv($output, [
                $row['closed_at'],
                $row['till_name'],
                $row['cashier_name'],
                $currency_symbol . number_format($row['opening_amount'], 2),
                $currency_symbol . number_format($row['total_sales'], 2),
                $currency_symbol . number_format($row['total_drops'], 2),
                $currency_symbol . number_format($row['expected_balance'], 2),
                $currency_symbol . number_format($row['actual_counted_amount'], 2),
                $currency_symbol . number_format($row['difference'], 2),
                $status_text,
                $row['closing_notes']
            ]);
        }
        
        fclose($output);
        exit();
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Export failed']);
    }
}

function getDaysNotClosed() {
    global $conn;
    
    $date_from = $_GET['date_from'] ?? date('Y-m-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $till_id = $_GET['till_id'] ?? '';
    
    try {
        // Generate all dates in the range
        $start_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        $all_dates = [];
        
        while ($start_date <= $end_date) {
            $all_dates[] = $start_date->format('Y-m-d');
            $start_date->add(new DateInterval('P1D'));
        }
        
        // Get existing closing dates
        $sql = "SELECT DISTINCT DATE(closed_at) as closing_date, till_id FROM till_closings WHERE DATE(closed_at) BETWEEN :date_from AND :date_to";
        $params = [':date_from' => $date_from, ':date_to' => $date_to];
        
        if (!empty($till_id)) {
            $sql .= " AND till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $closed_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array of closed dates by till
        $closed_by_till = [];
        foreach ($closed_dates as $closed) {
            $closed_by_till[$closed['till_id']][] = $closed['closing_date'];
        }
        
        // Get all tills
        $till_sql = "SELECT id, till_name FROM register_tills WHERE is_active = 1";
        if (!empty($till_id)) {
            $till_sql .= " AND id = :till_id";
        }
        
        $till_stmt = $conn->prepare($till_sql);
        if (!empty($till_id)) {
            $till_stmt->bindParam(':till_id', $till_id);
        }
        $till_stmt->execute();
        $tills = $till_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Find missing dates
        $days_not_closed = [];
        foreach ($tills as $till) {
            $till_id = $till['id'];
            $closed_dates_for_till = $closed_by_till[$till_id] ?? [];
            
            foreach ($all_dates as $date) {
                if (!in_array($date, $closed_dates_for_till)) {
                    // Check if there was any activity on this date
                    $activity_sql = "
                        SELECT COUNT(*) as activity_count, MAX(created_at) as last_activity
                        FROM (
                            SELECT created_at FROM sales WHERE DATE(created_at) = :date AND till_id = :till_id
                            UNION ALL
                            SELECT created_at FROM cash_drops WHERE DATE(created_at) = :date AND till_id = :till_id
                        ) as activity
                    ";
                    
                    $activity_stmt = $conn->prepare($activity_sql);
                    $activity_stmt->execute([':date' => $date, ':till_id' => $till_id]);
                    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $days_not_closed[] = [
                        'date' => $date,
                        'till_id' => $till_id,
                        'till_name' => $till['till_name'],
                        'status' => $activity['activity_count'] > 0 ? 'partial' : 'no_activity',
                        'last_activity' => $activity['last_activity']
                    ];
                }
            }
        }
        
        // Sort by date
        usort($days_not_closed, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        echo json_encode([
            'success' => true,
            'days_not_closed' => $days_not_closed
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function closeMissedDay() {
    global $conn, $currency_symbol;
    
    $date = $_POST['date'] ?? '';
    $till_id = $_POST['till_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $opening_amount = floatval($_POST['opening_amount'] ?? 0);
    $sales_amount = floatval($_POST['sales_amount'] ?? 0);
    $drops_amount = floatval($_POST['drops_amount'] ?? 0);
    $actual_amount = floatval($_POST['actual_amount'] ?? 0);
    $expected_balance = floatval($_POST['expected_balance'] ?? 0);
    $difference = floatval($_POST['difference'] ?? 0);
    $shortage_type = $_POST['shortage_type'] ?? 'exact';
    
    if (empty($date) || empty($till_id)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    try {
        // Check if already closed
        $check_stmt = $conn->prepare("SELECT id FROM till_closings WHERE DATE(closed_at) = :date AND till_id = :till_id");
        $check_stmt->execute([':date' => $date, ':till_id' => $till_id]);
        
        if ($check_stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'This day is already closed']);
            return;
        }
        
        // Create closing record with provided amounts
        $stmt = $conn->prepare("
            INSERT INTO till_closings (
                till_id, user_id, opening_amount, total_sales, total_drops, 
                expected_balance, cash_amount, voucher_amount, loyalty_points, 
                other_amount, other_description, actual_counted_amount, 
                total_amount, difference, shortage_type, closing_notes, 
                allow_exceed, closed_at
            ) VALUES (
                :till_id, :user_id, :opening_amount, :sales_amount, :drops_amount, 
                :expected_balance, :cash_amount, :voucher_amount, :loyalty_points, 
                :other_amount, :other_description, :actual_amount, 
                :total_amount, :difference, :shortage_type, :notes, 
                0, :closed_at
            )
        ");
        
        $user_id = $_SESSION['user_id'];
        $closed_at = $date . ' 23:59:59';
        
        $stmt->execute([
            ':till_id' => $till_id,
            ':user_id' => $user_id,
            ':opening_amount' => $opening_amount,
            ':sales_amount' => $sales_amount,
            ':drops_amount' => $drops_amount,
            ':expected_balance' => $expected_balance,
            ':cash_amount' => $actual_amount, // Use actual_amount as cash_amount
            ':voucher_amount' => 0.00,
            ':loyalty_points' => 0.00,
            ':other_amount' => 0.00,
            ':other_description' => 'Missed day closure',
            ':actual_amount' => $actual_amount,
            ':total_amount' => $actual_amount,
            ':difference' => $difference,
            ':shortage_type' => $shortage_type,
            ':notes' => $notes,
            ':closed_at' => $closed_at
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Missed day closed successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function closeAllMissedDays() {
    global $conn;
    
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $till_id = $_POST['till_id'] ?? '';
    
    if (empty($date_from) || empty($date_to)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    try {
        // Get days not closed (reuse the logic from getDaysNotClosed)
        $start_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        $all_dates = [];
        
        while ($start_date <= $end_date) {
            $all_dates[] = $start_date->format('Y-m-d');
            $start_date->add(new DateInterval('P1D'));
        }
        
        // Get existing closing dates
        $sql = "SELECT DISTINCT DATE(closed_at) as closing_date, till_id FROM till_closings WHERE DATE(closed_at) BETWEEN :date_from AND :date_to";
        $params = [':date_from' => $date_from, ':date_to' => $date_to];
        
        if (!empty($till_id)) {
            $sql .= " AND till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $closed_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array of closed dates by till
        $closed_by_till = [];
        foreach ($closed_dates as $closed) {
            $closed_by_till[$closed['till_id']][] = $closed['closing_date'];
        }
        
        // Get all tills
        $till_sql = "SELECT id, till_name FROM register_tills WHERE is_active = 1";
        if (!empty($till_id)) {
            $till_sql .= " AND id = :till_id";
        }
        
        $till_stmt = $conn->prepare($till_sql);
        if (!empty($till_id)) {
            $till_stmt->bindParam(':till_id', $till_id);
        }
        $till_stmt->execute();
        $tills = $till_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $closed_count = 0;
        $user_id = $_SESSION['user_id'];
        
        // Close all missed days
        foreach ($tills as $till) {
            $current_till_id = $till['id'];
            $closed_dates_for_till = $closed_by_till[$current_till_id] ?? [];
            
            foreach ($all_dates as $date) {
                if (!in_array($date, $closed_dates_for_till)) {
                    $stmt = $conn->prepare("
                        INSERT INTO till_closings (
                            till_id, user_id, opening_amount, total_sales, total_drops, 
                            expected_balance, cash_amount, voucher_amount, loyalty_points, 
                            other_amount, other_description, actual_counted_amount, 
                            total_amount, difference, shortage_type, closing_notes, 
                            allow_exceed, closed_at
                        ) VALUES (
                            :till_id, :user_id, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 
                            0.00, 'Bulk missed day closure', 0.00, 0.00, 0.00, 'exact', 
                            'Closed via bulk operation', 0, :closed_at
                        )
                    ");
                    
                    $closed_at = $date . ' 23:59:59';
                    $stmt->execute([
                        ':till_id' => $current_till_id,
                        ':user_id' => $user_id,
                        ':closed_at' => $closed_at
                    ]);
                    
                    $closed_count++;
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Successfully closed {$closed_count} missed days",
            'closed_count' => $closed_count
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function closeAllNoActivityDays() {
    global $conn;
    
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $till_id = $_POST['till_id'] ?? '';
    
    if (empty($date_from) || empty($date_to)) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    try {
        // Get days not closed (reuse the logic from getDaysNotClosed)
        $start_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        $all_dates = [];
        
        while ($start_date <= $end_date) {
            $all_dates[] = $start_date->format('Y-m-d');
            $start_date->add(new DateInterval('P1D'));
        }
        
        // Get existing closing dates
        $sql = "SELECT DISTINCT DATE(closed_at) as closing_date, till_id FROM till_closings WHERE DATE(closed_at) BETWEEN :date_from AND :date_to";
        $params = [':date_from' => $date_from, ':date_to' => $date_to];
        
        if (!empty($till_id)) {
            $sql .= " AND till_id = :till_id";
            $params[':till_id'] = $till_id;
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $closed_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create array of closed dates by till
        $closed_by_till = [];
        foreach ($closed_dates as $closed) {
            $closed_by_till[$closed['till_id']][] = $closed['closing_date'];
        }
        
        // Get all tills
        $till_sql = "SELECT id, till_name FROM register_tills WHERE is_active = 1";
        if (!empty($till_id)) {
            $till_sql .= " AND id = :till_id";
        }
        
        $till_stmt = $conn->prepare($till_sql);
        if (!empty($till_id)) {
            $till_stmt->bindParam(':till_id', $till_id);
        }
        $till_stmt->execute();
        $tills = $till_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $closed_count = 0;
        $user_id = $_SESSION['user_id'];
        
        // Close only "No Activity" missed days
        foreach ($tills as $till) {
            $current_till_id = $till['id'];
            $closed_dates_for_till = $closed_by_till[$current_till_id] ?? [];
            
            foreach ($all_dates as $date) {
                if (!in_array($date, $closed_dates_for_till)) {
                    // Check if there was any activity on this date
                    $activity_sql = "
                        SELECT COUNT(*) as activity_count
                        FROM (
                            SELECT created_at FROM sales WHERE DATE(created_at) = :date AND till_id = :till_id
                            UNION ALL
                            SELECT created_at FROM cash_drops WHERE DATE(created_at) = :date AND till_id = :till_id
                        ) as activity
                    ";
                    
                    $activity_stmt = $conn->prepare($activity_sql);
                    $activity_stmt->execute([':date' => $date, ':till_id' => $current_till_id]);
                    $activity = $activity_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Only close if there was NO activity
                    if ($activity['activity_count'] == 0) {
                        $stmt = $conn->prepare("
                            INSERT INTO till_closings (
                                till_id, user_id, opening_amount, total_sales, total_drops, 
                                expected_balance, cash_amount, voucher_amount, loyalty_points, 
                                other_amount, other_description, actual_counted_amount, 
                                total_amount, difference, shortage_type, closing_notes, 
                                allow_exceed, closed_at
                            ) VALUES (
                                :till_id, :user_id, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 
                                0.00, 'Bulk no activity day closure', 0.00, 0.00, 0.00, 'exact', 
                                'Closed via bulk operation - No activity day', 0, :closed_at
                            )
                        ");
                        
                        $closed_at = $date . ' 23:59:59';
                        $stmt->execute([
                            ':till_id' => $current_till_id,
                            ':user_id' => $user_id,
                            ':closed_at' => $closed_at
                        ]);
                        
                        $closed_count++;
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Successfully closed {$closed_count} 'No Activity' days",
            'closed_count' => $closed_count
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
}

function getTillClosingDetails() {
    global $conn, $currency_symbol;
    
    $closing_id = $_GET['closing_id'] ?? '';
    
    if (empty($closing_id)) {
        echo json_encode(['success' => false, 'message' => 'Closing ID is required']);
        return;
    }
    
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        return;
    }
    
    try {
        // First, get basic closing information
        $sql = "SELECT * FROM till_closings WHERE id = :closing_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':closing_id', $closing_id);
        $stmt->execute();
        $closing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$closing) {
            echo json_encode(['success' => false, 'message' => 'Closing record not found']);
            return;
        }
        
        // Get till information
        $till_sql = "SELECT till_name FROM register_tills WHERE id = :till_id";
        $till_stmt = $conn->prepare($till_sql);
        $till_stmt->bindParam(':till_id', $closing['till_id']);
        $till_stmt->execute();
        $till_info = $till_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get user information
        $user_sql = "SELECT username, first_name, last_name, email FROM users WHERE id = :user_id";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bindParam(':user_id', $closing['user_id']);
        $user_stmt->execute();
        $user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Merge the information
        $closing['till_name'] = $till_info['till_name'] ?? 'Unknown';
        $closing['till_location'] = 'N/A'; // Column doesn't exist in register_tills table
        $closing['cashier_name'] = $user_info['username'] ?? 'Unknown';
        $closing['first_name'] = $user_info['first_name'] ?? '';
        $closing['last_name'] = $user_info['last_name'] ?? '';
        $closing['email'] = $user_info['email'] ?? '';
        
        // Get cash drops for this closing
        $drops_sql = "SELECT * FROM cash_drops WHERE till_id = :till_id AND DATE(drop_date) = DATE(:closed_at) ORDER BY drop_date DESC";
        $drops_stmt = $conn->prepare($drops_sql);
        $drops_stmt->bindParam(':till_id', $closing['till_id']);
        $drops_stmt->bindParam(':closed_at', $closing['closed_at']);
        $drops_stmt->execute();
        $cash_drops = $drops_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add user info to cash drops and ensure amount is properly formatted
        foreach ($cash_drops as &$drop) {
            $user_sql = "SELECT username FROM users WHERE id = :user_id";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bindParam(':user_id', $drop['user_id']);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $drop['dropped_by'] = $user['username'] ?? 'Unknown';
            
            // Map database columns to display columns
            $drop['amount'] = $drop['drop_amount'] ?? 0;
            $drop['reason'] = $drop['notes'] ?? 'N/A';
            $drop['created_at'] = $drop['drop_date'];
        }
        
        // Get sales summary for this closing
        $sales_sql = "SELECT COUNT(*) as total_transactions, SUM(total_amount) as total_sales FROM sales WHERE till_id = :till_id AND DATE(created_at) = DATE(:closed_at)";
        $sales_stmt = $conn->prepare($sales_sql);
        $sales_stmt->bindParam(':till_id', $closing['till_id']);
        $sales_stmt->bindParam(':closed_at', $closing['closed_at']);
        $sales_stmt->execute();
        $sales_summary = $sales_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Initialize payment method breakdown
        $sales_summary['cash_sales'] = 0;
        $sales_summary['card_sales'] = 0;
        $sales_summary['mobile_sales'] = 0;
        $sales_summary['voucher_sales'] = 0;
        $sales_summary['loyalty_sales'] = 0;
        
        // Get payment method breakdown
        $payment_sql = "SELECT payment_method, SUM(total_amount) as amount FROM sales WHERE till_id = :till_id AND DATE(created_at) = DATE(:closed_at) GROUP BY payment_method";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bindParam(':till_id', $closing['till_id']);
        $payment_stmt->bindParam(':closed_at', $closing['closed_at']);
        $payment_stmt->execute();
        $payment_breakdown = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($payment_breakdown as $payment) {
            $method = $payment['payment_method'];
            $amount = $payment['amount'];
            switch ($method) {
                case 'cash':
                    $sales_summary['cash_sales'] = $amount;
                    break;
                case 'card':
                    $sales_summary['card_sales'] = $amount;
                    break;
                case 'mobile_money':
                    $sales_summary['mobile_sales'] = $amount;
                    break;
                case 'voucher':
                    $sales_summary['voucher_sales'] = $amount;
                    break;
                case 'loyalty_points':
                    $sales_summary['loyalty_sales'] = $amount;
                    break;
            }
        }
        
        // Add currency symbol
        $closing['currency_symbol'] = $currency_symbol;
        
        echo json_encode([
            'success' => true,
            'closing' => $closing,
            'cash_drops' => $cash_drops,
            'sales_summary' => $sales_summary
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error occurred: ' . $e->getMessage()]);
    }
}
?>
