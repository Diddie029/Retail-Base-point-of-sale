<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/AutoBOMManager.php';

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

// Check if user has permission to process sales
if (!hasPermission('process_sales', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Initialize Auto BOM Manager
$auto_bom_manager = new AutoBOMManager($conn, $user_id);

// Handle POST requests for cart storage and hold transactions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'store_cart') {
        $_SESSION['pos_cart'] = $_POST['cart_data'];
        echo json_encode(['success' => true]);
        exit();
    }
    
    if ($_POST['action'] === 'hold_transaction') {
        try {
            $cart_data = json_decode($_POST['cart_data'], true);
            $reason = sanitizeInput($_POST['reason'] ?? '');
            $customer_reference = sanitizeInput($_POST['customer_reference'] ?? '');
            
            if (empty($cart_data['items'])) {
                throw new Exception('No items in cart to hold');
            }
            
            // Create held transaction record
            $stmt = $conn->prepare("
                INSERT INTO held_transactions (user_id, cart_data, reason, customer_reference, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user_id,
                json_encode($cart_data),
                $reason,
                $customer_reference
            ]);
            
            $hold_id = $conn->lastInsertId();
            
            // Log the hold action
            logActivity($conn, $user_id, 'transaction_held', "Held transaction #$hold_id with " . count($cart_data['items']) . " items");
            
            echo json_encode([
                'success' => true,
                'hold_id' => $hold_id,
                'message' => 'Transaction held successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_held_transactions') {
        try {
            $stmt = $conn->prepare("
                SELECT ht.*, u.username as cashier_name
                FROM held_transactions ht
                JOIN users u ON ht.user_id = u.id
                WHERE ht.status = 'held'
                ORDER BY ht.created_at DESC
            ");
            $stmt->execute();
            $held_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse cart data for each transaction
            foreach ($held_transactions as &$transaction) {
                $cart_data = json_decode($transaction['cart_data'], true);
                $transaction['item_count'] = count($cart_data['items']);
                $transaction['total_amount'] = $cart_data['total'];
            }
            
            echo json_encode([
                'success' => true,
                'transactions' => $held_transactions
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'resume_held_transaction') {
        try {
            $hold_id = (int)($_POST['hold_id'] ?? 0);
            
            if (!$hold_id) {
                throw new Exception('Hold ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT * FROM held_transactions 
                WHERE id = ? AND status = 'held'
            ");
            $stmt->execute([$hold_id]);
            $held_transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$held_transaction) {
                throw new Exception('Held transaction not found');
            }
            
            echo json_encode([
                'success' => true,
                'cart_data' => $held_transaction['cart_data']
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'delete_held_transaction') {
        try {
            $hold_id = (int)($_POST['hold_id'] ?? 0);
            
            if (!$hold_id) {
                throw new Exception('Hold ID is required');
            }
            
            $stmt = $conn->prepare("
                UPDATE held_transactions 
                SET status = 'deleted', updated_at = NOW()
                WHERE id = ? AND status = 'held'
            ");
            $result = $stmt->execute([$hold_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Held transaction not found or already processed');
            }
            
            // Log the delete action
            logActivity($conn, $user_id, 'held_transaction_deleted', "Deleted held transaction #$hold_id");
            
            echo json_encode([
                'success' => true,
                'message' => 'Held transaction deleted successfully'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit();
    }
}

// Handle AJAX requests for Auto BOM functionality
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['action']) {
            case 'get_auto_bom_units':
                $base_product_id = (int) ($_GET['base_product_id'] ?? 0);
                if (!$base_product_id) {
                    throw new Exception('Base product ID is required');
                }

                $selling_units = $auto_bom_manager->getAvailableSellingUnits($base_product_id);

                // Calculate prices for each unit
                foreach ($selling_units as &$unit) {
                    try {
                        $unit['calculated_price'] = $auto_bom_manager->calculateSellingUnitPrice($unit['id']);
                        $unit['formatted_price'] = formatCurrency($unit['calculated_price'], $settings);
                    } catch (Exception $e) {
                        $unit['calculated_price'] = null;
                        $unit['formatted_price'] = 'Price unavailable';
                    }
                }

                echo json_encode([
                    'success' => true,
                    'selling_units' => array_values($selling_units)
                ]);
                break;

            case 'check_inventory':
                $product_id = (int) ($_GET['product_id'] ?? 0);
                $quantity = (float) ($_GET['quantity'] ?? 0);
                $selling_unit_id = isset($_GET['selling_unit_id']) ? (int) $_GET['selling_unit_id'] : null;

                if (!$product_id || !$quantity) {
                    throw new Exception('Product ID and quantity are required');
                }

                $inventory_check = $auto_bom_manager->checkBaseStockAvailability($product_id, $quantity, $selling_unit_id);

                echo json_encode([
                    'success' => true,
                    'inventory_check' => $inventory_check
                ]);
                break;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// Get walk-in customer
$walk_in_customer = getWalkInCustomer($conn);
if (!$walk_in_customer) {
    // Try to create walk-in customer if it doesn't exist
    $stmt = $conn->prepare("
        INSERT INTO customers (
            customer_number, first_name, last_name, customer_type,
            membership_status, membership_level, notes, created_by
        ) VALUES (
            'WALK-IN-001', 'Walk-in', 'Customer', 'walk_in',
            'active', 'Bronze', 'Default customer for walk-in purchases', ?
        )
    ");
    $stmt->execute([$user_id]);
    $walk_in_customer = getWalkInCustomer($conn);
}

// Get products for POS (both regular and Auto BOM enabled)
$products = [];
$stmt = $conn->query("
    SELECT p.*, c.name as category_name,
           p.is_auto_bom_enabled, p.auto_bom_type,
           COUNT(CASE WHEN su.status = 'active' THEN 1 END) as selling_units_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
    LEFT JOIN auto_bom_selling_units su ON abc.id = su.auto_bom_config_id
    WHERE p.status = 'active'
    GROUP BY p.id
    ORDER BY p.name ASC
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filtering
$categories = [];
$stmt = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        /* POS Unique UI Styles */
        .pos-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .pos-title h1 {
            color: white !important;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .pos-title small {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
        }

        .pos-actions .btn {
            border-radius: 25px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .pos-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .pos-actions .btn-success {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            font-size: 1.1rem;
            padding: 1rem 2rem;
        }

        /* POS Full Width Layout */
        .pos-container-full {
            width: 100vw;
            height: 100vh;
            background: #f8f9fa;
            overflow: hidden;
        }

        .pos-main-content {
            width: 100%;
            height: 100vh;
            padding: 0;
            overflow: hidden; /* Remove scrollbar */
        }

        .pos-top-bar {
            background: linear-gradient(135deg, var(--primary-color), #764ba2);
            border: none;
            padding: 1.5rem 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .pos-brand {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .pos-brand h5 {
            color: white !important;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pos-brand h5 i {
            font-size: 1.4rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }

        .pos-brand small {
            color: rgba(255,255,255,0.85);
            font-size: 0.85rem;
            font-weight: 500;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .pos-top-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .pos-dashboard-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            font-weight: 600;
            border-radius: 12px;
            padding: 0.65rem 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .pos-dashboard-btn:hover {
            background: rgba(255,255,255,0.25);
            border-color: rgba(255,255,255,0.4);
            color: white;
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .pos-dashboard-btn:active {
            transform: translateY(0) scale(0.98);
        }

        .pos-dashboard-btn i {
            font-size: 1rem;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));
        }

        .pos-user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255,255,255,0.1);
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .pos-user-info .user-avatar {
            width: 36px;
            height: 36px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            border: 2px solid rgba(255,255,255,0.3);
        }

        .pos-user-info .user-details {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }

        .pos-user-info .user-welcome {
            color: rgba(255,255,255,0.8);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .pos-user-info .user-name {
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .pos-user-info .user-role {
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.6rem;
            border-radius: 8px;
            margin-left: 0.5rem;
            text-shadow: none;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .pos-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            height: calc(100vh - 200px); /* Account for top bar + bottom padding */
            background: transparent; /* Remove background to use full screen */
            border-radius: 0; /* Remove border radius for full screen */
            padding: 1rem 0 100px 0; /* Top and bottom padding for better spacing */
            margin: 0 1.5rem 0 1.5rem; /* Slightly larger left/right margins */
            box-shadow: none; /* Remove shadow for cleaner look */
            overflow: hidden; /* Remove any container scrollbars */
        }

        /* Floating Dashboard Button */
        .floating-dashboard-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
        }

        .btn-floating {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-floating:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.6);
            color: white;
        }

        .btn-floating i {
            font-size: 1.2rem;
        }

        .btn-text {
            font-size: 0.9rem;
        }

        .products-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            overflow: hidden; /* Remove scrollbar */
            height: 100%; /* Take full height */
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(0,0,0,0.06);
        }

        .products-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .products-section-header h5 {
            color: #1e293b;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
        }

        .products-section-header .bi {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .products-filters {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-select, .form-control {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .input-group {
            position: relative;
        }

        .input-group-text {
            background: #f8fafc;
            border: 1px solid #d1d5db;
            border-right: none;
            color: #64748b;
            border-radius: 8px 0 0 8px;
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 8px 8px 0;
        }

        .input-group .form-control:focus {
            border-left: none;
        }

        .search-highlight {
            background: rgba(99, 102, 241, 0.1);
            padding: 0.1rem 0.2rem;
            border-radius: 3px;
            font-weight: 600;
        }

        .cart-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            height: 100%; /* Take full height */
            overflow: hidden; /* Remove any scrollbars */
            border: 1px solid rgba(0,0,0,0.06);
        }

        .cart-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .cart-section-header h5 {
            color: #1e293b;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cart-section-header .bi {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            flex: 1;
            overflow-y: auto;
            padding: 0.3rem;
            max-height: calc(100vh - 400px);
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f1f5f9;
        }

        .product-grid::-webkit-scrollbar {
            width: 6px;
        }

        .product-grid::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }

        .product-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .product-grid::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .product-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.5rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            height: auto;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            overflow: hidden;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), #8b5cf6);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
        }

        .product-card:hover::before {
            transform: scaleX(1);
        }

        .product-card:active {
            transform: translateY(-3px) scale(0.98);
            transition: all 0.1s ease;
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            box-shadow: 0 12px 30px rgba(99, 102, 241, 0.25);
        }

        .product-card.selected::before {
            transform: scaleX(1);
        }

        .product-name {
            font-weight: 700;
            margin-bottom: 0.2rem;
            margin-top: 0.3rem;
            color: #1e293b;
            font-size: 0.8rem;
            line-height: 1.1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-price {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 0.2rem;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .product-stock {
            font-size: 0.65rem;
            color: #64748b;
            margin-top: 0.1rem;
            display: flex;
            align-items: center;
            gap: 0.15rem;
        }

        .product-stock i {
            font-size: 0.65rem;
        }

        .product-stock.low-stock {
            color: #f59e0b;
            font-weight: 600;
        }

        .product-stock.out-of-stock {
            color: #dc2626;
            font-weight: 700;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.8rem;
            border: 1px solid #fca5a5;
        }

        .product-category {
            position: absolute;
            top: 4px;
            left: 4px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
            padding: 0.15rem 0.3rem;
            border-radius: 6px;
            font-size: 0.55rem;
            font-weight: 600;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .auto-bom-indicator {
            position: absolute;
            top: 4px;
            right: 4px;
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
            padding: 0.25rem;
            border-radius: 50%;
            font-size: 0.65rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(6, 182, 212, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            background: white;
            border-radius: 12px;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
            border: 1px solid #f1f5f9;
        }

        .cart-item:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }

        .cart-item-info {
            flex: 1;
            margin-right: 1rem;
        }

        .cart-item-name {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1e293b;
            font-size: 0.95rem;
            line-height: 1.3;
        }

        .cart-item-details {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8fafc;
            border-radius: 8px;
            padding: 0.25rem;
            border: 1px solid #e2e8f0;
        }

        .quantity-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .quantity-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .quantity-btn:active {
            transform: scale(0.95);
        }

        .quantity-display {
            min-width: 30px;
            text-align: center;
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
        }

        .cart-item-total {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1rem;
            min-width: 80px;
            text-align: right;
        }

        .cart-item-remove {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: 6px;
            padding: 0.5rem;
            transition: all 0.2s ease;
        }

        .cart-item-remove:hover {
            background: #dc2626;
            color: white;
            transform: scale(1.05);
        }

        .cart-total {
            border-top: 2px solid #e2e8f0;
            padding-top: 1.5rem;
            margin-top: auto;
            background: #f8fafc;
            margin-left: -1.5rem;
            margin-right: -1.5rem;
            margin-bottom: -1.5rem;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
        }

        .total-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .selling-units-modal .modal-dialog {
            max-width: 600px;
        }

        .selling-unit-option {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .selling-unit-option:hover {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .selling-unit-option.selected {
            border-color: var(--primary-color);
            background: #e0f2fe;
        }

        .unit-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .unit-details {
            font-size: 0.8rem;
            color: #64748b;
        }

        .unit-price {
            font-weight: 700;
            color: var(--primary-color);
        }

        /* Floating Bottom Navigation */
        .floating-bottom-nav {
            position: fixed;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: auto;
            max-width: 90vw;
        }

        .nav-container {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 30px;
            padding: 12px 18px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12),
                        0 4px 12px rgba(0, 0, 0, 0.08),
                        inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .nav-container:hover {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.18),
                        0 6px 16px rgba(0, 0, 0, 0.12),
                        inset 0 1px 0 rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 65px;
            padding: 14px 10px 10px 10px;
            border: none;
            background: transparent;
            border-radius: 18px;
            text-decoration: none;
            color: #6b7280;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 18px;
        }

        .nav-item i {
            font-size: 1.5rem;
            margin-bottom: 6px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .nav-item span {
            font-size: 0.7rem;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .nav-item:hover {
            color: #4338ca;
            transform: translateY(-3px) scale(1.05);
        }

        .nav-item:hover::before {
            opacity: 1;
        }

        .nav-item:hover i {
            transform: scale(1.15);
        }

        .nav-item.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-width: 75px;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .nav-item.primary::before {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
        }

        .nav-item.primary:hover {
            color: white;
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.5);
            transform: translateY(-4px) scale(1.08);
        }

        .nav-item:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(0.5);
        }

        .nav-item.primary:disabled {
            background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
            box-shadow: 0 2px 8px rgba(156, 163, 175, 0.3);
        }

        .nav-item:active {
            transform: translateY(-1px) scale(0.98);
        }

        /* Cart items container should not scroll */
        #cartItems {
            overflow: hidden; /* Remove cart scrollbars */
            max-height: calc(100vh - 470px); /* Fixed height for cart items */
            flex: 1;
        }

        .empty-cart {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #64748b;
        }

        .empty-cart .bi {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.4;
        }

        .empty-cart p {
            font-size: 1.1rem;
            font-weight: 500;
            margin: 0;
        }

        .customer-info {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .customer-info .customer-label {
            color: #64748b;
            font-weight: 500;
        }

        .customer-info .customer-name {
            color: #1e293b;
            font-weight: 600;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .product-card {
            animation: fadeIn 0.5s ease;
        }

        .cart-item {
            animation: slideIn 0.3s ease;
        }

        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* Enhanced responsive design */
        @media (max-width: 1200px) {
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 0.4rem;
            }
        }

        @media (max-width: 768px) {
            .nav-item {
                min-width: 55px;
                padding: 12px 8px 8px 8px;
            }
            
            .nav-item i {
                font-size: 1.3rem;
            }
            
            .nav-item span {
                font-size: 0.65rem;
            }
            
            .nav-container {
                gap: 4px;
                padding: 10px 14px;
            }
            
            .pos-container {
                grid-template-columns: 1fr;
                height: auto;
                gap: 1rem;
                margin: 0 0.75rem 0.75rem 0.75rem;
            }
            
            .products-section,
            .cart-section {
                height: 50vh;
                min-height: 300px;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 0.3rem;
            }

            .product-card {
                padding: 0.4rem;
                min-height: 70px;
            }

            .products-filters {
                flex-direction: column;
                align-items: stretch;
                gap: 0.75rem;
            }

            .filter-group {
                width: 100%;
            }

            .form-select, .form-control {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .pos-top-bar {
                padding: 1rem;
            }

            .pos-brand h5 {
                font-size: 1rem;
            }

            .pos-user-info {
                flex-direction: column;
                align-items: flex-end;
                gap: 0.25rem;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 0.25rem;
            }

            .product-card {
                padding: 0.3rem;
                min-height: 60px;
            }

            .product-name {
                font-size: 0.7rem;
            }

            .product-price {
                font-size: 0.85rem;
            }

            .nav-container {
                padding: 8px 12px;
                gap: 2px;
            }

            .nav-item {
                min-width: 50px;
                padding: 10px 6px 6px 6px;
            }

            .nav-item i {
                font-size: 1.1rem;
            }

            .nav-item span {
                font-size: 0.6rem;
            }
        }

        /* Dark mode support for floating nav */
        @media (prefers-color-scheme: dark) {
            .nav-container {
                background: rgba(31, 41, 55, 0.95);
                border: 1px solid rgba(75, 85, 99, 0.3);
            }
            
            .nav-container:hover {
                background: rgba(31, 41, 55, 0.98);
            }
            
            .nav-item {
                color: #d1d5db;
            }
            
            .nav-item:hover {
                color: #818cf8;
                background: rgba(129, 140, 248, 0.1);
            }
        }
    </style>
</head>
<body>
    <div class="pos-container-full">
        <main class="pos-main-content">
            <!-- POS Top Bar -->
            <div class="pos-top-bar">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="pos-brand">
                        <h5>
                            <i class="bi bi-cart-plus"></i>
                            Point of Sale
                        </h5>
                        <small>Quick & Easy Sales Processing</small>
                    </div>
                    <div class="pos-top-actions">
                        <a href="../dashboard/dashboard.php" class="pos-dashboard-btn" title="Go to Dashboard">
                            <i class="bi bi-speedometer2"></i>
                            <span>Dashboard</span>
                        </a>
                        <div class="pos-user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($username, 0, 1)); ?>
                            </div>
                            <div class="user-details">
                                <span class="user-welcome">Welcome back,</span>
                                <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                            </div>
                            <span class="user-role"><?php echo htmlspecialchars($role_name); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container-fluid" style="padding: 0 1rem 0 1rem;"> <!-- Only right and left padding -->
                <div class="pos-container">
                    <!-- Products Section -->
                    <div class="products-section">
                        <div class="products-section-header">
                            <h5><i class="bi bi-grid-3x3-gap-fill"></i>Products</h5>
                            <div class="products-filters">
                                <div class="filter-group">
                                    <label class="filter-label">Category</label>
                                    <select class="form-select" id="categoryFilter" style="min-width: 160px;">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Search</label>
                                    <div class="input-group" style="min-width: 220px;">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" class="form-control" id="productSearch" placeholder="Search products...">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="product-grid" id="productGrid">
                            <?php foreach ($products as $product): ?>
                                <div class="product-card"
                                     data-product-id="<?php echo $product['id']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                                     data-product-price="<?php echo $product['price']; ?>"
                                     data-product-stock="<?php echo $product['quantity']; ?>"
                                     data-is-auto-bom="<?php echo $product['is_auto_bom_enabled'] ? 'true' : 'false'; ?>"
                                     data-selling-units-count="<?php echo $product['selling_units_count']; ?>"
                                     data-category-id="<?php echo $product['category_id']; ?>">
                                    
                                    <?php if ($product['category_name']): ?>
                                        <div class="product-category"><?php echo htmlspecialchars($product['category_name']); ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($product['is_auto_bom_enabled']): ?>
                                        <div class="auto-bom-indicator" title="Auto BOM Product">
                                            <i class="bi bi-gear-fill"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                    <div class="product-price"><?php echo $settings['currency_symbol']; ?> <?php echo number_format($product['price'], 2); ?></div>
                                    
                                    <div class="product-stock <?php echo $product['quantity'] <= 10 ? 'low-stock' : ''; ?> <?php echo $product['quantity'] <= 0 ? 'out-of-stock' : ''; ?>">
                                        <i class="bi bi-box-seam"></i>
                                        Stock: <?php echo $product['quantity']; ?>
                                    </div>
                                    
                                    <?php if ($product['is_auto_bom_enabled']): ?>
                                        <div class="product-stock" style="color: #06b6d4;">
                                            <i class="bi bi-puzzle-fill"></i>
                                            <?php echo $product['selling_units_count']; ?> units
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Cart Section -->
                    <div class="cart-section">
                        <div class="cart-section-header">
                            <h5><i class="bi bi-cart-check-fill"></i>Current Sale</h5>
                            <div class="customer-info">
                                <span class="customer-label">Customer:</span>
                                <span class="customer-name" id="currentCustomer">
                                    <?php echo htmlspecialchars($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']); ?>
                                </span>
                                <button class="btn btn-sm btn-outline-primary" id="changeCustomerBtn" title="Change Customer">
                                    <i class="bi bi-person-gear"></i>
                                </button>
                            </div>
                        </div>

                        <div id="cartItems" class="flex-grow-1">
                            <!-- Cart items will be populated here -->
                            <div class="text-center text-muted mt-5">
                                <i class="bi bi-cart" style="font-size: 3rem;"></i>
                                <p class="mt-2">No items in cart</p>
                            </div>
                        </div>

                        <div class="cart-total">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-bold">Total:</span>
                                <span class="total-amount" id="cartTotal"><?php echo $settings['currency_symbol']; ?> 0.00</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted">Items:</span>
                                <span id="cartItemCount">0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Floating Bottom Navigation -->
    <div class="floating-bottom-nav">
        <div class="nav-container">
            <button type="button" class="nav-item" id="holdTransactionBtn" disabled title="Hold Transaction">
                <i class="bi bi-pause-circle-fill"></i>
                <span>Hold</span>
            </button>
            <button type="button" class="nav-item" id="viewHeldBtn" title="View Held Sales">
                <i class="bi bi-clock-history"></i>
                <span>Held</span>
            </button>
            <button type="button" class="nav-item" id="clearCart" title="Clear Cart">
                <i class="bi bi-trash3-fill"></i>
                <span>Clear</span>
            </button>
            <button type="button" class="nav-item primary" id="checkoutBtn" disabled title="Checkout">
                <i class="bi bi-credit-card-2-front-fill"></i>
                <span>Checkout</span>
            </button>
        </div>
    </div>

    <!-- Auto BOM Selling Units Modal -->
    <div class="modal fade selling-units-modal" id="sellingUnitsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Selling Unit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="sellingUnitsList">
                        <!-- Selling units will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmSellingUnit">Add to Cart</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Held Transactions Modal -->
    <div class="modal fade" id="heldTransactionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Held Transactions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="heldTransactionsList">
                        <!-- Held transactions will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hold Transaction Modal -->
    <div class="modal fade" id="holdTransactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hold Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="holdReason" class="form-label">Reason for holding (optional)</label>
                        <input type="text" class="form-control" id="holdReason" placeholder="e.g., Customer will return later, Need to check stock, etc.">
                    </div>
                    <div class="mb-3">
                        <label for="customerReference" class="form-label">Customer Reference (optional)</label>
                        <input type="text" class="form-control" id="customerReference" placeholder="Customer name, phone, or reference">
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        This transaction will be saved and can be resumed later from the "Held Sales" list.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmHoldTransaction">
                        <i class="bi bi-pause-circle"></i> Hold Transaction
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>

    <script>
        // POS functionality
        document.addEventListener('DOMContentLoaded', function() {
            let cart = [];
            let selectedProductForModal = null;
            const currencySymbol = '<?php echo $settings['currency_symbol']; ?>';

            // Product search and filtering
            const productSearch = document.getElementById('productSearch');
            const categoryFilter = document.getElementById('categoryFilter');
            const productGrid = document.getElementById('productGrid');

            function filterProducts() {
                const searchTerm = productSearch.value.toLowerCase();
                const categoryId = categoryFilter.value;
                const productCards = productGrid.querySelectorAll('.product-card');

                productCards.forEach(card => {
                    const productName = card.dataset.productName.toLowerCase();
                    const productCategory = card.dataset.categoryId || '';
                    const matchesSearch = productName.includes(searchTerm);
                    const matchesCategory = !categoryId || productCategory === categoryId;

                    if (matchesSearch && matchesCategory) {
                        card.style.display = 'block';
                        card.style.animation = 'fadeIn 0.3s ease';
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Update product count display
                const visibleProducts = productGrid.querySelectorAll('.product-card[style*="block"]').length;
                const totalProducts = productCards.length;
                
                // Add or update product count indicator
                let countIndicator = document.querySelector('.product-count-indicator');
                if (!countIndicator) {
                    countIndicator = document.createElement('div');
                    countIndicator.className = 'product-count-indicator';
                    countIndicator.style.cssText = `
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: var(--primary-color);
                        color: white;
                        padding: 0.25rem 0.5rem;
                        border-radius: 12px;
                        font-size: 0.7rem;
                        font-weight: 600;
                        z-index: 10;
                    `;
                    productGrid.style.position = 'relative';
                    productGrid.appendChild(countIndicator);
                }
                countIndicator.textContent = `${visibleProducts} of ${totalProducts} products`;
            }

            productSearch.addEventListener('input', filterProducts);
            categoryFilter.addEventListener('change', filterProducts);

            // Product selection with loading state
            productGrid.addEventListener('click', function(e) {
                const productCard = e.target.closest('.product-card');
                if (!productCard) return;

                // Add loading state to product card
                productCard.style.pointerEvents = 'none';
                productCard.style.opacity = '0.7';
                productCard.style.transform = 'scale(0.95)';

                const productId = productCard.dataset.productId;
                const productName = productCard.dataset.productName;
                const productPrice = parseFloat(productCard.dataset.productPrice);
                const productStock = parseInt(productCard.dataset.productStock);
                const isAutoBom = productCard.dataset.isAutoBom === 'true';

                // Simulate loading delay for better UX
                setTimeout(() => {
                    if (isAutoBom) {
                        // Show Auto BOM selling units modal
                        showSellingUnitsModal(productId, productName);
                    } else {
                        // Add regular product to cart
                        addToCart({
                            id: productId,
                            name: productName,
                            price: productPrice,
                            quantity: 1,
                            stock: productStock,
                            is_auto_bom: false
                        });
                        
                        // Show success feedback
                        showAddToCartFeedback(productCard);
                    }
                    
                    // Reset product card state
                    productCard.style.pointerEvents = 'auto';
                    productCard.style.opacity = '1';
                    productCard.style.transform = 'scale(1)';
                }, 200);
            });

            // Add to cart feedback animation
            function showAddToCartFeedback(productCard) {
                const feedback = document.createElement('div');
                feedback.innerHTML = '<i class="bi bi-check-circle-fill"></i> Added!';
                feedback.style.cssText = `
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: #10b981;
                    color: white;
                    padding: 0.5rem 1rem;
                    border-radius: 20px;
                    font-size: 0.8rem;
                    font-weight: 600;
                    z-index: 1000;
                    animation: pulse 0.6s ease;
                    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                `;
                
                productCard.style.position = 'relative';
                productCard.appendChild(feedback);
                
                setTimeout(() => {
                    feedback.remove();
                }, 1000);
            }

            // Auto BOM selling units modal
            const sellingUnitsModal = new bootstrap.Modal(document.getElementById('sellingUnitsModal'));

            function showSellingUnitsModal(productId, productName) {
                selectedProductForModal = { id: productId, name: productName };

                // Load selling units
                fetch(`?action=get_auto_bom_units&base_product_id=${productId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displaySellingUnits(data.selling_units);
                            sellingUnitsModal.show();
                        } else {
                            alert('Error loading selling units: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error loading selling units');
                    });
            }

            function displaySellingUnits(sellingUnits) {
                const container = document.getElementById('sellingUnitsList');
                let html = '';

                if (sellingUnits.length === 0) {
                    html = '<div class="text-center text-muted"><p>No selling units available</p></div>';
                } else {
                    sellingUnits.forEach(unit => {
                        html += `
                            <div class="selling-unit-option" data-unit-id="${unit.id}" data-unit-price="${unit.calculated_price || unit.fixed_price}">
                                <div class="unit-name">${unit.unit_name} (${unit.unit_quantity} units)</div>
                                <div class="unit-details">
                                    SKU: ${unit.unit_sku || 'N/A'} |
                                    Price: ${currencySymbol} ${parseFloat(unit.calculated_price || unit.fixed_price).toFixed(2)}
                                </div>
                            </div>
                        `;
                    });
                }

                container.innerHTML = html;

                // Add click handlers for unit selection
                container.querySelectorAll('.selling-unit-option').forEach(option => {
                    option.addEventListener('click', function() {
                        container.querySelectorAll('.selling-unit-option').forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                    });
                });
            }

            // Confirm selling unit selection
            document.getElementById('confirmSellingUnit').addEventListener('click', function() {
                const selectedUnit = document.querySelector('.selling-unit-option.selected');
                if (!selectedUnit || !selectedProductForModal) return;

                const unitId = selectedUnit.dataset.unitId;
                const unitPrice = parseFloat(selectedUnit.dataset.unitPrice);
                const unitName = selectedUnit.querySelector('.unit-name').textContent;

                addToCart({
                    id: selectedProductForModal.id + '_' + unitId, // Create unique ID
                    name: selectedProductForModal.name + ' - ' + unitName,
                    price: unitPrice,
                    quantity: 1,
                    stock: 999, // Auto BOM units don't have traditional stock limits
                    is_auto_bom: true,
                    selling_unit_id: unitId,
                    base_product_id: selectedProductForModal.id
                });

                sellingUnitsModal.hide();
                selectedProductForModal = null;
            });

            // Cart management
            function addToCart(product) {
                const existingItem = cart.find(item => item.id === product.id);

                if (existingItem) {
                    existingItem.quantity += product.quantity;
                } else {
                    cart.push(product);
                }

                updateCartDisplay();
            }

            function removeFromCart(productId) {
                cart = cart.filter(item => item.id !== productId);
                updateCartDisplay();
            }

            function updateCartQuantity(productId, newQuantity) {
                const item = cart.find(item => item.id === productId);
                if (item) {
                    item.quantity = Math.max(1, newQuantity);
                    updateCartDisplay();
                }
            }

            function updateCartDisplay() {
                const cartContainer = document.getElementById('cartItems');
                const cartTotal = document.getElementById('cartTotal');
                const cartItemCount = document.getElementById('cartItemCount');
                const checkoutBtn = document.getElementById('checkoutBtn');
                const holdBtn = document.getElementById('holdTransactionBtn');

                if (cart.length === 0) {
                    cartContainer.innerHTML = `
                        <div class="text-center text-muted mt-5">
                            <i class="bi bi-cart" style="font-size: 3rem;"></i>
                            <p class="mt-2">No items in cart</p>
                        </div>
                    `;
                    cartTotal.textContent = currencySymbol + ' 0.00';
                    cartItemCount.textContent = '0';
                    checkoutBtn.disabled = true;
                    holdBtn.disabled = true;
                    return;
                }

                checkoutBtn.disabled = false;
                holdBtn.disabled = false;

                let html = '';
                let total = 0;
                let itemCount = 0;

                cart.forEach(item => {
                    const itemTotal = item.price * item.quantity;
                    total += itemTotal;
                    itemCount += item.quantity;

                    html += `
                        <div class="cart-item">
                            <div class="cart-item-info">
                                <div class="cart-item-name">${item.name}</div>
                                <div class="cart-item-details">
                                    <i class="bi bi-currency-dollar"></i>
                                    ${currencySymbol} ${item.price.toFixed(2)} each
                                    ${item.is_auto_bom ? ' <span class="badge bg-info ms-1">Auto BOM</span>' : ''}
                                </div>
                            </div>
                            <div class="cart-item-controls">
                                <div class="quantity-controls">
                                    <button class="quantity-btn" onclick="updateCartQuantity('${item.id}', ${item.quantity - 1})" title="Decrease quantity">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <span class="quantity-display">${item.quantity}</span>
                                    <button class="quantity-btn" onclick="updateCartQuantity('${item.id}', ${item.quantity + 1})" title="Increase quantity">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <div class="cart-item-total">
                                    ${currencySymbol} ${itemTotal.toFixed(2)}
                                </div>
                                <button class="cart-item-remove" onclick="removeFromCart('${item.id}')" title="Remove item">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    `;
                });

                cartContainer.innerHTML = html;
                cartTotal.textContent = currencySymbol + ' ' + total.toFixed(2);
                cartItemCount.textContent = itemCount;
            }

            // Clear cart
            document.getElementById('clearCart').addEventListener('click', function() {
                if (confirm('Are you sure you want to clear the cart?')) {
                    cart = [];
                    updateCartDisplay();
                }
            });

            // Hold transaction functionality
            const holdTransactionModal = new bootstrap.Modal(document.getElementById('holdTransactionModal'));
            const heldTransactionsModal = new bootstrap.Modal(document.getElementById('heldTransactionsModal'));

            // Hold transaction button
            document.getElementById('holdTransactionBtn').addEventListener('click', function() {
                if (cart.length === 0) return;
                holdTransactionModal.show();
            });

            // Confirm hold transaction
            document.getElementById('confirmHoldTransaction').addEventListener('click', function() {
                const reason = document.getElementById('holdReason').value;
                const customerReference = document.getElementById('customerReference').value;
                
                const cartData = {
                    items: cart,
                    customer: currentCustomer,
                    total: calculateCartTotal()
                };

                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=hold_transaction&cart_data=${encodeURIComponent(JSON.stringify(cartData))}&reason=${encodeURIComponent(reason)}&customer_reference=${encodeURIComponent(customerReference)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Transaction held successfully with ID: ${data.hold_id}`);
                        cart = [];
                        updateCartDisplay();
                        holdTransactionModal.hide();
                        // Clear form
                        document.getElementById('holdReason').value = '';
                        document.getElementById('customerReference').value = '';
                    } else {
                        alert('Error holding transaction: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error holding transaction. Please try again.');
                });
            });

            // View held transactions
            document.getElementById('viewHeldBtn').addEventListener('click', function() {
                loadHeldTransactions();
                heldTransactionsModal.show();
            });

            function loadHeldTransactions() {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_held_transactions'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayHeldTransactions(data.transactions);
                    } else {
                        document.getElementById('heldTransactionsList').innerHTML = '<div class="alert alert-danger">Error loading held transactions: ' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('heldTransactionsList').innerHTML = '<div class="alert alert-danger">Error loading held transactions.</div>';
                });
            }

            function displayHeldTransactions(transactions) {
                const container = document.getElementById('heldTransactionsList');
                
                if (transactions.length === 0) {
                    container.innerHTML = '<div class="text-center text-muted p-4"><i class="bi bi-inbox" style="font-size: 3rem;"></i><p class="mt-2">No held transactions</p></div>';
                    return;
                }

                let html = '';
                transactions.forEach(transaction => {
                    const heldDate = new Date(transaction.created_at);
                    html += `
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title">Hold #${transaction.id}</h6>
                                        <p class="card-text">
                                            <small class="text-muted">
                                                ${heldDate.toLocaleString()}<br>
                                                Cashier: ${transaction.cashier_name}<br>
                                                Items: ${transaction.item_count} | Total: ${currencySymbol} ${parseFloat(transaction.total_amount).toFixed(2)}
                                            </small>
                                        </p>
                                        ${transaction.reason ? `<p class="card-text"><small><strong>Reason:</strong> ${transaction.reason}</small></p>` : ''}
                                        ${transaction.customer_reference ? `<p class="card-text"><small><strong>Customer:</strong> ${transaction.customer_reference}</small></p>` : ''}
                                    </div>
                                    <div class="btn-group-vertical">
                                        <button class="btn btn-sm btn-success" onclick="resumeHeldTransaction(${transaction.id})">
                                            <i class="bi bi-play-circle"></i> Resume
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteHeldTransaction(${transaction.id})">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });

                container.innerHTML = html;
            }

            // Resume held transaction
            window.resumeHeldTransaction = function(holdId) {
                if (cart.length > 0) {
                    if (!confirm('Current cart has items. Resuming will replace current cart. Continue?')) {
                        return;
                    }
                }

                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=resume_held_transaction&hold_id=${holdId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cartData = JSON.parse(data.cart_data);
                        cart = cartData.items;
                        currentCustomer = cartData.customer;
                        document.getElementById('currentCustomer').textContent = currentCustomer.name;
                        updateCartDisplay();
                        heldTransactionsModal.hide();
                        alert('Transaction resumed successfully!');
                        
                        // Mark as resumed in database
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=mark_held_resumed&hold_id=${holdId}`
                        });
                    } else {
                        alert('Error resuming transaction: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error resuming transaction.');
                });
            };

            // Delete held transaction
            window.deleteHeldTransaction = function(holdId) {
                if (!confirm('Are you sure you want to delete this held transaction? This action cannot be undone.')) {
                    return;
                }

                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_held_transaction&hold_id=${holdId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Held transaction deleted successfully.');
                        loadHeldTransactions(); // Refresh the list
                    } else {
                        alert('Error deleting held transaction: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting held transaction.');
                });
            };

            // Customer management
            let currentCustomer = {
                id: <?php echo $walk_in_customer['id']; ?>,
                name: '<?php echo addslashes($walk_in_customer['first_name'] . ' ' . $walk_in_customer['last_name']); ?>',
                type: '<?php echo $walk_in_customer['customer_type']; ?>',
                is_walk_in: true
            };

            document.getElementById('changeCustomerBtn').addEventListener('click', function() {
                // For now, just show current customer info
                alert('Current Customer: ' + currentCustomer.name + '\n' +
                      'Type: ' + (currentCustomer.is_walk_in ? 'Walk-in Customer' : 'Registered Customer') + '\n\n' +
                      'Customer selection functionality will be enhanced in the next update.');
            });

            // Checkout with loading state
            document.getElementById('checkoutBtn').addEventListener('click', function() {
                if (cart.length === 0) return;

                const checkoutBtn = this;
                const originalContent = checkoutBtn.innerHTML;
                
                // Show loading state
                checkoutBtn.disabled = true;
                checkoutBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                checkoutBtn.style.opacity = '0.7';

                // Store cart data for checkout process
                const cartData = {
                    items: cart,
                    customer: currentCustomer,
                    total: calculateCartTotal()
                };

                // Debug: Log cart data structure
                console.log('Cart data being sent:', cartData);

                // Store in session for checkout page
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=store_cart&cart_data=' + encodeURIComponent(JSON.stringify(cartData))
                })
                .then(() => {
                    // Show success feedback before redirect
                    checkoutBtn.innerHTML = '<i class="bi bi-check-circle"></i> Redirecting...';
                    checkoutBtn.style.background = '#10b981';
                    
                    setTimeout(() => {
                        window.location.href = 'checkout.php';
                    }, 500);
                })
                .catch(error => {
                    console.error('Error:', error);
                    
                    // Reset button state
                    checkoutBtn.disabled = false;
                    checkoutBtn.innerHTML = originalContent;
                    checkoutBtn.style.opacity = '1';
                    checkoutBtn.style.background = '';
                    
                    // Show error feedback
                    showNotification('Error preparing checkout. Please try again.', 'error');
                });
            });

            // Notification system
            function showNotification(message, type = 'info') {
                const notification = document.createElement('div');
                const bgColor = type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6';
                
                notification.innerHTML = `
                    <i class="bi bi-${type === 'error' ? 'exclamation-triangle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                    ${message}
                `;
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: ${bgColor};
                    color: white;
                    padding: 1rem 1.5rem;
                    border-radius: 8px;
                    font-weight: 500;
                    z-index: 10000;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    animation: slideIn 0.3s ease;
                    max-width: 300px;
                `;
                
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }

            // Add slideOut animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);

            // Helper function to calculate cart total
            function calculateCartTotal() {
                return cart.reduce((total, item) => total + (item.price * item.quantity), 0);
            }

            // Make functions global for onclick handlers
            window.removeFromCart = removeFromCart;
            window.updateCartQuantity = updateCartQuantity;
        });
    </script>
</body>
</html>
