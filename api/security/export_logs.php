<?php
session_start();
require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
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

// Check permissions
if (!hasPermission('export_security_logs', $permissions)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Get filters from query parameters
$severity_filter = $_GET['severity'] ?? '';
$event_type_filter = $_GET['event_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($severity_filter)) {
    $where_conditions[] = "severity = :severity";
    $params[':severity'] = $severity_filter;
}

if (!empty($event_type_filter)) {
    $where_conditions[] = "event_type LIKE :event_type";
    $params[':event_type'] = '%' . $event_type_filter . '%';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get security logs
$sql = "SELECT * FROM security_logs $where_clause ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$security_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = 'security_logs_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Create output stream
$output = fopen('php://output', 'w');

// Write CSV header
fputcsv($output, [
    'ID',
    'Event Type',
    'Severity',
    'IP Address',
    'User Agent',
    'Details',
    'Created At'
]);

// Write data rows
foreach ($security_logs as $log) {
    fputcsv($output, [
        $log['id'],
        $log['event_type'],
        $log['severity'],
        $log['ip_address'] ?? 'N/A',
        $log['user_agent'] ?? 'N/A',
        $log['details'] ?? '',
        $log['created_at']
    ]);
}

fclose($output);
exit();
?>
