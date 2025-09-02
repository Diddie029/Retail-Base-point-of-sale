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

// Check if user has permission to export customer data
if (!hasPermission('export_customer_data', $permissions)) {
    header("Location: index.php");
    exit();
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Build WHERE clause (same as in index.php)
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(c.first_name LIKE :search OR c.last_name LIKE :search OR c.email LIKE :search OR c.phone LIKE :search OR c.customer_number LIKE :search OR c.company_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "c.membership_status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "c.customer_type = :type";
    $params[':type'] = $type_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get customers for export
$sql = "SELECT
        c.customer_number,
        c.first_name,
        c.last_name,
        c.email,
        c.phone,
        c.mobile,
        c.address,
        c.city,
        c.state,
        c.zip_code,
        c.country,
        c.date_of_birth,
        c.gender,
        c.customer_type,
        c.company_name,
        c.tax_id,
        c.credit_limit,
        c.current_balance,
        c.loyalty_points,
        c.membership_status,
        c.membership_level,
        c.preferred_payment_method,
        c.notes,
        c.created_at,
        c.updated_at
    FROM customers c $where_clause ORDER BY c.created_at DESC";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=customers_' . date('Y-m-d_H-i-s') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Write CSV headers
$headers = [
    'Customer Number',
    'First Name',
    'Last Name',
    'Email',
    'Phone',
    'Mobile',
    'Address',
    'City',
    'State',
    'ZIP Code',
    'Country',
    'Date of Birth',
    'Gender',
    'Customer Type',
    'Company Name',
    'Tax ID',
    'Credit Limit',
    'Current Balance',
    'Loyalty Points',
    'Membership Status',
    'Membership Level',
    'Preferred Payment Method',
    'Notes',
    'Created Date',
    'Last Updated'
];

fputcsv($output, $headers);

// Write customer data
foreach ($customers as $customer) {
    $row = [
        $customer['customer_number'],
        $customer['first_name'],
        $customer['last_name'],
        $customer['email'],
        $customer['phone'],
        $customer['mobile'],
        $customer['address'],
        $customer['city'],
        $customer['state'],
        $customer['zip_code'],
        $customer['country'],
        $customer['date_of_birth'] ? date('Y-m-d', strtotime($customer['date_of_birth'])) : '',
        ucfirst($customer['gender'] ?? ''),
        ucfirst($customer['customer_type']),
        $customer['company_name'],
        $customer['tax_id'],
        number_format($customer['credit_limit'], 2),
        number_format($customer['current_balance'], 2),
        $customer['loyalty_points'],
        ucfirst($customer['membership_status']),
        $customer['membership_level'],
        ucfirst(str_replace('_', ' ', $customer['preferred_payment_method'] ?? '')),
        $customer['notes'],
        date('Y-m-d H:i:s', strtotime($customer['created_at'])),
        date('Y-m-d H:i:s', strtotime($customer['updated_at']))
    ];

    fputcsv($output, $row);
}

// Log the export activity
$log_stmt = $conn->prepare("
    INSERT INTO activity_logs (user_id, action, details, created_at)
    VALUES (:user_id, :action, :details, NOW())
");
$log_stmt->execute([
    ':user_id' => $user_id,
    ':action' => "Exported customer data",
    ':details' => json_encode([
        'export_type' => 'csv',
        'record_count' => count($customers),
        'filters' => [
            'search' => $search,
            'status' => $status_filter,
            'type' => $type_filter
        ]
    ])
]);

fclose($output);
exit();
?>
