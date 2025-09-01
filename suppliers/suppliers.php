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
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Debug: Check session variables
if (!isset($_SESSION['user_id'])) {
    echo "<!-- Debug: User not logged in -->";
    // Redirect to login if not logged in
    header("Location: ../auth/login.php");
    exit();
}

// Get user permissions
$permissions = [];
if ($role_id) {
    try {
        $stmt = $conn->prepare("
            SELECT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
        ");
        $stmt->bindParam(':role_id', $role_id);
        $stmt->execute();
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // If permissions tables don't exist, use default permissions
        $permissions = ['manage_products', 'process_sales', 'manage_sales'];
    }
}

// Check if user has permission to manage products (includes suppliers)
if (!hasPermission('manage_products', $permissions)) {
    echo "<!-- Debug: User doesn't have manage_products permission -->";
    // For debugging, allow access anyway
    // header("Location: ../dashboard/dashboard.php");
    // exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // If settings table doesn't exist or query fails, use defaults
    $settings = [
        'company_name' => 'POS System',
        'theme_color' => '#6366f1',
        'sidebar_color' => '#1e293b'
    ];
}

// Handle search and filters
$search = sanitizeProductInput($_GET['search'] ?? '');
$status_filter = sanitizeProductInput($_GET['status'] ?? 'all');
$sort_by = sanitizeProductInput($_GET['sort'] ?? 'name');
$sort_order = sanitizeProductInput($_GET['order'] ?? 'ASC');

// No advanced filters needed for simplified view

// Build simplified query - just basic supplier info and product count
$query = "
    SELECT s.*,
           COUNT(p.id) as product_count,
           COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_product_count
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (s.name LIKE :search OR s.contact_person LIKE :search OR s.email LIKE :search OR s.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status_filter !== 'all') {
    $query .= " AND s.is_active = :is_active";
    $params[':is_active'] = ($status_filter === 'active') ? 1 : 0;
}

$query .= " GROUP BY s.id";

// Add simplified sorting
$valid_sort_columns = ['name', 'contact_person', 'email', 'created_at', 'product_count'];
if (in_array($sort_by, $valid_sort_columns)) {
    if ($sort_by === 'name') {
        $query .= " ORDER BY s.name " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'contact_person') {
        $query .= " ORDER BY s.contact_person " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'email') {
        $query .= " ORDER BY s.email " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'created_at') {
        $query .= " ORDER BY s.created_at " . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
    } elseif ($sort_by === 'product_count') {
        $query .= " ORDER BY product_count " . ($sort_order === 'DESC' ? 'DESC' : 'ASC') . ", s.name ASC";
    }
} else {
    $query .= " ORDER BY s.name ASC";
}

// Get total count for pagination
$count_query = str_replace("SELECT s.*", "SELECT COUNT(DISTINCT s.id) as total", $query);
$count_stmt = $conn->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_suppliers = $result ? $result['total'] : 0;

// Pagination
$per_page = 20;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $per_page;
$total_pages = ceil($total_suppliers / $per_page);

// Add pagination to main query
$query .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $per_page;
$params[':offset'] = $offset;

// Execute main query
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// No performance calculations needed for simplified view

// Handle individual toggle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_supplier'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $deactivation_type = $_POST['deactivation_type'] ?? 'simple';
    $supplier_block_note = trim($_POST['supplier_block_note'] ?? '');

    // Get current status
    $stmt = $conn->prepare("SELECT is_active FROM suppliers WHERE id = :id");
    $stmt->bindParam(':id', $supplier_id);
    $stmt->execute();
    $current_status = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_status) {
        if ($current_status['is_active']) {
            // Deactivating supplier - validate block note is required
            if (empty($supplier_block_note)) {
                $_SESSION['error'] = 'Block reason is required when deactivating a supplier.';
                header("Location: suppliers.php");
                exit();
            }

            $new_status = 0;

            // Handle different deactivation types
            if ($deactivation_type === 'deactivate_products') {
                // Deactivate supplier and all associated products
                $conn->beginTransaction();
                try {
                    // Update supplier status and block note
                    $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                    $update_stmt->bindParam(':status', $new_status);
                    $update_stmt->bindParam(':block_note', $supplier_block_note);
                    $update_stmt->bindParam(':id', $supplier_id);
                    $update_stmt->execute();

                    // Deactivate all products from this supplier
                    $product_stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE supplier_id = :supplier_id");
                    $product_stmt->bindParam(':supplier_id', $supplier_id);
                    $product_stmt->execute();

                    $conn->commit();
                    $_SESSION['success'] = 'Supplier and all associated products have been deactivated.';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $_SESSION['error'] = 'Failed to deactivate supplier and products.';
                }
            } elseif ($deactivation_type === 'allow_selling') {
                // Deactivate supplier but keep products active for selling
                $conn->beginTransaction();
                try {
                    // Update supplier status and block note
                    $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                    $update_stmt->bindParam(':status', $new_status);
                    $update_stmt->bindParam(':block_note', $supplier_block_note);
                    $update_stmt->bindParam(':id', $supplier_id);
                    $update_stmt->execute();

                    $conn->commit();
                    $_SESSION['success'] = 'Supplier deactivated but products remain active for selling.';
                } catch (Exception $e) {
                    $conn->rollBack();
                    $_SESSION['error'] = 'Failed to deactivate supplier.';
                }
            } elseif ($deactivation_type === 'supplier_notice') {
                // Issue supplier notice - keep supplier active but add block note
                $update_stmt = $conn->prepare("UPDATE suppliers SET supplier_block_note = :block_note WHERE id = :id");
                $update_stmt->bindParam(':block_note', $supplier_block_note);
                $update_stmt->bindParam(':id', $supplier_id);

                if ($update_stmt->execute()) {
                    $_SESSION['success'] = 'Supplier notice issued successfully.';
                } else {
                    $_SESSION['error'] = 'Failed to issue supplier notice.';
                }
            } else {
                // Simple deactivation - only supplier status changes
                $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = :block_note WHERE id = :id");
                $update_stmt->bindParam(':status', $new_status);
                $update_stmt->bindParam(':block_note', $supplier_block_note);
                $update_stmt->bindParam(':id', $supplier_id);

                if ($update_stmt->execute()) {
                    $_SESSION['success'] = 'Supplier deactivated successfully.';
                } else {
                    $_SESSION['error'] = 'Failed to deactivate supplier.';
                }
            }
        } else {
            // Activating supplier - clear block note
            $new_status = 1;
            $update_stmt = $conn->prepare("UPDATE suppliers SET is_active = :status, supplier_block_note = NULL WHERE id = :id");
            $update_stmt->bindParam(':status', $new_status);
            $update_stmt->bindParam(':id', $supplier_id);

            if ($update_stmt->execute()) {
                $_SESSION['success'] = 'Supplier activated successfully.';
            } else {
                $_SESSION['error'] = 'Failed to activate supplier.';
            }
        }
    }

    header("Location: suppliers.php");
    exit();
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = sanitizeProductInput($_POST['bulk_action']);
    $supplier_ids = $_POST['supplier_ids'] ?? [];

    if (!empty($supplier_ids) && is_array($supplier_ids)) {
        $placeholders = str_repeat('?,', count($supplier_ids) - 1) . '?';

                 if ($action === 'activate') {
             // Check if confirmation checkbox is checked
             if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                 $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                 header("Location: suppliers.php");
                 exit();
             }
             
             $stmt = $conn->prepare("UPDATE suppliers SET is_active = 1, supplier_block_note = NULL WHERE id IN ($placeholders)");
             $stmt->execute($supplier_ids);
             $_SESSION['success'] = 'Selected suppliers have been activated.';
                 } elseif ($action === 'deactivate') {
             // Check if confirmation checkbox is checked
             if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                 $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                 header("Location: suppliers.php");
                 exit();
             }
             
                           $supplier_block_note = trim($_POST['supplier_block_note'] ?? '');
 
              // Validate block note is required for deactivation
              if (empty($supplier_block_note)) {
                  $_SESSION['error'] = 'Block reason is required when deactivating suppliers.';
                  header("Location: suppliers.php");
                  exit();
              }
              
              // Simple bulk deactivation - only supplier status changes
              $stmt = $conn->prepare("UPDATE suppliers SET is_active = 0, supplier_block_note = ? WHERE id IN ($placeholders)");
              $params = array_merge([$supplier_block_note], $supplier_ids);
              $stmt->execute($params);
              $_SESSION['success'] = 'Selected suppliers have been deactivated.';
        } elseif ($action === 'delete') {
            // Check if confirmation checkbox is checked
            if (!isset($_POST['bulk_confirm_action']) || $_POST['bulk_confirm_action'] !== 'on') {
                $_SESSION['error'] = 'Please confirm that you want to proceed with this action.';
                header("Location: suppliers.php");
                exit();
            }
            
            // Check if suppliers are being used
            $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE supplier_id IN ($placeholders)");
            $check_stmt->execute($supplier_ids);
            $usage_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($usage_count > 0) {
                $_SESSION['error'] = 'Cannot delete suppliers that are being used by products. Please deactivate them instead.';
            } else {
                $stmt = $conn->prepare("DELETE FROM suppliers WHERE id IN ($placeholders)");
                $stmt->execute($supplier_ids);
                $_SESSION['success'] = 'Selected suppliers have been deleted.';
            }
        }

        header("Location: suppliers.php");
        exit();
    }
}



// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
        }

        /* Performance metrics styling */
        .performance-score {
            font-size: 1.1em;
            font-weight: 600;
        }

        .performance-badge {
            font-size: 0.75em;
            padding: 0.25em 0.5em;
            border-radius: 0.25rem;
        }

        .delivery-metric {
            font-size: 0.9em;
        }

        .quality-metric {
            font-size: 0.9em;
        }

        .order-metric {
            font-size: 0.9em;
        }

        .metric-label {
            font-size: 0.75em;
            color: #6c757d;
            display: block;
        }

        /* Performance color coding */
        .text-excellent { color: #28a745 !important; }
        .text-good { color: #007bff !important; }
        .text-fair { color: #ffc107 !important; }
        .text-poor { color: #fd7e14 !important; }
        .text-critical { color: #dc3545 !important; }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .performance-score {
                font-size: 1em;
            }

            .table th, .table td {
                padding: 0.5rem 0.25rem;
            }
        }

        @media (max-width: 768px) {
            .table th:nth-child(7),
            .table th:nth-child(8),
            .table th:nth-child(9),
            .table th:nth-child(10),
            .table td:nth-child(7),
            .table td:nth-child(8),
            .table td:nth-child(9),
            .table td:nth-child(10) {
                display: none;
            }
        }

        /* Performance tooltip */
        .performance-tooltip {
            position: relative;
            cursor: help;
        }

        .performance-tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 0.5rem;
        }

        .performance-tooltip:hover::before {
            content: '';
            position: absolute;
            bottom: calc(100% - 0.25rem);
            left: 50%;
            transform: translateX(-50%);
            border: 0.25rem solid transparent;
            border-top-color: #333;
            z-index: 1000;
        }

        /* Floating horizontal scroll bar */
        .table-scroll-container {
            position: relative;
            overflow-x: auto;
            margin-bottom: 0;
        }

        .floating-scrollbar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            border-radius: 25px;
            padding: 8px 16px;
            z-index: 1000;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .floating-scrollbar:hover {
            background: rgba(0, 0, 0, 0.9);
            transform: translateX(-50%) scale(1.05);
        }

        .scrollbar-track {
            width: 200px;
            height: 6px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            position: relative;
            cursor: pointer;
        }

        .scrollbar-thumb {
            height: 100%;
            background: var(--primary-color, #6366f1);
            border-radius: 3px;
            position: absolute;
            top: 0;
            left: 0;
            min-width: 20px;
            transition: background 0.2s ease;
        }

        .scrollbar-thumb:hover {
            background: #4f46e5;
        }

        .scrollbar-info {
            color: white;
            font-size: 0.75rem;
            text-align: center;
            margin-top: 4px;
            opacity: 0.8;
        }

        /* Hide scrollbar on mobile */
        @media (max-width: 768px) {
            .floating-scrollbar {
                display: none;
            }
        }

        /* Bulk Actions Enhanced UI */
        .bulk-actions-container {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .bulk-actions-panel {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
            min-width: 600px;
            max-width: 800px;
            flex: 1;
        }

        .bulk-actions-panel:hover {
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .bulk-actions-header {
            background: linear-gradient(135deg, var(--primary-color, #6366f1) 0%, #4f46e5 100%);
            color: white;
            padding: 0.75rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .bulk-actions-header i {
            font-size: 1.1rem;
            margin-right: 0.5rem;
        }

        .bulk-actions-title {
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
        }

        .bulk-actions-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: all 0.2s ease;
            opacity: 0.8;
        }

        .bulk-actions-close:hover {
            background: rgba(255, 255, 255, 0.2);
            opacity: 1;
            transform: scale(1.1);
        }

        .bulk-actions-content {
            padding: 1.5rem;
        }

        .bulk-action-row {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .bulk-action-group {
            display: flex;
            flex-direction: column;
        }

        .bulk-action-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #495057;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .bulk-action-group .form-select,
        .bulk-action-group .form-control {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background-color: #fff;
        }

        .bulk-action-group .form-select:focus,
        .bulk-action-group .form-control:focus {
            border-color: var(--primary-color, #6366f1);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .bulk-action-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }

        .bulk-confirmation {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .bulk-confirmation .form-check-input {
            margin: 0;
            transform: scale(1.1);
        }

        .bulk-confirmation .form-check-label {
            font-size: 0.9rem;
            color: #495057;
            cursor: pointer;
        }

        .bulk-confirmation #selectedCount {
            font-weight: 600;
            color: var(--primary-color, #6366f1);
        }

        .bulk-action-footer .btn {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
            text-transform: none;
        }

        .bulk-action-footer .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .suppliers-count {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            color: #6c757d;
            white-space: nowrap;
        }

        .suppliers-count i {
            color: var(--primary-color, #6366f1);
        }

        /* Responsive adjustments for bulk actions */
        @media (max-width: 992px) {
            .bulk-actions-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-actions-panel {
                min-width: auto;
                max-width: none;
            }
            
            .bulk-action-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .bulk-action-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
        }

        @media (max-width: 576px) {
            .bulk-actions-content {
                padding: 1rem;
            }
            
            .bulk-actions-header {
                padding: 0.5rem 1rem;
            }
            
            .bulk-action-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Animation for bulk actions panel */
        @keyframes slideInFromTop {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bulk-actions-panel[style*="display: block"] {
            animation: slideInFromTop 0.3s ease-out;
        }

        /* Enhanced reason group styling */
        #reasonGroup {
            transition: all 0.3s ease;
        }

        #reasonGroup.show {
            opacity: 1;
            transform: translateY(0);
        }

        #reasonGroup:not(.show) {
            opacity: 0;
            transform: translateY(-10px);
        }

        /* Action-specific styling */
        .bulk-action-group .form-select[value="delete"] {
            border-color: #dc3545;
        }

        .bulk-action-group .form-select[value="deactivate"] {
            border-color: #fd7e14;
        }

        .bulk-action-group .form-select[value="activate"] {
            border-color: #28a745;
        }

        /* Modern Stats Cards */
        .stats-overview {
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 0;
        }

        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 0;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color, #6366f1) 0%, var(--card-color-light, #8b5cf6) 100%);
        }

        .card-success {
            --card-color: #10b981;
            --card-color-light: #34d399;
        }

        .card-info {
            --card-color: #3b82f6;
            --card-color-light: #60a5fa;
        }

        .card-warning {
            --card-color: #f59e0b;
            --card-color-light: #fbbf24;
        }

        .card-primary {
            --card-color: #6366f1;
            --card-color-light: #8b5cf6;
        }

        .stat-content {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            gap: 1rem;
        }

        .stat-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--card-color, #6366f1), var(--card-color-light, #8b5cf6));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(var(--card-color-rgb, 99, 102, 241), 0.3);
        }

        .card-success .stat-icon {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .card-info .stat-icon {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .card-warning .stat-icon {
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .stat-info {
            flex: 1;
            min-width: 0;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .value-unit {
            font-size: 1.25rem;
            font-weight: 500;
            color: #6b7280;
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .trend-indicator.success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .trend-indicator.warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .trend-indicator.neutral {
            background-color: #e5e7eb;
            color: #374151;
        }

        .trend-text {
            font-size: 0.75rem;
            color: #9ca3af;
            font-weight: 500;
        }

        /* Responsive adjustments for stats */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1rem;
            }
            
            .stat-content {
                padding: 1.25rem;
            }
            
            .stat-icon {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stat-content {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.25rem;
            }
            
            .stat-value {
                font-size: 1.75rem;
            }
        }

        /* Animation for stats cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Supplier Management</h1>
                    <div class="header-subtitle">Manage your product suppliers and vendors</div>
                </div>
                <div class="header-actions">
                    <a href="bulk_operations.php" class="btn btn-outline-primary me-2">
                        <i class="bi bi-arrow-down-up"></i>
                        Bulk Operations
                    </a>
                    <a href="add.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i>
                        Add Supplier
                    </a>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Overview -->
            <div class="stats-overview">
                <div class="stats-grid">
                    <div class="stat-card card-success">
                        <div class="stat-content">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-value"><?php 
                                    $active_count = array_filter($suppliers, function($s) { return $s['is_active']; });
                                    echo count($active_count);
                                ?></div>
                                <div class="stat-label">Active Suppliers</div>
                                <div class="stat-trend">
                                    <span class="trend-indicator success">
                                        <i class="bi bi-arrow-up"></i>
                                        <?php echo round((count($active_count) / max($total_suppliers, 1)) * 100, 1); ?>%
                                    </span>
                                    <span class="trend-text">of total suppliers</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card card-info">
                        <div class="stat-content">
                            <div class="stat-icon">
                                <i class="bi bi-person-check-fill"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-value"><?php echo $total_suppliers; ?></div>
                                <div class="stat-label">Total Suppliers</div>
                                <div class="stat-trend">
                                    <span class="trend-indicator neutral">
                                        <i class="bi bi-building"></i>
                                        Directory
                                    </span>
                                    <span class="trend-text">in your network</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card card-warning">
                        <div class="stat-content">
                            <div class="stat-icon">
                                <i class="bi bi-pause-circle-fill"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-value"><?php 
                                    $inactive_count = count($suppliers) - count($active_count);
                                    echo $inactive_count;
                                ?></div>
                                <div class="stat-label">Inactive Suppliers</div>
                                <div class="stat-trend">
                                    <span class="trend-indicator <?php echo $inactive_count == 0 ? 'success' : 'warning'; ?>">
                                        <i class="bi bi-<?php echo $inactive_count == 0 ? 'check' : 'exclamation-triangle'; ?>"></i>
                                        <?php echo $inactive_count == 0 ? 'None' : 'Review'; ?>
                                    </span>
                                    <span class="trend-text">requires attention</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card card-primary">
                        <div class="stat-content">
                            <div class="stat-icon">
                                <i class="bi bi-box-seam-fill"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-value"><?php echo array_sum(array_column($suppliers, 'product_count')); ?></div>
                                <div class="stat-label">Total Products</div>
                                <div class="stat-trend">
                                    <span class="trend-indicator neutral">
                                        <i class="bi bi-collection"></i>
                                        Catalog
                                    </span>
                                    <span class="trend-text">across all suppliers</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="filter-section">
                <form method="GET" class="filter-row">
                    <div class="form-group search-container">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="searchInput" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>" placeholder="Search suppliers...">
                        </div>
                    </div>
                    <div class="form-group">
                        <select class="form-control" name="status">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <select class="form-control" name="sort">
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="contact_person" <?php echo $sort_by === 'contact_person' ? 'selected' : ''; ?>>Contact Person</option>
                            <option value="email" <?php echo $sort_by === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            <option value="product_count" <?php echo $sort_by === 'product_count' ? 'selected' : ''; ?>>Product Count</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                        <a href="suppliers.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i>
                            Clear
                        </a>
                    </div>
                </form>
                
                <!-- Active Filter Tags -->
                <?php if ($search || $status_filter !== 'all'): ?>
                <div class="filter-tags">
                    <?php if ($search): ?>
                    <span class="filter-tag">
                        Search: "<?php echo htmlspecialchars($search); ?>"
                        <button type="button" class="remove-tag" onclick="removeFilter('search')">
                            <i class="bi bi-x"></i>
                        </button>
                    </span>
                    <?php endif; ?>
                    
                    <?php if ($status_filter !== 'all'): ?>
                    <span class="filter-tag">
                        Status: <?php echo ucfirst($status_filter); ?>
                        <button type="button" class="remove-tag" onclick="removeFilter('status')">
                            <i class="bi bi-x"></i>
                        </button>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" id="bulkForm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <div class="d-flex align-items-center gap-3">
                            <select class="form-select" name="bulk_action" id="bulkAction" required style="width: auto; min-width: 180px;">
                                <option value="">Choose action</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                            </select>
                            <textarea class="form-control" name="supplier_block_note" id="bulkBlockNote" rows="1" placeholder="Reason required..." style="display: none; min-width: 250px; resize: none;"></textarea>
                            <div class="form-check" id="bulkConfirmationSection" style="display: none;">
                                <input class="form-check-input" type="checkbox" id="bulkConfirmAction" name="bulk_confirm_action" required>
                                <label class="form-check-label" for="bulkConfirmAction">
                                    Confirm (<span id="selectedCount">0</span> selected)
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-check"></i>
                                Apply
                            </button>
                        </div>
                    </div>
                    <div class="text-muted">
                        Showing <?php echo count($suppliers); ?> of <?php echo $total_suppliers; ?> suppliers
                    </div>
                </div>

                <!-- Suppliers Table -->
                <div class="product-table">
                    <div class="table-scroll-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                    </th>
                                    <th>Name</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($suppliers)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <i class="bi bi-truck text-muted" style="font-size: 3rem;"></i>
                                        <h5 class="mt-3 text-muted">No suppliers found</h5>
                                        <p class="text-muted">Start by adding your first supplier</p>
                                        <a href="add.php" class="btn btn-primary">
                                            <i class="bi bi-plus"></i>
                                            Add First Supplier
                                        </a>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="supplier_ids[]" value="<?php echo $supplier['id']; ?>"
                                                   class="form-check-input supplier-checkbox">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="product-image-placeholder me-3">
                                                    <i class="bi bi-truck"></i>
                                                </div>
                                                <div>
                                                    <a href="view.php?id=<?php echo $supplier['id']; ?>" class="text-decoration-none text-dark">
                                                        <strong><?php echo htmlspecialchars($supplier['name']); ?></strong>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'Not specified'); ?></td>
                                        <td>
                                            <?php if ($supplier['email']): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($supplier['email']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($supplier['phone']): ?>
                                                <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($supplier['phone']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary">
                                                <?php echo $supplier['active_product_count']; ?>/<?php echo $supplier['product_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $supplier['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($supplier['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="view.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-primary"
                                                   title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="supplier_performance.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-info"
                                                   title="View Performance">
                                                    <i class="bi bi-graph-up"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-secondary"
                                                   title="Edit Supplier">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm <?php echo $supplier['is_active'] ? 'btn-warning' : 'btn-success'; ?> toggle-status"
                                                        data-id="<?php echo $supplier['id']; ?>"
                                                        data-current-status="<?php echo $supplier['is_active']; ?>"
                                                        title="<?php echo $supplier['is_active'] ? 'Deactivate Supplier' : 'Activate Supplier'; ?>">
                                                    <i class="bi <?php echo $supplier['is_active'] ? 'bi-pause-fill' : 'bi-play-fill'; ?>"></i>
                                                </button>
                                                <a href="delete.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Are you sure you want to delete this supplier?')"
                                                   title="Delete Supplier">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Floating Horizontal Scroll Bar -->
                <div class="floating-scrollbar" id="floatingScrollbar">
                    <div class="scrollbar-track" id="scrollbarTrack">
                        <div class="scrollbar-thumb" id="scrollbarThumb"></div>
                    </div>
                    <div class="scrollbar-info">Scroll left/right</div>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                </div>
                <nav aria-label="Supplier pagination">
                    <ul class="pagination">
                        <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                Previous
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">
                                Next
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/suppliers.js"></script>

    <script>
        // Floating scroll bar functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.querySelector('.table-scroll-container');
            const floatingScrollbar = document.getElementById('floatingScrollbar');
            const scrollbarTrack = document.getElementById('scrollbarTrack');
            const scrollbarThumb = document.getElementById('scrollbarThumb');
            
            if (!tableContainer || !floatingScrollbar) return;
            
            // Update scrollbar position based on table scroll
            function updateScrollbar() {
                const scrollLeft = tableContainer.scrollLeft;
                const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;
                const scrollPercentage = maxScroll > 0 ? scrollLeft / maxScroll : 0;
                
                const thumbWidth = Math.max(20, scrollbarTrack.offsetWidth * 0.3);
                const maxThumbLeft = scrollbarTrack.offsetWidth - thumbWidth;
                const thumbLeft = scrollPercentage * maxThumbLeft;
                
                scrollbarThumb.style.width = thumbWidth + 'px';
                scrollbarThumb.style.left = thumbLeft + 'px';
                
                // Show/hide scrollbar based on scrollability
                if (maxScroll > 0) {
                    floatingScrollbar.style.display = 'block';
                } else {
                    floatingScrollbar.style.display = 'none';
                }
            }
            
            // Handle scrollbar track click
            scrollbarTrack.addEventListener('click', function(e) {
                const rect = scrollbarTrack.getBoundingClientRect();
                const clickX = e.clientX - rect.left;
                const clickPercentage = clickX / scrollbarTrack.offsetWidth;
                
                const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;
                const newScrollLeft = clickPercentage * maxScroll;
                
                tableContainer.scrollTo({
                    left: newScrollLeft,
                    behavior: 'smooth'
                });
            });
            
            // Handle scrollbar thumb drag
            let isDragging = false;
            let startX, startScrollLeft;
            
            scrollbarThumb.addEventListener('mousedown', function(e) {
                isDragging = true;
                startX = e.clientX;
                startScrollLeft = tableContainer.scrollLeft;
                document.body.style.cursor = 'grabbing';
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                
                const deltaX = e.clientX - startX;
                const scrollDelta = (deltaX / scrollbarTrack.offsetWidth) * (tableContainer.scrollWidth - tableContainer.clientWidth);
                tableContainer.scrollLeft = startScrollLeft + scrollDelta;
            });
            
            document.addEventListener('mouseup', function() {
                if (isDragging) {
                    isDragging = false;
                    document.body.style.cursor = '';
                }
            });
            
            // Update scrollbar on table scroll
            tableContainer.addEventListener('scroll', updateScrollbar);
            
            // Update scrollbar on window resize
            window.addEventListener('resize', updateScrollbar);
            
            // Initial update
            updateScrollbar();
            
            // Hide scrollbar after 3 seconds of inactivity
            let scrollbarTimeout;
            function hideScrollbarAfterDelay() {
                clearTimeout(scrollbarTimeout);
                scrollbarTimeout = setTimeout(() => {
                    if (floatingScrollbar.style.display !== 'none') {
                        floatingScrollbar.style.opacity = '0.7';
                    }
                }, 3000);
            }
            
            // Show scrollbar on hover
            floatingScrollbar.addEventListener('mouseenter', function() {
                floatingScrollbar.style.opacity = '1';
                clearTimeout(scrollbarTimeout);
            });
            
            floatingScrollbar.addEventListener('mouseleave', hideScrollbarAfterDelay);
            
            // Initial hide delay
            hideScrollbarAfterDelay();
        });
        
        // Filter management functions
        function removeFilter(filterName) {
            const url = new URL(window.location);
            url.searchParams.delete(filterName);
            window.location.href = url.toString();
        }
        
        // Add Quick Actions Panel
        const quickActionsPanel = document.createElement('div');
        quickActionsPanel.className = 'quick-actions';
        quickActionsPanel.innerHTML = `
            <a href="add.php" class="quick-action-btn" title="Add New Supplier">
                <i class="bi bi-plus"></i>
            </a>
            <a href="bulk_operations.php" class="quick-action-btn" title="Bulk Operations">
                <i class="bi bi-arrow-down-up"></i>
            </a>
            <button type="button" class="quick-action-btn" onclick="exportSelected()" title="Export Selected">
                <i class="bi bi-download"></i>
            </button>
            <button type="button" class="quick-action-btn" onclick="selectAll()" title="Select All">
                <i class="bi bi-check2-all"></i>
            </button>
            <button type="button" class="quick-action-btn" onclick="clearSelection()" title="Clear Selection">
                <i class="bi bi-x-square"></i>
            </button>
        `;
        document.body.appendChild(quickActionsPanel);
        
        // Quick action functions
        window.exportSelected = function() {
            const selectedSuppliers = document.querySelectorAll('.supplier-checkbox:checked');
            if (selectedSuppliers.length === 0) {
                alert('Please select suppliers to export.');
                return;
            }
            
            // Create a form to submit selected supplier IDs to bulk operations
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'bulk_operations.php';
            form.style.display = 'none';
            
            selectedSuppliers.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'supplier_ids[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });
            
            const exportInput = document.createElement('input');
            exportInput.type = 'hidden';
            exportInput.name = 'export_suppliers';
            exportInput.value = '1';
            form.appendChild(exportInput);
            
            const formatInput = document.createElement('input');
            formatInput.type = 'hidden';
            formatInput.name = 'export_format';
            formatInput.value = 'csv';
            form.appendChild(formatInput);
            
            document.body.appendChild(form);
            form.submit();
        };
        
        window.selectAll = function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.dispatchEvent(new Event('change'));
            }
        };
        
        window.clearSelection = function() {
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.dispatchEvent(new Event('change'));
            }
        };
        
        // Enhanced search with debounce
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    // Auto-submit form after 1 second of inactivity
                    const form = this.closest('form');
                    if (form && this.value.length >= 3) {
                        // Only auto-search if user typed at least 3 characters
                        form.submit();
                    }
                }, 1000);
            });
        }
        
        // Handle bulk actions UI - Simplified
        const bulkActionSelect = document.getElementById('bulkAction');
        const bulkBlockNote = document.getElementById('bulkBlockNote');
        const bulkConfirmSection = document.getElementById('bulkConfirmationSection');
        const reasonGroup = document.getElementById('reasonGroup');
        const selectedCountSpan = document.getElementById('selectedCount');
        
        // Update selected count
        function updateSelectedCount() {
            const selectedSuppliers = document.querySelectorAll('.supplier-checkbox:checked');
            const count = selectedSuppliers.length;
            if (selectedCountSpan) {
                selectedCountSpan.textContent = count;
            }
            return count;
        }
        
        // Handle bulk action selection changes
        if (bulkActionSelect) {
            bulkActionSelect.addEventListener('change', function() {
                const action = this.value;
                
                // Show/hide reason field only for deactivate
                if (action === 'deactivate') {
                    reasonGroup.style.display = 'block';
                    bulkBlockNote.required = true;
                    bulkBlockNote.placeholder = 'Enter deactivation reason...';
                } else {
                    reasonGroup.style.display = 'none';
                    bulkBlockNote.required = false;
                    bulkBlockNote.value = '';
                }
                
                // Show confirmation section for any action
                if (action && action !== '') {
                    bulkConfirmSection.style.display = 'block';
                    updateSelectedCount();
                } else {
                    bulkConfirmSection.style.display = 'none';
                }
            });
        }
        
        // Enhanced bulk form validation
        const bulkForm = document.getElementById('bulkForm');
        if (bulkForm) {
            bulkForm.addEventListener('submit', function(e) {
                const action = bulkActionSelect.value;
                const selectedSuppliers = document.querySelectorAll('.supplier-checkbox:checked');
                const reason = bulkBlockNote.value.trim();
                const confirmed = document.getElementById('bulkConfirmAction').checked;
                
                // Check if suppliers are selected
                if (selectedSuppliers.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one supplier.');
                    return;
                }
                
                // Check if action is selected
                if (!action) {
                    e.preventDefault();
                    alert('Please select an action.');
                    return;
                }
                
                // Check reason only for deactivate (delete doesn't need reason)
                if (action === 'deactivate' && reason === '') {
                    e.preventDefault();
                    alert('Please provide a reason for deactivating the selected suppliers.');
                    bulkBlockNote.focus();
                    return;
                }
                
                // Check confirmation
                if (!confirmed) {
                    e.preventDefault();
                    alert('Please confirm that you want to proceed with this action.');
                    return;
                }
                
                // Final confirmation for dangerous actions
                if (action === 'delete') {
                    const confirmDelete = confirm(`Are you sure you want to delete ${selectedSuppliers.length} supplier(s)?\n\nThis action cannot be undone.\n\nReason: ${reason}`);
                    if (!confirmDelete) {
                        e.preventDefault();
                        return;
                    }
                } else if (action === 'deactivate') {
                    const confirmDeactivate = confirm(`Are you sure you want to deactivate ${selectedSuppliers.length} supplier(s)?\n\nReason: ${reason}`);
                    if (!confirmDeactivate) {
                        e.preventDefault();
                        return;
                    }
                }
            });
        }
    </script>
    
    <!-- Quick Actions Panel -->
    <div class="floating-actions">
        <button type="button" class="fab fab-mini" onclick="scrollToTop()" title="Scroll to Top">
            <i class="bi bi-arrow-up"></i>
        </button>
    </div>
    
    <script>
        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
        
        // Show/hide scroll to top button
        window.addEventListener('scroll', function() {
            const scrollButton = document.querySelector('.floating-actions .fab');
            if (window.scrollY > 300) {
                scrollButton.style.display = 'flex';
            } else {
                scrollButton.style.display = 'none';
            }
        });
    </script>

</body>
</html>
