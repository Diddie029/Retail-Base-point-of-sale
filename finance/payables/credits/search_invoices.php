<?php
session_start();
require_once __DIR__ . '/../../../include/db.php';
require_once __DIR__ . '/../../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get parameters
$supplier_id = $_GET['supplier_id'] ?? 0;
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;

if (!$supplier_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Supplier ID is required']);
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Build search query
$where_conditions = [
    "si.supplier_id = :supplier_id",
    "si.balance_due > 0",
    "si.status IN ('pending', 'partial', 'overdue')"
];

$params = [':supplier_id' => $supplier_id];

if (!empty($search)) {
    $where_conditions[] = "(si.invoice_number LIKE :search OR si.notes LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM supplier_invoices si
    $where_clause
";

$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_invoices = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_invoices / $per_page);

// Get invoices with pagination
$offset = ($page - 1) * $per_page;
$sql = "
    SELECT 
        si.id,
        si.invoice_number,
        si.invoice_date,
        si.due_date,
        si.total_amount,
        si.balance_due,
        si.status,
        si.notes
    FROM supplier_invoices si
    $where_clause
    ORDER BY si.invoice_date DESC, si.invoice_number
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format response
$response = [
    'invoices' => $invoices,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_invoices' => $total_invoices,
        'per_page' => $per_page
    ],
    'settings' => $settings
];

header('Content-Type: application/json');
echo json_encode($response);
?>
