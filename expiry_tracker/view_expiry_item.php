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

// Check if user has permission to view expiry tracker
if (!hasPermission('view_expiry_alerts', $permissions) && !hasPermission('manage_expiry_tracker', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=permission_denied");
    exit();
}

// Get expiry item ID
$expiry_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$expiry_id) {
    header("Location: expiry_tracker.php?error=invalid_item");
    exit();
}

// Get expiry item details with comprehensive information
try {
    $stmt = $conn->prepare("
        SELECT
            ped.*,
            p.name as product_name,
            p.sku,
            p.description,
            p.image_url,
            p.category_id,
            p.quantity as product_total_quantity,
            p.cost_price as product_cost_price,
            c.name as category_name,
            s.name as supplier_name,
            s.contact_person,
            s.phone as supplier_phone,
            ec.category_name as expiry_category_name,
            ec.color_code as expiry_color,
            ec.alert_threshold_days
        FROM product_expiry_dates ped
        JOIN products p ON ped.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON ped.supplier_id = s.id
        LEFT JOIN expiry_categories ec ON p.expiry_category_id = ec.id AND ec.is_active = 1
        WHERE ped.id = ?
    ");
    $stmt->execute([$expiry_id]);
    $expiry_item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expiry_item) {
        header("Location: expiry_tracker.php?error=item_not_found");
        exit();
    }
} catch (PDOException $e) {
    // Log the actual error for debugging
    error_log("View Expiry Item DB Error: " . $e->getMessage());
    error_log("Error Code: " . $e->getCode());
    error_log("Expiry ID: " . $expiry_id);

    header("Location: expiry_tracker.php?error=db_error&debug=" . urlencode($e->getMessage()));
    exit();
}

// Get comprehensive activity history for this expiry item
$comprehensive_activities = [];

// 1. Get expiry actions
try {
    $stmt = $conn->prepare("
        SELECT
            'expiry_action' as activity_type,
            ea.id,
            ea.action_date as activity_date,
            ea.action_type,
            ea.quantity_affected,
            ea.cost,
            ea.revenue,
            ea.reason,
            ea.notes,
            ea.disposal_method,
            ea.return_reference,
            u.username as performed_by,
            u.role_name as performer_role,
            ea.created_at
        FROM expiry_actions ea
        LEFT JOIN users u ON ea.user_id = u.id
        WHERE ea.product_expiry_id = ?
        ORDER BY ea.action_date DESC
    ");
    $stmt->execute([$expiry_id]);
    $expiry_actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiry_actions as $action) {
        $comprehensive_activities[] = $action;
    }
} catch (PDOException $e) {
    error_log("Expiry Actions DB Error: " . $e->getMessage());
}

// 2. Get system activity logs related to this expiry item
try {
    $stmt = $conn->prepare("
        SELECT
            'system_activity' as activity_type,
            al.id,
            al.created_at as activity_date,
            al.action,
            al.details,
            u.username as performed_by,
            u.role_name as performer_role,
            al.created_at
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.details LIKE ?
        ORDER BY al.created_at DESC
    ");
    $stmt->execute(['%' . $expiry_item['product_name'] . '%']);
    $system_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($system_activities as $activity) {
        $comprehensive_activities[] = $activity;
    }
} catch (PDOException $e) {
    error_log("System Activities DB Error: " . $e->getMessage());
}

// 3. Get alert activities
try {
    $stmt = $conn->prepare("
        SELECT
            'alert_sent' as activity_type,
            ea.id,
            ea.alert_date as activity_date,
            ea.alert_type,
            ea.alert_message,
            ea.sent_status,
            ea.sent_at,
            u.username as performed_by,
            ea.created_at
        FROM expiry_alerts ea
        LEFT JOIN users u ON ea.recipient_user_id = u.id
        WHERE ea.product_expiry_id = ?
        ORDER BY ea.alert_date DESC
    ");
    $stmt->execute([$expiry_id]);
    $alert_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($alert_activities as $alert) {
        $comprehensive_activities[] = $alert;
    }
} catch (PDOException $e) {
    error_log("Alert Activities DB Error: " . $e->getMessage());
}

// 4. Add creation and update activities from the expiry item itself
if ($expiry_item['created_at']) {
    $comprehensive_activities[] = [
        'activity_type' => 'item_created',
        'activity_date' => $expiry_item['created_at'],
        'action' => 'Item Created',
        'details' => 'Expiry item was first created in the system',
        'performed_by' => 'System',
        'performer_role' => 'System'
    ];
}

if ($expiry_item['updated_at'] && $expiry_item['updated_at'] !== $expiry_item['created_at']) {
    $comprehensive_activities[] = [
        'activity_type' => 'item_updated',
        'activity_date' => $expiry_item['updated_at'],
        'action' => 'Item Updated',
        'details' => 'Expiry item details were last modified',
        'performed_by' => 'System',
        'performer_role' => 'System'
    ];
}

// 5. Add status change activities
$status_changes = [
    'active' => 'Item marked as active',
    'expired' => 'Item marked as expired',
    'disposed' => 'Item marked as disposed',
    'returned' => 'Item marked as returned'
];

if (isset($status_changes[$expiry_item['status']])) {
    $comprehensive_activities[] = [
        'activity_type' => 'status_change',
        'activity_date' => $expiry_item['updated_at'] ?: $expiry_item['created_at'],
        'action' => 'Status Changed',
        'details' => $status_changes[$expiry_item['status']],
        'performed_by' => 'System',
        'performer_role' => 'System'
    ];
}

// Sort all activities by date (most recent first)
usort($comprehensive_activities, function($a, $b) {
    return strtotime($b['activity_date']) - strtotime($a['activity_date']);
});

// Remove duplicates based on activity_date and type
$unique_activities = [];
$seen_activities = [];

foreach ($comprehensive_activities as $activity) {
    $key = $activity['activity_date'] . '_' . $activity['activity_type'];
    if (!isset($seen_activities[$key])) {
        $seen_activities[$key] = true;
        $unique_activities[] = $activity;
    }
}

$comprehensive_activities = $unique_activities;

// Get related expiry items (same product, different batches)
try {
    $stmt = $conn->prepare("
        SELECT
            ped.*,
            s.name as supplier_name
        FROM product_expiry_dates ped
        LEFT JOIN suppliers s ON ped.supplier_id = s.id
        WHERE ped.product_id = ? AND ped.id != ?
        ORDER BY ped.expiry_date ASC
        LIMIT 10
    ");
    $stmt->execute([$expiry_item['product_id'], $expiry_id]);
    $related_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Related Items DB Error: " . $e->getMessage());
    $related_items = [];
}

// Get alerts history for this item
try {
    $stmt = $conn->prepare("
        SELECT * FROM expiry_alerts
        WHERE product_expiry_id = ?
        ORDER BY alert_date DESC
        LIMIT 20
    ");
    $stmt->execute([$expiry_id]);
    $alerts_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Alerts History DB Error: " . $e->getMessage());
    $alerts_history = [];
}

// Calculate comprehensive financial summary
$financial_summary = [
    'total_cost' => 0,
    'total_revenue' => 0,
    'total_value_lost' => 0,
    'net_impact' => 0,
    'actions_count' => count($comprehensive_activities),
    'total_original_quantity' => $expiry_item['quantity'],
    'remaining_quantity' => $expiry_item['remaining_quantity'],
    'disposed_quantity' => $expiry_item['quantity'] - $expiry_item['remaining_quantity'],
    'cogs_per_unit' => $expiry_item['product_cost_price'] ?: $expiry_item['unit_cost'],
    'unit_cost_per_unit' => $expiry_item['unit_cost'],
    'actions_breakdown' => []
];

// Calculate per-action financial details
foreach ($comprehensive_activities as $activity) {
    // Only count expiry actions for financial summary
    if ($activity['activity_type'] === 'expiry_action') {
        $quantity_value = $activity['quantity_affected'] * $financial_summary['cogs_per_unit'];
        $unit_cost_value = $activity['quantity_affected'] * $financial_summary['unit_cost_per_unit'];

        $financial_summary['total_cost'] += $activity['cost'];
        $financial_summary['total_revenue'] += $activity['revenue'];
        $financial_summary['total_value_lost'] += $quantity_value;

        // Store detailed breakdown per action
        $financial_summary['actions_breakdown'][] = [
            'action_type' => $activity['action_type'],
            'quantity_affected' => $activity['quantity_affected'],
            'cost_per_unit_cogs' => $financial_summary['cogs_per_unit'],
            'cost_per_unit_original' => $financial_summary['unit_cost_per_unit'],
            'total_value_cogs' => $quantity_value,
            'total_value_original' => $unit_cost_value,
            'additional_costs' => $activity['cost'],
            'revenue' => $activity['revenue'],
            'net_impact' => $activity['revenue'] - ($activity['cost'] + $quantity_value),
            'action_date' => $activity['activity_date']
        ];
    }
}

// Calculate overall totals
$financial_summary['total_cogs_value'] = $financial_summary['total_original_quantity'] * $financial_summary['cogs_per_unit'];
$financial_summary['remaining_cogs_value'] = $financial_summary['remaining_quantity'] * $financial_summary['cogs_per_unit'];
$financial_summary['disposed_cogs_value'] = $financial_summary['disposed_quantity'] * $financial_summary['cogs_per_unit'];
$financial_summary['total_unit_cost_value'] = $financial_summary['total_original_quantity'] * $financial_summary['unit_cost_per_unit'];
$financial_summary['remaining_unit_cost_value'] = $financial_summary['remaining_quantity'] * $financial_summary['unit_cost_per_unit'];
$financial_summary['disposed_unit_cost_value'] = $financial_summary['disposed_quantity'] * $financial_summary['unit_cost_per_unit'];

$financial_summary['net_impact'] = $financial_summary['total_revenue'] - ($financial_summary['total_cost'] + $financial_summary['total_value_lost']);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Calculate days until expiry
$days_until_expiry = (strtotime($expiry_item['expiry_date']) - time()) / (60 * 60 * 24);
$is_expired = $days_until_expiry < 0;
$is_critical = $days_until_expiry <= 7 && !$is_expired;

// Determine status color and text
$status_config = [
    'active' => ['color' => '#10b981', 'text' => 'Active'],
    'expired' => ['color' => '#ef4444', 'text' => 'Expired'],
    'disposed' => ['color' => '#6b7280', 'text' => 'Disposed'],
    'returned' => ['color' => '#3b82f6', 'text' => 'Returned']
];

$page_title = "View Expiry Item - " . htmlspecialchars($expiry_item['product_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>

    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js for visualizations -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --primary-rgb: <?php echo implode(',', sscanf($settings['theme_color'] ?? '#6366f1', '#%02x%02x%02x')); ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
            --sidebar-rgb: <?php echo implode(',', sscanf($settings['sidebar_color'] ?? '#1e293b', '#%02x%02x%02x')); ?>;
            --gradient-primary: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            --gradient-success: linear-gradient(135deg, #10b981, #059669);
            --gradient-warning: linear-gradient(135deg, #f59e0b, #d97706);
            --gradient-danger: linear-gradient(135deg, #ef4444, #dc2626);
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }

        /* Hero Section */
        .hero-section {
            background: var(--gradient-primary);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.15)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .hero-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .hero-stat-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .hero-stat-card:hover {
            transform: translateY(-5px);
        }

        .hero-stat-value {
            font-size: 2rem;
            font-weight: 700;
            display: block;
            margin-bottom: 0.5rem;
        }

        .hero-stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* Main Content Cards */
        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 2rem;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .content-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 1px solid #e2e8f0;
            padding: 1.5rem;
        }

        .card-header-custom h5 {
            margin: 0;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body-custom {
            padding: 2rem;
        }

        /* Product Info Section */
        .product-main-info {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 2rem;
            align-items: start;
        }

        .product-image-container {
            position: relative;
        }

        .product-image {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .product-image-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 2rem;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .product-details h4 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .product-sku {
            color: #64748b;
            font-weight: 500;
            margin-bottom: 1rem;
            display: inline-block;
            background: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .product-description {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        /* Status Badge */
        .status-badge-large {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .status-badge-large.active {
            background: var(--gradient-success);
            color: white;
        }

        .status-badge-large.expired {
            background: var(--gradient-danger);
            color: white;
        }

        .status-badge-large.disposed {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .status-badge-large.returned {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .info-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .info-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-value i {
            color: var(--primary-color);
        }

        /* Expiry Timeline */
        .expiry-timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline-line {
            position: absolute;
            left: 1rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, var(--primary-color), #e2e8f0);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
            padding-left: 1rem;
        }

        .timeline-dot {
            position: absolute;
            left: -2rem;
            top: 0.5rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        .timeline-content {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .timeline-date {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .timeline-details {
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Financial Summary */
        .financial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .financial-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            text-align: center;
            transition: all 0.3s ease;
        }

        .financial-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }

        .financial-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }

        .financial-value.positive {
            color: #10b981;
        }

        .financial-value.negative {
            color: #ef4444;
        }

        .financial-value.neutral {
            color: #64748b;
        }

        .financial-label {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 2rem 0;
        }

        /* Action Buttons */
        .action-buttons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .action-btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3);
        }

        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.4);
        }

        .action-btn-secondary {
            background: white;
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .action-btn-secondary:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .action-btn-warning {
            background: var(--gradient-warning);
            color: white;
        }

        .action-btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        /* Related Items */
        .related-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .related-item-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .related-item-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .related-item-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .related-item-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            color: #64748b;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2rem;
            }

            .product-main-info {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .financial-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-buttons-grid {
                grid-template-columns: 1fr;
            }

            .related-items-grid {
                grid-template-columns: 1fr;
            }

            .hero-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .financial-grid {
                grid-template-columns: 1fr;
            }

            .card-body-custom {
                padding: 1.5rem;
            }
        }

        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Activity History and Overview Layout */
        .activity-layout {
            display: flex;
            gap: 1rem;
        }

        .activity-history-section {
            flex: 1;
        }

        .activity-overview-section {
            flex: 1;
        }

        /* Activity Statistics Cards */
        .activity-stat-card {
            transition: all 0.3s ease;
        }

        .activity-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Responsive Design for Activity Layout */
        @media (max-width: 992px) {
            .activity-layout {
                flex-direction: column;
                gap: 1rem;
            }

            .activity-history-section,
            .activity-overview-section {
                flex: none;
                width: 100%;
            }

            .activity-history-section .card-body-custom {
                max-height: 400px;
            }
        }

        @media (max-width: 576px) {
            .activity-layout {
                gap: 0.5rem;
            }

            .activity-history-section .card-body-custom {
                max-height: 300px;
            }

            .activity-stat-card {
                padding: 1rem !important;
            }

            /* Activity Summary responsive design */
            .activity-summary .col-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }

        @media (max-width: 480px) {
            .activity-summary .col-3 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #5855eb;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'expiry_tracker';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="container-fluid">
                <div class="hero-content">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h1 class="hero-title"><?php echo htmlspecialchars($expiry_item['product_name']); ?></h1>
                            <p class="hero-subtitle">Batch: <?php echo htmlspecialchars($expiry_item['batch_number'] ?: 'N/A'); ?> • SKU: <?php echo htmlspecialchars($expiry_item['sku']); ?></p>

                            <div class="mt-3">
                                <span class="status-badge-large <?php echo $expiry_item['status']; ?>">
                                    <i class="bi bi-circle-fill"></i>
                                    <?php echo $status_config[$expiry_item['status']]['text']; ?>
                                </span>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <a href="expiry_tracker.php" class="btn btn-outline-light">
                                <i class="bi bi-arrow-left me-2"></i>Back to Tracker
                            </a>
                            <?php if (hasPermission('handle_expired_items', $permissions) && $expiry_item['status'] === 'active'): ?>
                            <a href="handle_expiry.php?id=<?php echo $expiry_id; ?>" class="btn btn-warning">
                                <i class="bi bi-tools me-2"></i>Handle Expiry
                            </a>
                            <?php endif; ?>
                            <?php if (hasPermission('manage_expiry_tracker', $permissions)): ?>
                            <a href="edit_expiry_date.php?id=<?php echo $expiry_id; ?>" class="btn btn-light">
                                <i class="bi bi-pencil me-2"></i>Edit
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Hero Stats -->
                    <div class="hero-stats">
                        <div class="hero-stat-card">
                            <span class="hero-stat-value">
                                <?php
                                if ($is_expired) {
                                    echo abs(round($days_until_expiry));
                                } elseif ($days_until_expiry <= 30) {
                                    echo round($days_until_expiry);
                                } else {
                                    echo '30+';
                                }
                                ?>
                            </span>
                            <span class="hero-stat-label">
                                <?php
                                if ($is_expired) {
                                    echo 'Days Expired';
                                } else {
                                    echo 'Days Left';
                                }
                                ?>
                            </span>
                        </div>

                        <div class="hero-stat-card">
                            <span class="hero-stat-value"><?php echo number_format($expiry_item['remaining_quantity']); ?></span>
                            <span class="hero-stat-label">Remaining Quantity</span>
                        </div>

                        <div class="hero-stat-card">
                            <span class="hero-stat-value">KES <?php echo number_format($expiry_item['remaining_quantity'] * ($expiry_item['product_cost_price'] ?: $expiry_item['unit_cost']), 2); ?></span>
                            <span class="hero-stat-label">Current Value</span>
                        </div>

                        <div class="hero-stat-card">
                            <span class="hero-stat-value"><?php echo count($comprehensive_activities); ?></span>
                            <span class="hero-stat-label">Total Activities</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- Product Information -->
            <div class="content-card">
                <div class="card-header-custom">
                    <h5><i class="bi bi-info-circle"></i> Product Information</h5>
                </div>
                <div class="card-body-custom">
                    <div class="product-main-info">
                        <div class="product-image-container">
                            <?php if ($expiry_item['image_url']): ?>
                                <img src="<?php echo htmlspecialchars($expiry_item['image_url']); ?>"
                                     alt="<?php echo htmlspecialchars($expiry_item['product_name']); ?>"
                                     class="product-image">
                            <?php else: ?>
                                <div class="product-image-placeholder">
                                    <i class="bi bi-image"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="product-details">
                            <h4><?php echo htmlspecialchars($expiry_item['product_name']); ?></h4>
                            <span class="product-sku"><?php echo htmlspecialchars($expiry_item['sku']); ?></span>

                            <?php if ($expiry_item['description']): ?>
                                <p class="product-description"><?php echo htmlspecialchars($expiry_item['description']); ?></p>
                            <?php endif; ?>

                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Category</div>
                                    <div class="info-value">
                                        <i class="bi bi-tag"></i>
                                        <?php echo htmlspecialchars($expiry_item['category_name'] ?: 'Uncategorized'); ?>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Supplier</div>
                                    <div class="info-value">
                                        <i class="bi bi-truck"></i>
                                        <?php echo htmlspecialchars($expiry_item['supplier_name'] ?: 'N/A'); ?>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Expiry Date</div>
                                    <div class="info-value <?php echo $is_critical ? 'text-warning' : ($is_expired ? 'text-danger' : ''); ?>">
                                        <i class="bi bi-calendar-x"></i>
                                        <?php echo date('M d, Y', strtotime($expiry_item['expiry_date'])); ?>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">
                                        Cost of Goods (COGS)
                                        <i class="bi bi-info-circle text-muted ms-1"
                                           title="Cost of Goods Sold - The actual cost to purchase/acquire the product from suppliers"
                                           style="cursor: help; font-size: 0.8rem;"></i>
                                    </div>
                                    <div class="info-value">
                                        <i class="bi bi-cash"></i>
                                        <strong>KES <?php echo number_format($expiry_item['product_cost_price'] ?: $expiry_item['unit_cost'], 2); ?></strong>
                                        <small class="text-success d-block">Auto-fetched from product master data</small>
                                        <?php if ($expiry_item['product_cost_price'] && $expiry_item['unit_cost'] != $expiry_item['product_cost_price']): ?>
                                            <small class="text-muted d-block">Expiry Unit Cost: KES <?php echo number_format($expiry_item['unit_cost'], 2); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Location</div>
                                    <div class="info-value">
                                        <i class="bi bi-geo-alt"></i>
                                        <?php echo htmlspecialchars($expiry_item['location'] ?: 'Not specified'); ?>
                                    </div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Manufacturing Date</div>
                                    <div class="info-value">
                                        <i class="bi bi-calendar"></i>
                                        <?php echo $expiry_item['manufacturing_date'] ? date('M d, Y', strtotime($expiry_item['manufacturing_date'])) : 'N/A'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Summary & Charts -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="content-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-graph-up"></i> Financial Summary</h5>
                        </div>
                        <div class="card-body-custom">
                            <!-- Main Financial Overview -->
                            <div class="financial-grid">
                                <div class="financial-card">
                                    <div class="financial-value text-info">
                                        KES <?php echo number_format($financial_summary['total_cogs_value'], 2); ?>
                                    </div>
                                    <div class="financial-label">Total COGS Value</div>
                                    <small class="text-muted d-block mt-1">
                                        <?php echo number_format($financial_summary['total_original_quantity']); ?> units × KES <?php echo number_format($financial_summary['cogs_per_unit'], 2); ?>
                                    </small>
                                </div>

                                <div class="financial-card">
                                    <div class="financial-value text-primary">
                                        KES <?php echo number_format($financial_summary['remaining_cogs_value'], 2); ?>
                                    </div>
                                    <div class="financial-label">Remaining Value</div>
                                    <small class="text-muted d-block mt-1">
                                        <?php echo number_format($financial_summary['remaining_quantity']); ?> units remaining
                                    </small>
                                </div>

                                <div class="financial-card">
                                    <div class="financial-value text-danger">
                                        KES <?php echo number_format($financial_summary['disposed_cogs_value'], 2); ?>
                                    </div>
                                    <div class="financial-label">Disposed Value</div>
                                    <small class="text-muted d-block mt-1">
                                        <?php echo number_format($financial_summary['disposed_quantity']); ?> units disposed
                                    </small>
                                </div>

                                <div class="financial-card">
                                    <div class="financial-value text-warning">
                                        KES <?php echo number_format($financial_summary['total_cost'], 2); ?>
                                    </div>
                                    <div class="financial-label">Additional Costs</div>
                                    <small class="text-muted d-block mt-1">
                                        Disposal/handling costs
                                    </small>
                                </div>
                            </div>

                            <!-- Net Impact Summary -->
                            <div class="mt-4">
                                <div class="financial-card" style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="financial-value <?php echo $financial_summary['net_impact'] >= 0 ? 'positive' : 'negative'; ?> mb-0">
                                                KES <?php echo number_format($financial_summary['net_impact'], 2); ?>
                                            </div>
                                            <div class="financial-label">Net Financial Impact</div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block">Revenue: KES <?php echo number_format($financial_summary['total_revenue'], 2); ?></small>
                                            <small class="text-muted d-block">Total Costs: KES <?php echo number_format($financial_summary['total_cost'] + $financial_summary['total_value_lost'], 2); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action-wise Cost Breakdown -->
                            <?php if (!empty($financial_summary['actions_breakdown'])): ?>
                            <div class="mt-4">
                                <h6 class="mb-3"><i class="bi bi-calculator me-2"></i>Per-Action Cost Breakdown</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Action</th>
                                                <th>Quantity</th>
                                                <th>COGS Cost</th>
                                                <th>Additional Cost</th>
                                                <th>Revenue</th>
                                                <th>Net Impact</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($financial_summary['actions_breakdown'] as $action_breakdown): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo ucfirst(str_replace('_', ' ', $action_breakdown['action_type'])); ?>
                                                    </span>
                                                    <br><small class="text-muted"><?php echo date('M d, H:i', strtotime($action_breakdown['action_date'])); ?></small>
                                                </td>
                                                <td><?php echo number_format($action_breakdown['quantity_affected']); ?> units</td>
                                                <td>
                                                    KES <?php echo number_format($action_breakdown['total_value_cogs'], 2); ?>
                                                    <br><small class="text-muted">@ KES <?php echo number_format($action_breakdown['cost_per_unit_cogs'], 2); ?>/unit</small>
                                                </td>
                                                <td>KES <?php echo number_format($action_breakdown['additional_costs'], 2); ?></td>
                                                <td>KES <?php echo number_format($action_breakdown['revenue'], 2); ?></td>
                                                <td>
                                                    <span class="fw-bold <?php echo $action_breakdown['net_impact'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                        KES <?php echo number_format($action_breakdown['net_impact'], 2); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <td colspan="2">TOTALS</td>
                                                <td>KES <?php echo number_format($financial_summary['total_value_lost'], 2); ?></td>
                                                <td>KES <?php echo number_format($financial_summary['total_cost'], 2); ?></td>
                                                <td>KES <?php echo number_format($financial_summary['total_revenue'], 2); ?></td>
                                                <td class="<?php echo $financial_summary['net_impact'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    KES <?php echo number_format($financial_summary['net_impact'], 2); ?>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <!-- Cost Analysis Section -->
                    <div class="content-card mb-4">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-calculator"></i> Cost Analysis</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="row g-3">
                                <!-- Cost Comparison -->
                                <div class="col-12">
                                    <div class="p-3 border rounded">
                                        <h6 class="mb-2">Cost Comparison</h6>
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="border-end">
                                                    <div class="h4 text-primary mb-1">
                                                        KES <?php echo number_format($financial_summary['cogs_per_unit'], 2); ?>
                                                    </div>
                                                    <small class="text-muted">COGS per Unit</small>
                                                    <br><small class="text-success">Auto-fetched</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="h4 text-secondary mb-1">
                                                    KES <?php echo number_format($financial_summary['unit_cost_per_unit'], 2); ?>
                                                </div>
                                                <small class="text-muted">Expiry Unit Cost</small>
                                                <br><small class="text-warning">Manual Entry</small>
                                            </div>
                                        </div>
                                        <?php
                                        $cost_difference = $financial_summary['unit_cost_per_unit'] - $financial_summary['cogs_per_unit'];
                                        if ($cost_difference != 0):
                                        ?>
                                        <div class="mt-2 text-center">
                                            <small class="text-<?php echo $cost_difference > 0 ? 'warning' : 'info'; ?>">
                                                Difference: KES <?php echo number_format(abs($cost_difference), 2); ?>
                                                <?php echo $cost_difference > 0 ? 'higher' : 'lower'; ?> than COGS
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Quantity Breakdown -->
                                <div class="col-12">
                                    <div class="p-3 border rounded">
                                        <h6 class="mb-2">Quantity Breakdown</h6>
                                        <div class="row text-center">
                                            <div class="col-4">
                                                <div class="border-end">
                                                    <div class="h5 text-info mb-1"><?php echo number_format($financial_summary['total_original_quantity']); ?></div>
                                                    <small class="text-muted">Total</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="border-end">
                                                    <div class="h5 text-primary mb-1"><?php echo number_format($financial_summary['remaining_quantity']); ?></div>
                                                    <small class="text-muted">Remaining</small>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="h5 text-danger mb-1"><?php echo number_format($financial_summary['disposed_quantity']); ?></div>
                                                    <small class="text-muted">Disposed</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Financial Efficiency -->
                                <div class="col-12">
                                    <div class="p-3 border rounded">
                                        <h6 class="mb-2">Financial Efficiency</h6>
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted d-block">COGS Recovery Rate</small>
                                                <div class="progress mb-2" style="height: 8px;">
                                                    <?php
                                                    $recovery_rate = $financial_summary['total_original_quantity'] > 0
                                                        ? (($financial_summary['total_original_quantity'] - $financial_summary['disposed_quantity']) / $financial_summary['total_original_quantity']) * 100
                                                        : 0;
                                                    ?>
                                                    <div class="progress-bar bg-success" style="width: <?php echo $recovery_rate; ?>%"></div>
                                                </div>
                                                <small class="text-success fw-bold"><?php echo number_format($recovery_rate, 1); ?>%</small>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Profitability</small>
                                                <div class="h6 mb-0 <?php echo $financial_summary['net_impact'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $financial_summary['net_impact'] >= 0 ? '✓ Profitable' : '⚠ Loss'; ?>
                                                </div>
                                                <small class="text-muted">Net Impact: KES <?php echo number_format($financial_summary['net_impact'], 2); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Activity History and Overview Side by Side -->
            <div class="activity-layout">
                <!-- Activity History - 60% width -->
                <div class="activity-history-section" style="flex: 1.2;">
                    <div class="content-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-clock-history"></i> Complete Activity History</h5>
                        </div>
                        <div class="card-body-custom" style="max-height: 600px; overflow-y: auto;">
                            <?php if (empty($comprehensive_activities)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                    <h5>No activity history found</h5>
                                    <p>This expiry item hasn't had any activities recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="expiry-timeline">
                                    <div class="timeline-line"></div>
                                    <?php
                                    $activity_icons = [
                                        'expiry_action' => 'bi-tools',
                                        'system_activity' => 'bi-gear',
                                        'alert_sent' => 'bi-bell',
                                        'item_created' => 'bi-plus-circle',
                                        'item_updated' => 'bi-pencil',
                                        'status_change' => 'bi-arrow-repeat'
                                    ];

                                    $activity_colors = [
                                        'expiry_action' => '#f59e0b',
                                        'system_activity' => '#6b7280',
                                        'alert_sent' => '#3b82f6',
                                        'item_created' => '#10b981',
                                        'item_updated' => '#6366f1',
                                        'status_change' => '#8b5cf6'
                                    ];

                                    foreach ($comprehensive_activities as $activity):
                                        $icon = $activity_icons[$activity['activity_type']] ?? 'bi-circle';
                                        $color = $activity_colors[$activity['activity_type']] ?? '#6b7280';
                                    ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background: <?php echo $color; ?>; border-color: white;"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-date">
                                                    <i class="bi <?php echo $icon; ?>" style="color: <?php echo $color; ?>; margin-right: 5px;"></i>
                                                    <?php echo date('M d, Y H:i', strtotime($activity['activity_date'])); ?>
                                                </div>

                                                <div class="timeline-title">
                                                    <?php
                                                    switch ($activity['activity_type']) {
                                                        case 'expiry_action':
                                                            echo ucfirst(str_replace('_', ' ', $activity['action_type'])) . ' Action';
                                                            break;
                                                        case 'system_activity':
                                                            echo htmlspecialchars($activity['action']);
                                                            break;
                                                        case 'alert_sent':
                                                            echo 'Alert ' . ucfirst($activity['sent_status']);
                                                            break;
                                                        case 'item_created':
                                                            echo 'Item Created';
                                                            break;
                                                        case 'item_updated':
                                                            echo 'Item Updated';
                                                            break;
                                                        case 'status_change':
                                                            echo 'Status Changed';
                                                            break;
                                                        default:
                                                            echo htmlspecialchars($activity['action'] ?? $activity['activity_type']);
                                                    }
                                                    ?>
                                                </div>

                                                <div class="timeline-details">
                                                    <?php if ($activity['activity_type'] === 'expiry_action'): ?>
                                                        <strong>Action:</strong> <?php echo ucfirst(str_replace('_', ' ', $activity['action_type'])); ?><br>
                                                        <strong>Quantity:</strong> <?php echo number_format($activity['quantity_affected']); ?> units<br>
                                                        <?php if ($activity['cost'] > 0): ?>
                                                            <strong>Cost:</strong> KES <?php echo number_format($activity['cost'], 2); ?>
                                                        <small class="text-muted">(additional costs)</small><br>
                                                        <?php endif; ?>
                                                        <?php if ($activity['revenue'] > 0): ?>
                                                            <strong>Revenue:</strong> <?php echo number_format($activity['revenue'], 2); ?><br>
                                                        <?php endif; ?>
                                                        <strong>Value Impact:</strong> KES <?php echo number_format($activity['quantity_affected'] * ($expiry_item['product_cost_price'] ?: $expiry_item['unit_cost']), 2); ?>
                                                        <small class="text-muted">(at COGS)</small><br>
                                                        <?php
                                                        $cogs_per_unit = $expiry_item['product_cost_price'] ?: $expiry_item['unit_cost'];
                                                        $unit_cost_per_unit = $expiry_item['unit_cost'];
                                                        $quantity = $activity['quantity_affected'];
                                                        $cogs_total = $quantity * $cogs_per_unit;
                                                        $unit_cost_total = $quantity * $unit_cost_per_unit;
                                                        ?>
                                                        <small class="text-muted">
                                                            Breakdown: <?php echo number_format($quantity); ?> × KES <?php echo number_format($cogs_per_unit, 2); ?> = KES <?php echo number_format($cogs_total, 2); ?>
                                                            <?php if ($cogs_per_unit != $unit_cost_per_unit): ?>
                                                                (Original: <?php echo number_format($quantity); ?> × KES <?php echo number_format($unit_cost_per_unit, 2); ?> = KES <?php echo number_format($unit_cost_total, 2); ?>)
                                                            <?php endif; ?>
                                                        </small><br>
                                                        <?php if ($activity['disposal_method']): ?>
                                                            <strong>Disposal Method:</strong> <?php echo ucfirst($activity['disposal_method']); ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($activity['return_reference']): ?>
                                                            <strong>Reference:</strong> <?php echo htmlspecialchars($activity['return_reference']); ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($activity['reason']): ?>
                                                            <strong>Reason:</strong> <?php echo htmlspecialchars($activity['reason']); ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($activity['notes']): ?>
                                                            <strong>Notes:</strong> <?php echo htmlspecialchars($activity['notes']); ?><br>
                                                        <?php endif; ?>

                                                    <?php elseif ($activity['activity_type'] === 'alert_sent'): ?>
                                                        <strong>Alert Type:</strong> <?php echo ucfirst($activity['alert_type']); ?><br>
                                                        <strong>Status:</strong>
                                                        <span class="badge bg-<?php echo $activity['sent_status'] === 'sent' ? 'success' : 'warning'; ?>">
                                                            <?php echo ucfirst($activity['sent_status']); ?>
                                                        </span><br>
                                                        <?php if ($activity['alert_message']): ?>
                                                            <strong>Message:</strong> <?php echo htmlspecialchars(substr($activity['alert_message'], 0, 100)); ?><?php echo strlen($activity['alert_message']) > 100 ? '...' : ''; ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($activity['sent_at']): ?>
                                                            <strong>Sent At:</strong> <?php echo date('M d, Y H:i', strtotime($activity['sent_at'])); ?><br>
                                                        <?php endif; ?>

                                                    <?php elseif ($activity['activity_type'] === 'system_activity'): ?>
                                                        <strong>Action:</strong> <?php echo htmlspecialchars($activity['action']); ?><br>
                                                        <?php if ($activity['details']): ?>
                                                            <strong>Details:</strong> <?php echo htmlspecialchars($activity['details']); ?><br>
                                                        <?php endif; ?>

                                                    <?php elseif (in_array($activity['activity_type'], ['item_created', 'item_updated', 'status_change'])): ?>
                                                        <strong>Event:</strong> <?php echo htmlspecialchars($activity['details']); ?><br>
                                                        <?php if ($activity['activity_type'] === 'status_change'): ?>
                                                            <strong>Current Status:</strong>
                                                            <span class="badge bg-<?php
                                                                echo match($expiry_item['status']) {
                                                                    'active' => 'success',
                                                                    'expired' => 'danger',
                                                                    'disposed' => 'secondary',
                                                                    'returned' => 'info',
                                                                    default => 'light'
                                                                };
                                                            ?>">
                                                                <?php echo ucfirst($expiry_item['status']); ?>
                                                            </span><br>
                                                        <?php endif; ?>

                                                    <?php endif; ?>

                                                    <strong>Performed by:</strong> <?php echo htmlspecialchars($activity['performed_by'] ?? 'System'); ?>
                                                    <?php if (!empty($activity['performer_role']) && $activity['performer_role'] !== 'System'): ?>
                                                        (<?php echo htmlspecialchars($activity['performer_role']); ?>)
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Activity Summary -->
                                <div class="mt-4 p-3 bg-light rounded">
                                    <h6 class="mb-2"><i class="bi bi-bar-chart me-2"></i>Activity Summary</h6>
                                    <div class="row g-3">
                                        <?php
                                        $activity_counts = array_count_values(array_column($comprehensive_activities, 'activity_type'));
                                        $activity_labels = [
                                            'expiry_action' => 'Expiry Actions',
                                            'system_activity' => 'System Activities',
                                            'alert_sent' => 'Alerts Sent',
                                            'item_created' => 'Item Created',
                                            'item_updated' => 'Item Updated',
                                            'status_change' => 'Status Changes'
                                        ];
                                        ?>
                                        <?php foreach ($activity_counts as $type => $count): ?>
                                            <div class="col-auto">
                                                <small class="text-muted d-block"><?php echo $activity_labels[$type] ?? ucfirst(str_replace('_', ' ', $type)); ?></small>
                                                <span class="badge bg-primary"><?php echo $count; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Activity Overview - 40% width -->
                <div class="activity-overview-section" style="flex: 0.8;">
                    <div class="content-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-bar-chart"></i> Activity Overview</h5>
                        </div>
                        <div class="card-body-custom">
                            <?php if (!empty($comprehensive_activities)): ?>
                                <!-- Activity Chart -->
                                <div class="chart-container">
                                    <canvas id="activityChart"></canvas>
                                </div>


                                <!-- Activity Summary Stats -->
                                <div class="mt-4 activity-summary">
                                    <h6 class="mb-3"><i class="bi bi-graph-up me-2"></i>Activity Summary</h6>
                                    <?php
                                    // Calculate activity counts and totals
                                    $activity_counts = array_count_values(array_column($comprehensive_activities, 'activity_type'));
                                    $total_activities = count($comprehensive_activities);
                                    ?>
                                    <div class="row g-3">
                                        <div class="col-3">
                                            <div class="p-3 bg-light rounded text-center">
                                                <div class="h4 text-primary mb-1"><?php echo $total_activities; ?></div>
                                                <small class="text-muted">Total Activities</small>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="p-3 bg-light rounded text-center">
                                                <div class="h4 text-success mb-1">
                                                    <?php echo isset($activity_counts['expiry_action']) ? $activity_counts['expiry_action'] : 0; ?>
                                                </div>
                                                <small class="text-muted">Expiry Actions</small>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="p-3 bg-light rounded text-center">
                                                <div class="h4 text-info mb-1">
                                                    <?php echo isset($activity_counts['alert_sent']) ? $activity_counts['alert_sent'] : 0; ?>
                                                </div>
                                                <small class="text-muted">Alerts Sent</small>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="p-3 bg-light rounded text-center">
                                                <div class="h4 text-warning mb-1">
                                                    <?php echo isset($activity_counts['status_change']) ? $activity_counts['status_change'] : 0; ?>
                                                </div>
                                                <small class="text-muted">Status Changes</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="bi bi-bar-chart display-4 d-block mb-3"></i>
                                    <h5>No Activity Data</h5>
                                    <p>Activity overview will appear here once activities are recorded.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Related Items & Quick Actions -->
            <div class="row">
                <div class="col-lg-8">
                    <?php if (!empty($related_items)): ?>
                    <div class="content-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-diagram-3"></i> Related Expiry Items</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="related-items-grid">
                                <?php foreach ($related_items as $related): ?>
                                    <div class="related-item-card" onclick="window.location.href='view_expiry_item.php?id=<?php echo $related['id']; ?>'">
                                        <div class="related-item-title">
                                            Batch: <?php echo htmlspecialchars($related['batch_number'] ?: 'N/A'); ?>
                                        </div>
                                        <div class="related-item-meta">
                                            <span>Expires: <?php echo date('M d, Y', strtotime($related['expiry_date'])); ?></span>
                                            <span class="badge bg-<?php
                                                $days_left = (strtotime($related['expiry_date']) - time()) / (60 * 60 * 24);
                                                echo $days_left < 0 ? 'danger' : ($days_left <= 7 ? 'warning' : 'success');
                                            ?>">
                                                <?php echo $days_left < 0 ? 'Expired' : round($days_left) . ' days'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-4">
                    <div class="content-card">
                        <div class="card-header-custom">
                            <h5><i class="bi bi-gear"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body-custom">
                            <div class="action-buttons-grid">
                                <?php if (hasPermission('handle_expired_items', $permissions) && $expiry_item['status'] === 'active'): ?>
                                <a href="handle_expiry.php?id=<?php echo $expiry_id; ?>" class="action-btn action-btn-warning">
                                    <i class="bi bi-tools"></i>
                                    Handle Expiry
                                </a>
                                <?php endif; ?>

                                <?php if (hasPermission('manage_expiry_tracker', $permissions)): ?>
                                <a href="edit_expiry_date.php?id=<?php echo $expiry_id; ?>" class="action-btn action-btn-secondary">
                                    <i class="bi bi-pencil"></i>
                                    Edit Details
                                </a>
                                <?php endif; ?>

                                <button onclick="window.print()" class="action-btn action-btn-secondary">
                                    <i class="bi bi-printer"></i>
                                    Print Report
                                </button>

                                <button onclick="shareItem()" class="action-btn action-btn-secondary">
                                    <i class="bi bi-share"></i>
                                    Share
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Chart.js Script -->
    <script>
        // Activity Chart
        <?php
        $action_types = array_count_values(array_column($comprehensive_activities, 'activity_type'));
        $chart_labels = json_encode(array_map(function($type) {
            $labels = [
                'expiry_action' => 'Expiry Actions',
                'system_activity' => 'System Activities',
                'alert_sent' => 'Alerts Sent',
                'item_created' => 'Item Created',
                'item_updated' => 'Item Updated',
                'status_change' => 'Status Changes'
            ];
            return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
        }, array_keys($action_types)));
        $chart_data = json_encode(array_values($action_types));
        ?>

        const ctx = document.getElementById('activityChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $chart_labels; ?>,
                datasets: [{
                    data: <?php echo $chart_data; ?>,
                    backgroundColor: [
                        '#f59e0b',  // expiry_action
                        '#6b7280',  // system_activity
                        '#3b82f6',  // alert_sent
                        '#10b981',  // item_created
                        '#6366f1',  // item_updated
                        '#8b5cf6'   // status_change
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Share functionality
        function shareItem() {
            if (navigator.share) {
                navigator.share({
                    title: '<?php echo htmlspecialchars($expiry_item['product_name']); ?>',
                    text: 'Check out this expiry item: <?php echo htmlspecialchars($expiry_item['product_name']); ?>',
                    url: window.location.href
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(window.location.href).then(() => {
                    alert('Link copied to clipboard!');
                });
            }
        }

        // Add loading animation for actions
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.href && !this.href.includes('#')) {
                    this.innerHTML = '<div class="loading-spinner"></div> Loading...';
                }
            });
        });

        // Auto-refresh data every 30 seconds
        setInterval(() => {
            // You could add auto-refresh logic here if needed
            console.log('Auto-refresh check...');
        }, 30000);
    </script>
</body>
</html>
