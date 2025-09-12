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
$user_role = $_SESSION['role_name'] ?? 'User';

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Load role-based permissions for current user into $permissions to use with hasPermission()
$permissions = [];
$role_id = $_SESSION['role_id'] ?? 0;
if ($role_id) {
    try {
        $perm_stmt = $conn->prepare("SELECT p.name FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role_id = :role_id");
        $perm_stmt->execute([':role_id' => $role_id]);
        $permissions = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // If permissions can't be loaded, leave $permissions as empty array
        error_log('Could not load permissions for role_id ' . $role_id . ': ' . $e->getMessage());
        $permissions = [];
    }
}

// Get register tills
$stmt = $conn->query("
    SELECT * FROM register_tills
    WHERE is_active = 1
    ORDER BY till_name
");
$register_tills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if no tills exist
$no_tills_available = empty($register_tills);

// Check if user has selected a till for this session
$selected_till = null;
if (isset($_SESSION['selected_till_id'])) {
    $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['selected_till_id']]);
    $selected_till = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get tills available for switching (excludes current till)
$switch_tills = $register_tills;
if ($selected_till) {
    $switch_tills = array_filter($register_tills, function($till) use ($selected_till) {
        return $till['id'] != $selected_till['id'];
    });
}

// Handle till selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_till') {
    $till_id = $_POST['till_id'] ?? null;
    $opening_amount = floatval($_POST['opening_amount'] ?? 0);

    if ($till_id) {
        $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
        $stmt->execute([$till_id]);
        $till = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($till) {
            // Update till with opening amount, set status to opened, and assign to current user
            $stmt = $conn->prepare("UPDATE register_tills SET current_balance = ?, till_status = 'opened', current_user_id = ? WHERE id = ?");
            $stmt->execute([$opening_amount, $user_id, $till_id]);

            $_SESSION['selected_till_id'] = $till_id;
            $_SESSION['selected_till_name'] = $till['till_name'];
            $_SESSION['selected_till_code'] = $till['till_code'];
            $_SESSION['till_opening_amount'] = $opening_amount;
            $selected_till = $till;
            $selected_till['current_balance'] = $opening_amount;
            $selected_till['current_user_id'] = $user_id;

            // Show warning if till was previously used by another user
            if ($till['current_user_id'] && $till['current_user_id'] != $user_id) {
                $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $user_stmt->execute([$till['current_user_id']]);
                $previous_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                $_SESSION['success_message'] = "Till selected successfully. Note: This till was previously used by " . ($previous_user['username'] ?? 'another user') . ".";
            } else {
                $_SESSION['success_message'] = "Till selected successfully.";
            }
        }
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Function to check for held transactions
function hasHeldTransactions($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as held_count FROM held_transactions WHERE user_id = ? AND status = 'held'");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['held_count'] > 0;
    } catch (PDOException $e) {
        error_log("Error checking held transactions: " . $e->getMessage());
        return false;
    }
}

// Handle till switching
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_till') {
    $switch_till_id = intval($_POST['switch_till_id'] ?? 0);

    if ($switch_till_id > 0) {
        // Check if cart has active products
        if (!empty($cart)) {
            $_SESSION['error_message'] = "Cannot switch till with active products in cart. Please complete the current transaction or clear the cart first.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }

        // Check for held transactions
        if (hasHeldTransactions($conn, $user_id)) {
            $_SESSION['error_message'] = "Cannot switch till with held transactions. Please continue or void all held transactions first.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }

        // Prevent switching to the same till
        if ($selected_till && $switch_till_id == $selected_till['id']) {
            $_SESSION['error_message'] = "You cannot switch to the same till you are currently using.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }

        // Get the till to switch to
        $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
        $stmt->execute([$switch_till_id]);
        $switch_till = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($switch_till) {
            // Release current till first
            if ($selected_till) {
                $stmt = $conn->prepare("UPDATE register_tills SET current_user_id = NULL WHERE id = ?");
                $stmt->execute([$selected_till['id']]);
            }

            // Set new till status to opened and assign to current user
            $stmt = $conn->prepare("UPDATE register_tills SET till_status = 'opened', current_user_id = ? WHERE id = ?");
            $stmt->execute([$user_id, $switch_till['id']]);

            // Update session with new till
            $_SESSION['selected_till_id'] = $switch_till['id'];
            $_SESSION['selected_till_name'] = $switch_till['till_name'];
            $_SESSION['selected_till_code'] = $switch_till['till_code'];
            $_SESSION['till_opening_amount'] = $switch_till['current_balance'];

            // Show warning if till was previously used by another user
            if ($switch_till['current_user_id'] && $switch_till['current_user_id'] != $user_id) {
                $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $user_stmt->execute([$switch_till['current_user_id']]);
                $previous_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                $_SESSION['success_message'] = "Switched to till: " . $switch_till['till_name'] . ". Note: This till was previously used by " . ($previous_user['username'] ?? 'another user') . ".";
            } else {
                $_SESSION['success_message'] = "Switched to till: " . $switch_till['till_name'];
            }
        } else {
            $_SESSION['error_message'] = "Selected till not found or inactive.";
        }
    } else {
        $_SESSION['error_message'] = "Please select a till to switch to.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle till closing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_till') {
    // Check if cart has active products
    if (!empty($cart)) {
        $_SESSION['error_message'] = "Cannot close till with active products in cart. Please complete the current transaction or clear the cart first.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Check for held transactions
    if (hasHeldTransactions($conn, $user_id)) {
        $_SESSION['error_message'] = "Cannot close till with held transactions. Please continue or void all held transactions first.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
    $voucher_amount = floatval($_POST['voucher_amount'] ?? 0);
    $loyalty_points = floatval($_POST['loyalty_points'] ?? 0);
    $other_amount = floatval($_POST['other_amount'] ?? 0);
    $other_description = $_POST['other_description'] ?? '';
    $closing_notes = $_POST['closing_notes'] ?? '';
    $allow_exceed = isset($_POST['allow_exceed']) ? 1 : 0;

    if ($selected_till) {
        // Calculate total closing amount
        $total_closing = $cash_amount + $voucher_amount + $loyalty_points + $other_amount;

        // Check if closing exceeds balance (unless allowed)
        if (!$allow_exceed && $total_closing > $selected_till['current_balance']) {
            $_SESSION['error_message'] = "Closing amount exceeds till balance. Please check amounts or enable 'Allow Exceed' option.";
        } else {
            // Create till closing record
            $stmt = $conn->prepare("
                INSERT INTO till_closings (till_id, user_id, cash_amount, voucher_amount, loyalty_points, other_amount, other_description, total_amount, closing_notes, allow_exceed, closed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $selected_till['id'],
                $user_id,
                $cash_amount,
                $voucher_amount,
                $loyalty_points,
                $other_amount,
                $other_description,
                $total_closing,
                $closing_notes,
                $allow_exceed
            ]);

            // Reset till balance to 0, set status to closed, and release user assignment
            $stmt = $conn->prepare("UPDATE register_tills SET current_balance = 0, till_status = 'closed', current_user_id = NULL WHERE id = ?");
            $stmt->execute([$selected_till['id']]);

            // Clear till selection
            unset($_SESSION['selected_till_id']);
            unset($_SESSION['selected_till_name']);
            unset($_SESSION['selected_till_code']);
            unset($_SESSION['till_opening_amount']);
            $selected_till = null;

            $_SESSION['success_message'] = "Till closed successfully. You can now select a new till.";
        }
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle cash drop authentication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cash_drop_auth') {
    $auth_user_id = $_POST['user_id'] ?? '';
    $auth_password = $_POST['password'] ?? '';

    if ($auth_user_id && $auth_password) {
        // Verify user credentials
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$auth_user_id]);
        $auth_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($auth_user && password_verify($auth_password, $auth_user['password'])) {
            // Check if user has cash drop permission
            if ($auth_user['role'] === 'admin' || hasPermission('cash_drop', $permissions)) {
                $_SESSION['cash_drop_authenticated'] = true;
                $_SESSION['cash_drop_user_id'] = $auth_user['id'];
                $_SESSION['cash_drop_username'] = $auth_user['username'];
                $_SESSION['success_message'] = "Authentication successful. You can now proceed with cash drop.";
            } else {
                $_SESSION['error_message'] = "You don't have permission to perform cash drop operations.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid user ID or password.";
        }
    } else {
        $_SESSION['error_message'] = "Please enter both user ID and password.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle cash drop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cash_drop') {
    $notes = $_POST['notes'] ?? '';

    // Check if user is authenticated for cash drop
    if (!isset($_SESSION['cash_drop_authenticated']) || !$_SESSION['cash_drop_authenticated']) {
        $_SESSION['error_message'] = "You must authenticate before performing cash drop operations.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($selected_till) {
        // Get total sales amount for this till today
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(final_amount), 0) as total_sales
            FROM sales
            WHERE DATE(sale_date) = ? AND till_id = ?
        ");
        $stmt->execute([$today, $selected_till['id']]);
        $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_sales = floatval($sales_data['total_sales']);

        if ($total_sales > 0) {
            // Drop the total sales amount
            $stmt = $conn->prepare("
                INSERT INTO cash_drops (till_id, user_id, drop_amount, notes, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$selected_till['id'], $user_id, $total_sales, $notes]);

            // Update till balance
            $new_balance = $selected_till['current_balance'] - $total_sales;
            $stmt = $conn->prepare("UPDATE register_tills SET current_balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $selected_till['id']]);

            // Update session
            $selected_till['current_balance'] = $new_balance;
            $_SESSION['till_opening_amount'] = $new_balance;
            $_SESSION['success_message'] = "Cash drop processed successfully by " . $_SESSION['cash_drop_username'] . ". Dropped " . formatCurrency($total_sales, $settings) . " (total sales amount).";

            // Clear authentication after successful drop
            unset($_SESSION['cash_drop_authenticated']);
            unset($_SESSION['cash_drop_user_id']);
            unset($_SESSION['cash_drop_username']);
        } else {
            $_SESSION['error_message'] = "No sales found for today. Nothing to drop.";
        }
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle cash drop logout
if (isset($_GET['action']) && $_GET['action'] === 'logout_cash_drop') {
    unset($_SESSION['cash_drop_authenticated']);
    unset($_SESSION['cash_drop_user_id']);
    unset($_SESSION['cash_drop_username']);
    $_SESSION['success_message'] = "Logged out from cash drop operations.";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle till release on logout
if (isset($_GET['action']) && $_GET['action'] === 'release_till') {
    // Check if cart has active products
    if (!empty($cart)) {
        $_SESSION['error_message'] = "Cannot release till with active products in cart. Please complete the current transaction or clear the cart first.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Check for held transactions
    if (hasHeldTransactions($conn, $user_id)) {
        $_SESSION['error_message'] = "Cannot release till with held transactions. Please continue or void all held transactions first.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($selected_till) {
        $stmt = $conn->prepare("UPDATE register_tills SET current_user_id = NULL WHERE id = ?");
        $stmt->execute([$selected_till['id']]);
        $_SESSION['success_message'] = "Till released successfully.";
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get products for the POS interface
$stmt = $conn->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name
");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories
$stmt = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle new sale request
if (isset($_GET['new_sale']) && $_GET['new_sale'] === 'true') {
    // Clear the cart for new sale
    unset($_SESSION['cart']);
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cart_count = count($cart);
$subtotal = 0;
$tax_rate = $settings['tax_rate'] ?? 16.0;

// Calculate cart totals
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
$tax_amount = $subtotal * ($tax_rate / 100);
$total_amount = $subtotal + $tax_amount;
?>

<?php
// Determine if Dashboard button should be visible on Sales page based on menu assignment + permissions
$canSeeDashboard = false;
try {
    $roleId = $_SESSION['role_id'] ?? 0;
    if ($roleId) {
        $stmt = $conn->prepare("SELECT rma.is_visible
                                 FROM menu_sections ms
                                 LEFT JOIN role_menu_access rma ON ms.id = rma.menu_section_id AND rma.role_id = :role_id
                                 WHERE ms.section_key = 'dashboard' AND ms.is_active = 1
                                 LIMIT 1");
        $stmt->execute([':role_id' => $roleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $permOk = hasPermission('view_dashboard', $permissions)
               || hasPermission('view_reports', $permissions)
               || hasPermission('view_analytics', $permissions);
        $canSeeDashboard = $permOk && (!empty($row) && (int)($row['is_visible'] ?? 0) === 1);
    }
} catch (Exception $e) {
    // Fail closed (button hidden) on any error
    error_log('Dashboard visibility check failed: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced POS - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .pos-container {
            height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pos-sidebar {
            background: var(--sidebar-color);
            color: white;
            height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .pos-main {
            height: 80vh;
            overflow: hidden;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.5rem;
            padding: 0.5rem;
            flex: 1;
            overflow-y: auto;
            max-height: calc(80vh - 200px);
        }

        .product-card {
            background: white;
            border-radius: 6px;
            padding: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
            height: fit-content;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: var(--primary-color);
        }

        .product-card.selected {
            border-color: var(--primary-color);
            background: #f0f9ff;
        }

        .cart-container {
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: calc(120vh - 1rem);
            margin: 0.25rem;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .cart-container .cart-header {
            flex-shrink: 0;
        }

        .cart-container .cart-items {
            flex: 1;
            overflow-y: auto;
        }

        .cart-container .cart-totals {
            flex-shrink: 0;
        }

        .cart-container .cart-actions {
            flex-shrink: 0;
        }

        .cart-header {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            padding: 0.75rem;
            border-radius: 6px 6px 0 0;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
        }

        .cart-header h5 {
            color: white !important;
            font-weight: 700;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 0.75rem;
            padding-bottom: 20px;
            min-height: 60px;
            display: flex;
            flex-direction: column;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            min-height: 55px;
            flex-shrink: 0;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #ffffff 0%, #fafbfc 100%);
            border-radius: 6px;
            margin-bottom: 0.25rem;
            border: 1px solid #f1f5f9;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .cart-item:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-color: #e2e8f0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin: 0.25rem 0;
        }

        .cart-item .product-name {
            color: #000000 !important;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .cart-item .product-price {
            color: #000000 !important;
            font-size: 0.8rem;
        }

        .cart-item .product-number {
            color: #000000 !important;
            font-weight: bold;
            margin-right: 0.5rem;
        }

        .cart-item .product-sku {
            color: #374151 !important;
            font-size: 0.7rem;
            font-weight: 700;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            border: 1px solid #bae6fd;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            box-shadow: 0 1px 2px rgba(14, 165, 233, 0.1);
            margin-right: 0.75rem;
        }

        .cart-item .product-sku:hover {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #93c5fd;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(14, 165, 233, 0.2);
        }

        /* Customer Display Styling */
        .customer-display {
            color: #ffffff !important;
            font-weight: 600;
            font-size: 0.85rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            display: inline-block;
        }

        .customer-display:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        /* Void Cart Button Styling */
        .cart-header .btn-outline-danger {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.8);
            color: white !important;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .cart-header .btn-outline-danger:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .cart-header .btn-outline-danger:disabled {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.3);
            color: rgba(255, 255, 255, 0.5) !important;
            cursor: not-allowed;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-totals {
            padding: 0.5rem;
            border-top: 2px solid #e5e7eb;
            background: #f9fafb;
            flex-shrink: 0;
            position: absolute;
            bottom: 120px;
            left: 0;
            right: 0;
        }

        .cart-totals .fw-bold {
            color: #1f2937 !important;
            font-weight: 600 !important;
        }

        .cart-totals span {
            color: #374151 !important;
        }

        .cart-actions {
            padding: 0.5rem;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
        }

        .category-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            margin: 0.125rem;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }

        .category-btn:hover,
        .category-btn.active {
            background: white;
            color: var(--sidebar-color);
        }

        .search-box {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
        }

        .search-box::placeholder {
            color: rgba(255,255,255,0.7);
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            padding: 0.25rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .quantity-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1px solid #d1d5db;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quantity-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .quantity-display {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 2px solid #d1d5db;
            border-radius: 6px;
            padding: 0.25rem 0.5rem;
            min-width: 50px;
            text-align: center;
            font-weight: 700;
            color: #1f2937 !important;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .quantity-display:hover {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }

        .quantity-display:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2), 0 4px 12px rgba(59, 130, 246, 0.15);
            transform: translateY(-2px);
        }

        /* Quick Add Buttons */
        .quick-add-buttons {
            display: flex;
            justify-content: center;
            gap: 2px;
        }

        .quick-add-btn {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .quick-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .quick-add-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(59, 130, 246, 0.2);
        }

        .quantity-controls .btn-outline-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 2px solid #ef4444;
            border-radius: 6px;
            padding: 0.25rem;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
            color: #dc2626 !important;
            font-weight: 700;
        }

        .quantity-controls .btn-outline-danger:hover {
            background: linear-gradient(135deg, #fecaca 0%, #fef2f2 100%);
            border-color: #f87171;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }

        .quantity-controls .btn-outline-danger:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
        }

        /* Quantity Display Input */
        .quantity-display {
            background: #ffffff !important;
            border: 2px solid #e5e7eb !important;
            color: #1f2937 !important;
            font-weight: 700 !important;
            font-size: 0.9rem !important;
            text-align: center !important;
            border-radius: 6px !important;
            padding: 0.4rem !important;
            width: 60px !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            transition: all 0.3s ease !important;
        }

        .quantity-display:focus {
            outline: none !important;
            border-color: #3b82f6 !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2), 0 2px 6px rgba(59, 130, 246, 0.15) !important;
            transform: translateY(-1px) !important;
        }

        .quantity-display:hover {
            border-color: #9ca3af !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
        }

        .payment-btn {
            width: 100%;
            padding: 0.2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .top-bar {
            background: var(--primary-color);
            color: white;
            padding: 0.3rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .time-display {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
        }

        /* Network Status Indicator */
        .network-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .network-indicator {
            position: relative;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .network-indicator i {
            font-size: 1rem;
            color: #28a745;
            animation: networkBlink 2s infinite;
        }

        .network-status.offline .network-indicator i {
            color: #ffffff;
            animation: none;
        }

        .network-text {
            font-size: 0.75rem;
            font-weight: 500;
            color: #ffffff;
        }

        .network-status.offline .network-text {
            color: #ffffff;
        }

        @keyframes networkBlink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.3; }
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Logout Button Styling */
        .logout-btn {
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .logout-btn:active {
            transform: translateY(0);
            background: rgba(255, 255, 255, 0.15);
        }

        .avatar {
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.7rem;
        }

        /* Custom scrollbar styling */
        .product-grid::-webkit-scrollbar {
            width: 4px;
        }

        .product-grid::-webkit-scrollbar-track {
            background: transparent;
        }

        .product-grid::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 2px;
        }

        .product-grid::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.3);
        }

        /* Cart items scrollbar - more visible */
        .cart-items::-webkit-scrollbar {
            width: 6px;
        }

        .cart-items::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .cart-items::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .cart-items::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* For Firefox */
        .product-grid {
            scrollbar-width: thin;
            scrollbar-color: rgba(0,0,0,0.2) transparent;
        }

        .cart-items {
            scrollbar-width: thin;
            scrollbar-color: #c1c1c1 #f1f1f1;
        }

        /* Customer Modal Styling */
        .customer-list-container {
            max-height: 450px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .customer-list {
            max-height: 450px;
            overflow-y: auto;
            padding: 0.5rem;
        }

        /* Customer List Scrollbar */
        .customer-list::-webkit-scrollbar {
            width: 8px;
        }

        .customer-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .customer-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }

        .customer-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Customer Count Badge */
        #customerCountBadge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9) !important;
            color: #1e40af !important;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Customer Item Styling */
        .customer-item {
            border: 2px solid transparent;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .customer-item:hover {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }

        .customer-item.border-primary {
            border-color: #3b82f6 !important;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
        }

        .customer-name {
            font-weight: 700;
            color: #1f2937;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .customer-details {
            color: #6b7280;
            font-size: 0.9rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex align-items-center">
            <h3 class="mb-0 me-3 text-white fw-bold"><?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></h3>
            <?php if ($selected_till): ?>
            <div class="ms-3 d-flex align-items-center gap-2">
                <span class="badge bg-success">
                    <i class="bi bi-cash-register"></i> <?php echo htmlspecialchars($selected_till['till_name']); ?>
                </span>
                <span class="badge bg-success text-white">
                    <i class="bi bi-unlock"></i> Opened
                </span>
                <button type="button" class="btn btn-sm btn-primary till-action-btn" onclick="showSwitchTill()" title="Switch Till">
                    <i class="bi bi-arrow-repeat"></i> Switch Till
                </button>
                <button type="button" class="btn btn-sm btn-danger till-action-btn" onclick="showCloseTill()" title="Close Till">
                    <i class="bi bi-x-circle"></i> Close Till
                </button>
                <button type="button" class="btn btn-sm btn-secondary till-action-btn" onclick="releaseTill()" title="Release Till">
                    <i class="bi bi-person-dash"></i> Release Till
                </button>
                <?php if (hasPermission('cash_drop', $permissions) || $user_role === 'admin'): ?>
                <button type="button" class="btn btn-sm btn-warning till-action-btn" onclick="showCashDropAuth()" title="Cash Drop">
                    <i class="bi bi-cash-stack"></i> Cash Drop
                </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="ms-3 d-flex align-items-center gap-2">
                <div class="alert alert-warning mb-0 py-2 px-3 d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <span class="me-3">Please select a till to continue</span>
                    <?php if ($no_tills_available): ?>

                    <button type="button" class="btn btn-warning btn-sm" onclick="showNoTillsModal()">
                        <i class="bi bi-exclamation-triangle"></i> No Tills Available
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="showTillSelection()">
                        <i class="bi bi-cash-register"></i> Select Till
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Network Status Indicator -->
            <div class="network-status" id="networkStatus" title="Network Connection">
                <div class="network-indicator">
                    <i class="bi bi-wifi"></i>
                </div>
                <span class="network-text">Online</span>
            </div>

            <!-- Time Display -->
            <div class="time-display" id="currentTime"></div>

            <?php if (!empty($canSeeDashboard) && $canSeeDashboard): ?>
            <a href="../dashboard/dashboard.php" class="btn btn-outline-light btn-sm" title="Dashboard" aria-label="Open Dashboard">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <?php endif; ?>

            <!-- User Info -->
            <div class="user-info">
                <div class="avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($username); ?></div>

                    <small class="opacity-75">Cashier</small>
                </div>
            </div>

            <!-- Logout Button -->
            <button type="button" class="btn btn-outline-light btn-sm logout-btn" onclick="logout()" title="Logout">
                <i class="bi bi-box-arrow-right"></i> Logout
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error_message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="pos-container">
        <div class="row g-0 h-100">
            <!-- Left Sidebar - Products -->
            <div class="col-md-8">
            <div class="pos-main">
                    <!-- Search and Categories -->
                    <div class="p-2 bg-white border-bottom flex-shrink-0" style="margin-left: 30px;">
                        <div class="row g-2">
                            <!-- Search Column (60%) -->
                            <div class="col-md-7">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="productSearch" placeholder="Search products...">
                                </div>
                            </div>

                            <!-- Categories Column (40%) -->
                            <div class="col-md-5">
                                <div class="d-flex align-items-center">
                                    <span class="text-muted small me-2">
                                        <i class="bi bi-funnel me-1"></i>Filter by Category:
                                    </span>
                                    <div class="category-dropdown-container">
                                        <select class="form-select form-select-sm category-dropdown" id="categoryDropdown">
                                            <option value="all" selected>
                                                <i class="bi bi-grid-3x3-gap"></i> All Categories
                                            </option>
                                            <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Products Grid -->
                    <div class="product-grid" id="productGrid">
                        <?php foreach ($products as $product): ?>
                        <div class="product-card <?php echo !$selected_till ? 'disabled' : ''; ?>" data-product-id="<?php echo $product['id']; ?>" data-category-id="<?php echo $product['category_id']; ?>">
                            <div class="text-center">
                                <div class="mb-2">
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>"
                                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                                             class="img-fluid rounded" style="max-height: 80px;">
                        <?php else: ?>
                                        <div class="bg-light rounded d-flex align-items-center justify-content-center"
                                             style="height: 80px;">
                                            <i class="bi bi-box text-muted fs-1"></i>
                            </div>
                                    <?php endif; ?>
                            </div>
                                <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($product['category_name']); ?></p>
                                <div class="fw-bold text-success">
                                    <?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($product['price'], 2); ?>
                                </div>

                                <?php if ($product['quantity'] <= 0): ?>
                                <div class="badge bg-danger mt-1">Out of Stock</div>
                                <?php else: ?>
                                <!-- Quick Add Buttons -->
                                <div class="quick-add-buttons mt-2">
                                    <button class="btn btn-sm btn-outline-primary quick-add-btn me-1" data-quantity="1" title="Add 1">
                                        +1
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary quick-add-btn me-1" data-quantity="5" title="Add 5">
                                        +5
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary quick-add-btn" data-quantity="10" title="Add 10">
                                        +10
                                    </button>
                                </div>
                                <?php endif; ?>
                    </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                            </div>
                        </div>

            <!-- Right Sidebar - Cart -->
            <div class="col-md-4">
                <div class="pos-sidebar">
                    <div class="cart-container">
                        <!-- Cart Header -->
                        <div class="cart-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Cart (<span id="cartCount"><?php echo $cart_count; ?></span>)</h5>
                                <button class="btn btn-outline-danger btn-sm" onclick="voidCart()" <?php echo $cart_count == 0 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-x-circle"></i> Void Cart
                            </button>
                        </div>
                            <div class="mt-2">
                                <small class="customer-display" onclick="openCustomerModal()">
                                    <i class="bi bi-person me-1"></i>CUSTOMER: <span id="selectedCustomerName">Walk-in Customer</span>
                                    <i class="bi bi-chevron-down ms-1"></i>
                                </small>
                    </div>
    </div>

                        <!-- Cart Items -->
                        <div class="cart-items" id="cartItems">
                            <?php if (empty($cart)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-cart-x fs-1"></i>
                                    <p class="mt-2 mb-1">No items in cart</p>
                                    <small>Add products to get started</small>
                </div>
                            <?php else: ?>
                                <?php foreach ($cart as $index => $item): ?>
                                    <div class="cart-item" data-index="<?php echo $index; ?>">
                                        <div class="flex-grow-1 d-flex align-items-center">
                                            <span class="product-number"><?php echo $index + 1; ?>.</span>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <span class="product-sku">
                                                            <?php echo htmlspecialchars($item['sku']); ?>
                                                        </span>
                                                    <?php endif; ?>
                    </div>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="product-price">
                                                        <?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($item['price'], 2); ?> each
                                                    </small>
                </div>
                </div>
            </div>
                                        <div class="quantity-controls">
                                            <input type="number" class="quantity-display" value="<?php echo $item['quantity']; ?>"
                                                   min="1" max="999" data-index="<?php echo $index; ?>"
                                                   onchange="debouncedUpdateQuantityDirect(<?php echo $index; ?>, this.value)"
                                                   onkeypress="handleQuantityKeypress(event, <?php echo $index; ?>, this)"
                                                   oninput="filterQuantityInput(this)"
                                                   onpaste="setTimeout(() => filterQuantityInput(this), 10)">
                                            <button class="btn btn-outline-danger btn-sm ms-2" onclick="voidProduct(<?php echo $index; ?>)" title="Void Product">
                                                <i class="bi bi-x-circle"></i>
                            </button>
                        </div>
                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
    </div>

                        <!-- Cart Totals -->
                        <div class="cart-totals">
                            <div class="d-flex justify-content-between mb-0">
                                <span class="fw-bold small">Subtotal:</span>
                                <span class="fw-bold small" id="cartSubtotal"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($subtotal, 2); ?></span>
                </div>
                            <div class="d-flex justify-content-between mb-0">
                                <span class="fw-bold small">Tax (<?php echo $tax_rate; ?>%):</span>
                                <span class="fw-bold small" id="cartTax"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($tax_amount, 2); ?></span>
                    </div>
                            <hr class="my-0" style="margin: 0.1rem 0;">
                            <div class="d-flex justify-content-between fw-bold small text-primary">
                            <span>TOTAL:</span>
                                <span id="cartTotal"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> <?php echo number_format($total_amount, 2); ?></span>
                        </div>
                    </div>

                        <!-- Cart Actions -->
                        <div class="cart-actions">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <button class="btn btn-outline-warning w-100 btn-sm" onclick="holdTransaction()" <?php echo !$selected_till ? 'disabled' : ''; ?>>
                                        <i class="bi bi-pause-circle"></i> Hold
                                    </button>
                </div>
                                <div class="col-6">
                                    <button class="btn btn-outline-info w-100 btn-sm" onclick="loadHeldTransactions()">
                                        <i class="bi bi-clock-history"></i> Held
                            </button>
                        </div>
                    </div>
                            <button class="btn btn-success payment-btn" onclick="processPayment()" <?php echo ($cart_count == 0 || !$selected_till) ? 'disabled' : ''; ?>>
                                <i class="bi bi-credit-card"></i> Process Payment
                        </button>
                    </div>
                    </div>
                        </div>
            </div>
        </div>
    </div>

    <!-- Include Payment Modal -->
    <?php include 'payment_modal.php'; ?>

    <!-- Customer Selection Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="customerModalLabel">
                        <i class="bi bi-person me-2"></i>Select Customer
                        <span class="badge bg-light text-dark ms-2" id="customerCountBadge">0 customers</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Search Bar -->
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control" id="customerSearch" placeholder="Search by name, phone number, or email address...">
                        </div>
                    </div>

                    <!-- Customer List -->
                    <div class="customer-list-container">
                        <div class="customer-list" id="customerList">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading customers...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="selectCustomerBtn" disabled>Select Customer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/enhanced_payment.js"></script>
    <script>
        // POS Configuration
            window.POSConfig = {
            currencySymbol: '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>',
            companyName: '<?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?>',
            companyAddress: '<?php echo htmlspecialchars($settings['company_address'] ?? ''); ?>',
            taxRate: <?php echo $tax_rate; ?>
        };

        // Cart data
        window.cartData = <?php echo json_encode($cart); ?>;
        window.paymentTotals = {
            subtotal: <?php echo $subtotal; ?>,
            tax: <?php echo $tax_amount; ?>,
            total: <?php echo $total_amount; ?>
        };

        // Global error handler for unhandled Promise rejections
        window.addEventListener('unhandledrejection', function(event) {
            console.error('Unhandled promise rejection:', event.reason);
            // Prevent the default behavior (which would log to console)
            event.preventDefault();
        });

        // Global error handler for general errors
        window.addEventListener('error', function(event) {
            console.error('Global error:', event.error);
        });

        // Update time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            const dateString = now.toLocaleDateString([], {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
            document.getElementById('currentTime').textContent = `${timeString} ${dateString}`;
        }

        // Update time every second
        setInterval(updateTime, 1000);
        updateTime();

        // Pre-cache product data for better performance
        function cacheProductData() {
            if (!window.productCache) {
                window.productCache = {};
            }

            const productCards = document.querySelectorAll('.product-card[data-product-id]');
            productCards.forEach(card => {
                const productId = card.dataset.productId;
                if (!window.productCache[productId]) {
                    const nameEl = card.querySelector('h6');
                    const priceEl = card.querySelector('.fw-bold.text-success');
                    const skuEl = card.querySelector('.product-sku');
                    const categoryEl = card.querySelector('p');
                    const imgEl = card.querySelector('img');
                    const stockEl = card.querySelector('.badge.bg-danger');

                    if (nameEl && priceEl && categoryEl) {
                        window.productCache[productId] = {
                            name: nameEl.textContent,
                            price: parseFloat(priceEl.textContent.replace(/[^\d.-]/g, '')),
                            sku: skuEl?.textContent || '',
                            categoryName: categoryEl.textContent,
                            imageUrl: imgEl?.src || '',
                            isOutOfStock: !!stockEl
                        };
                    }
                }
            });
        }

        // Cache product data on page load
        cacheProductData();

        // Product search and filtering - optimized with debouncing
        let searchTimeout = null;
        document.getElementById('productSearch').addEventListener('input', function() {
            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Debounce search to improve performance
            searchTimeout = setTimeout(() => {
                const searchTerm = this.value.toLowerCase();
                const productCards = document.querySelectorAll('.product-card');

                // Use requestAnimationFrame for smooth UI updates
                requestAnimationFrame(() => {
                    productCards.forEach(card => {
                        const productName = card.querySelector('h6').textContent.toLowerCase();
                        const categoryName = card.querySelector('p').textContent.toLowerCase();

                        if (productName.includes(searchTerm) || categoryName.includes(searchTerm)) {
                            card.style.display = 'block';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }, 150); // 150ms debounce for search
        });


        // Product selection - optimized with targeted event delegation
        const productGrid = document.getElementById('productGrid');
        if (productGrid) {
            productGrid.addEventListener('click', function(event) {
                // Prevent event bubbling for better performance
                event.stopPropagation();

                const productCard = event.target.closest('.product-card');
                if (!productCard || !productCard.dataset.productId) {
                    return;
                }

                const productId = productCard.dataset.productId;

                // Check if it's a quick add button (quantity buttons)
                if (event.target.classList.contains('quick-add-btn')) {
                    const quantity = parseInt(event.target.dataset.quantity) || 1;
                    addToCart(productId, quantity);
                    return;
                }

                // Only handle clicks on the product card itself, not on buttons
                if (event.target.closest('.quick-add-buttons')) {
                    return; // Don't handle clicks on quick add buttons here
                }

                // Default: instant add to cart (only for product card clicks)
                if (productCard.contains(event.target)) {
                    addToCart(productId, 1); // Add 1 quantity instantly
                }
            });
        }

        // Track pending API calls to prevent duplicates
        const pendingApiCalls = new Set();

        // Async function to add item to cart
        async function addToCartAsync(productId, quantity, fallbackCart) {
            // Create unique key for this API call
            const callKey = `${productId}-${quantity}-${Date.now()}`;

            // Prevent duplicate calls for the same product and quantity
            if (pendingApiCalls.has(`${productId}-${quantity}`)) {
                console.log('Duplicate API call prevented');
                return;
            }

            pendingApiCalls.add(`${productId}-${quantity}`);

            try {
                const response = await fetch('add_to_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `product_id=${productId}&quantity=${quantity}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    // Server response successful, only update if cart data is different
                    if (JSON.stringify(data.cart) !== JSON.stringify(window.cartData)) {
                        window.cartData = data.cart;
                        window.paymentTotals = data.totals;
                        updateCartDisplay(data.cart);
                    }
                } else {
                    // Server error, revert to previous state
                    console.error('Server error:', data.error);
                    window.cartData = fallbackCart;
                    updateCartDisplay(fallbackCart);
                    alert('Error adding product to cart: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                // Revert the cart to previous state
                window.cartData = fallbackCart;
                updateCartDisplay(fallbackCart);
                alert('Error adding product to cart: ' + error.message);
            } finally {
                // Remove from pending calls
                pendingApiCalls.delete(`${productId}-${quantity}`);
            }
        }

        // Async function to update cart item
        async function updateCartItemAsync(index, change, fallbackCart) {
            try {
                const response = await fetch('update_cart_item.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `index=${index}&change=${change}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    // Server response successful, update with server data
                    updateCartDisplay(data.cart);
                } else {
                    // Server error, revert to previous state
                    console.error('Server error:', data.error);
                    updateCartDisplay(fallbackCart);
                    alert('Error updating quantity: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                // Revert the cart to previous state
                updateCartDisplay(fallbackCart);
                alert('Error updating quantity: ' + error.message);
            }
        }


        // Async function to clear cart
        async function clearCartAsync() {
            try {
                const response = await fetch('clear_cart.php', {
                    method: 'POST'
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    updateCartDisplay([]);
                } else {
                    alert('Error clearing cart: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error clearing cart: ' + error.message);
            }
        }

        // Async function to search customers
        async function searchCustomersAsync(search) {
            const customerList = document.getElementById('customerList');

            try {
                const response = await fetch(`../api/get_customers.php?search=${encodeURIComponent(search)}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('Customer API response:', data);

                if (data.success) {
                    displayCustomers(data.customers);
                } else {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-danger">
                            <i class="bi bi-exclamation-triangle fs-1"></i>
                            <p class="mt-2">Error loading customers: ${data.error || 'Unknown error'}</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Customer search error:', error);
                customerList.innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle fs-1"></i>
                        <p class="mt-2">Error loading customers</p>
                        <small>Please try again</small>
                    </div>
                `;
            }
        }

        // Add to cart function with instant UI update
        function addToCart(productId, quantityToAdd = 1) {
            // Use cached product data if available, otherwise query DOM
            let productData = window.productCache?.[productId];

            if (!productData) {
                // Find the clicked product card to get product data
                const productCard = document.querySelector(`[data-product-id="${productId}"]`);
                if (!productCard) {
                    console.error('Product card not found');
                    return;
                }

                // Extract product data from the card
                productData = {
                    name: productCard.querySelector('h6').textContent,
                    price: parseFloat(productCard.querySelector('.fw-bold.text-success').textContent.replace(/[^\d.-]/g, '')),
                    sku: productCard.querySelector('.product-sku')?.textContent || '',
                    categoryName: productCard.querySelector('p').textContent,
                    imageUrl: productCard.querySelector('img')?.src || '',
                    isOutOfStock: !!productCard.querySelector('.badge.bg-danger')
                };

                // Cache the product data
                if (!window.productCache) {
                    window.productCache = {};
                }
                window.productCache[productId] = productData;
            }

            // Check if product is out of stock
            if (productData.isOutOfStock) {
                alert('This product is out of stock');
                return;
            }

            // Create temporary cart item for instant display
            const tempCartItem = {
                id: productId,
                name: productData.name,
                price: productData.price,
                quantity: quantityToAdd,
                sku: productData.sku,
                category_name: productData.categoryName,
                image_url: productData.imageUrl
            };

            // Update cart instantly with temporary item
            const currentCart = window.cartData || [];
            const existingItemIndex = currentCart.findIndex(item => item.id == productId);

            let updatedCart;
            if (existingItemIndex >= 0) {
                // Item exists, increase quantity by the amount being added
                updatedCart = [...currentCart];
                updatedCart[existingItemIndex].quantity += quantityToAdd;
            } else {
                // New item, add to cart
                updatedCart = [...currentCart, tempCartItem];
            }

            // Store the optimistic cart state first
            window.cartData = updatedCart;

            // Update UI with requestAnimationFrame for better performance
            requestAnimationFrame(() => {
                debouncedUpdateCartDisplay(updatedCart);
            });

            // Send request to server in background with small delay to prevent rapid-fire requests
            setTimeout(() => {
                addToCartAsync(productId, quantityToAdd, currentCart);
            }, 50);
        }

        // Cache DOM elements for better performance
        let cartElements = null;

        function getCartElements() {
            if (!cartElements) {
                cartElements = {
                    cartItems: document.getElementById('cartItems'),
                    cartCount: document.getElementById('cartCount'),
                    cartSubtotal: document.getElementById('cartSubtotal'),
                    cartTax: document.getElementById('cartTax'),
                    cartTotal: document.getElementById('cartTotal'),
                    paymentBtn: document.querySelector('.payment-btn'),
                    voidCartBtn: document.querySelector('button[onclick="voidCart()"]')
                };
            }
            return cartElements;
        }

        // Debounce function to prevent excessive calls
        let updateCartTimeout = null;
        let updateCartFrame = null;

        function debouncedUpdateCartDisplay(cart) {
            if (updateCartTimeout) {
                clearTimeout(updateCartTimeout);
            }
            if (updateCartFrame) {
                cancelAnimationFrame(updateCartFrame);
            }

            updateCartTimeout = setTimeout(() => {
                updateCartFrame = requestAnimationFrame(() => {
                    updateCartDisplay(cart);
                });
            }, 10); // 10ms debounce
        }

        // Update cart display - optimized version
        function updateCartDisplay(cart) {
            const elements = getCartElements();

            // Check if required elements exist
            if (!elements.cartItems || !elements.cartCount || !elements.cartSubtotal || !elements.cartTax || !elements.cartTotal) {
                console.error('Required cart display elements not found');
                return;
            }

            // Update cart count
            elements.cartCount.textContent = cart.length;

            // Update totals
            let subtotal = 0;
            cart.forEach(item => {
                subtotal += item.price * item.quantity;
            });

            // Ensure POSConfig exists
            const taxRate = window.POSConfig?.taxRate || 16;
            const currencySymbol = window.POSConfig?.currencySymbol || 'KES';

            const tax = subtotal * (taxRate / 100);
            const total = subtotal + tax;

            // Update totals display
            elements.cartSubtotal.textContent = `${currencySymbol} ${subtotal.toFixed(2)}`;
            elements.cartTax.textContent = `${currencySymbol} ${tax.toFixed(2)}`;
            elements.cartTotal.textContent = `${currencySymbol} ${total.toFixed(2)}`;

            // Ensure proper styling
            elements.cartSubtotal.className = 'fw-bold small';
            elements.cartTax.className = 'fw-bold small';
            elements.cartTotal.className = 'fw-bold small text-primary';

            // Update payment totals for payment processor
            window.paymentTotals = { subtotal, tax, total };
            window.cartData = cart;

            // Enable/disable buttons
            if (elements.paymentBtn) {
                elements.paymentBtn.disabled = cart.length === 0;
            }

            if (elements.voidCartBtn) {
                elements.voidCartBtn.disabled = cart.length === 0;
            }

            // Update cart items display efficiently
            updateCartItemsDisplay(cart, elements.cartItems);
        }

        // Optimized cart items display update
        function updateCartItemsDisplay(cart, cartItemsElement) {
            if (cart.length === 0) {
                cartItemsElement.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x fs-1"></i>
                        <p class="mt-2 mb-1">No items in cart</p>
                        <small>Add products to get started</small>
                    </div>
                `;
                return;
            }

            // Use DocumentFragment for better performance
            const fragment = document.createDocumentFragment();

            cart.forEach((item, index) => {
                const cartItemDiv = document.createElement('div');
                cartItemDiv.className = 'cart-item';
                cartItemDiv.setAttribute('data-index', index);

                const currencySymbol = window.POSConfig?.currencySymbol || 'KES';

                cartItemDiv.innerHTML = `
                    <div class="flex-grow-1 d-flex align-items-center">
                        <span class="product-number">${index + 1}.</span>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="product-name">${item.name}</div>
                                ${item.sku ? `<span class="product-sku">${item.sku}</span>` : ''}
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="product-price">
                                    ${currencySymbol} ${item.price.toFixed(2)} each
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="quantity-controls">
                        <input type="number" class="quantity-display" value="${item.quantity}"
                               min="1" max="999" data-index="${index}"
                               onchange="debouncedUpdateQuantityDirect(${index}, this.value)"
                               onkeypress="handleQuantityKeypress(event, ${index}, this)"
                               oninput="filterQuantityInput(this)"
                               onpaste="setTimeout(() => filterQuantityInput(this), 10)">
                        <button class="btn btn-outline-danger btn-sm ms-2" onclick="voidProduct(${index})" title="Void Product">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </div>
                `;

                fragment.appendChild(cartItemDiv);
            });

            // Single DOM update
            cartItemsElement.innerHTML = '';
            cartItemsElement.appendChild(fragment);
        }


        // Debounced quantity update to prevent excessive API calls
        let quantityUpdateTimeout = null;
        function debouncedUpdateQuantityDirect(index, newQuantity) {
            if (quantityUpdateTimeout) {
                clearTimeout(quantityUpdateTimeout);
            }
            quantityUpdateTimeout = setTimeout(() => {
                updateQuantityDirect(index, newQuantity);
            }, 300); // 300ms debounce for quantity updates
        }

        // Update quantity directly from input
        function updateQuantityDirect(index, newQuantity) {
            // Sanitize input
            const sanitizedInput = sanitizeQuantityInput(newQuantity);

            if (sanitizedInput === null) {
                // Reset to current quantity if invalid
                const currentQuantity = window.cartData[index] ? window.cartData[index].quantity : 1;
                document.querySelector(`[data-index="${index}"] .quantity-display`).value = currentQuantity;
                return;
            }

            // Update UI instantly
            const currentCart = window.cartData || [];
            if (currentCart[index]) {
                const updatedCart = [...currentCart];
                const newQuantityValue = sanitizedInput;

                if (newQuantityValue <= 0) {
                    // Remove item if quantity becomes 0 or negative
                    updatedCart.splice(index, 1);
                } else {
                    updatedCart[index].quantity = newQuantityValue;
                }

                // Update UI instantly
                updateCartDisplay(updatedCart);
            }

            // Calculate the change needed for server sync
            const currentQuantity = currentCart[index] ? currentCart[index].quantity : 1;
            const change = sanitizedInput - currentQuantity;

            if (change !== 0) {
                // Send request to server in background
                updateCartItemAsync(index, change, currentCart);
            }
        }

        // Sanitize quantity input
        function sanitizeQuantityInput(input) {
            // Remove any non-numeric characters except minus sign
            let cleaned = String(input).replace(/[^0-9-]/g, '');

            // Remove leading zeros
            cleaned = cleaned.replace(/^0+/, '');

            // Handle empty string
            if (cleaned === '' || cleaned === '-') {
                return null;
            }

            // Convert to integer
            const quantity = parseInt(cleaned, 10);

            // Validate range
            if (isNaN(quantity) || quantity < 1 || quantity > 999) {
                return null;
            }

            return quantity;
        }

        // Handle keypress events for quantity input
        function handleQuantityKeypress(event, index, inputElement) {
            // Allow only numeric characters, backspace, delete, arrow keys, and Enter
            const allowedKeys = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Enter', 'Tab'];
            const isNumeric = /^[0-9]$/.test(event.key);

            if (!isNumeric && !allowedKeys.includes(event.key)) {
                event.preventDefault();
                return false;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                inputElement.blur(); // This will trigger onchange
            }
        }

        // Real-time input filtering
        function filterQuantityInput(inputElement) {
            const originalValue = inputElement.value;
            const sanitized = sanitizeQuantityInput(originalValue);

            if (sanitized === null && originalValue !== '') {
                // If input is invalid, revert to previous valid value
                const currentQuantity = window.cartData[inputElement.dataset.index] ?
                    window.cartData[inputElement.dataset.index].quantity : 1;
                inputElement.value = currentQuantity;
            } else if (sanitized !== null) {
                inputElement.value = sanitized;
            }
        }


        // Clear cart
        function clearCart() {
            if (confirm('Are you sure you want to clear the cart?')) {
                clearCartAsync();
            }
        }

        // Process payment
        function processPayment() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            if (window.cartData.length === 0) {
                alert('Cart is empty');
                return;
            }

            // Set loading state
            setButtonLoading(button, 'Processing...', true);

            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before proceeding to payment');
            return;
            <?php endif; ?>

            // Refresh cart data in payment processor before showing modal
            if (window.paymentProcessor) {
                window.paymentProcessor.refreshCartData();
            }

            // Show payment modal
            const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
            paymentModal.show();

            // Reset button state after modal is shown
            setButtonLoading(button, 'Processing...', false);
        }

        // Hold transaction
        function holdTransaction() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            if (window.cartData.length === 0) {
                alert('Cart is empty');
                return;
            }

            // Set loading state
            setButtonLoading(button, 'Holding...', true);

            // Show hold reason dialog
            const holdReason = prompt('Enter reason for holding this transaction (required):');
            if (!holdReason || holdReason.trim() === '') {
                setButtonLoading(button, 'Holding...', false);
                alert('Hold reason is required.');
                return;
            }

            const customerReference = prompt('Enter customer reference (optional):') || '';

            if (!confirm(`Hold this transaction?\n\nReason: ${holdReason}\nCustomer: ${customerReference || 'N/A'}\n\nYou can retrieve it later from the "Held" button.`)) {
                setButtonLoading(button, 'Holding...', false);
                return;
            }

            // Call hold transaction API
            fetch('hold_transaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    reason: holdReason,
                    customer_reference: customerReference
                })
            })
            .then(response => response.json())
            .then(data => {
                // Reset button state
                setButtonLoading(button, 'Holding...', false);

                if (data.success) {
                    // Update cart display
                    window.cartData = data.cart;
                    window.paymentTotals = data.totals;
                    updateCartDisplay(data.cart);
                    alert('Transaction held successfully!');
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                // Reset button state on error
                setButtonLoading(button, 'Holding...', false);
                console.error('Error:', error);
                alert('Error holding transaction. Please try again.');
            });
        }

        // Load held transactions
        function loadHeldTransactions() {
            const modalElement = document.getElementById('heldTransactionsModal');
            if (!modalElement) {
                console.error('Held transactions modal element not found');
                alert('Held transactions modal not found. Please refresh the page.');
                return;
            }

            // Show held transactions modal
            const heldModal = new bootstrap.Modal(modalElement);
            heldModal.show();

            // Load held transactions data after modal is shown
            setTimeout(() => {
                loadHeldTransactionsData();
            }, 100);
        }

        // Customer Selection Functions
        let selectedCustomerId = null;
        let selectedCustomerData = null;

        function openCustomerModal() {
            const customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
            customerModal.show();

            // Clear search and reset selection
            document.getElementById('customerSearch').value = '';
            selectedCustomerId = null;
            selectedCustomerData = null;
            document.getElementById('selectCustomerBtn').disabled = true;

            // Reset customer count badge
            document.getElementById('customerCountBadge').textContent = '0 customers';

            loadCustomers();
        }

        function loadCustomers(search = '') {
            const customerList = document.getElementById('customerList');
            customerList.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading customers...</p>
                </div>
            `;

            // Use async/await for customer search
            searchCustomersAsync(search);
        }

        function displayCustomers(customers) {
            const customerList = document.getElementById('customerList');
            const customerCountBadge = document.getElementById('customerCountBadge');
            const searchTerm = document.getElementById('customerSearch').value.trim();

            // Update customer count badge
            const customerCount = customers.length;
            const countText = customerCount === 1 ? '1 customer' : `${customerCount} customers`;
            customerCountBadge.textContent = countText;

            if (customers.length === 0) {
                if (searchTerm) {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-search fs-1"></i>
                            <p class="mt-2">No customers found for "${searchTerm}"</p>
                            <small>Try searching by name, phone number, or email address</small>
                        </div>
                    `;
                } else {
                    customerList.innerHTML = `
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-person-x fs-1"></i>
                            <p class="mt-2">No customers found</p>
                            <small>No active customers in the database</small>
                        </div>
                    `;
                }
                return;
            }

            let customersHtml = '';

            // Add results count header for search results
            if (searchTerm) {
                customersHtml += `
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Found ${customerCount} customer${customerCount === 1 ? '' : 's'} matching "${searchTerm}"
                    </div>
                `;
            }

            customers.forEach(customer => {
                const isSelected = selectedCustomerId === customer.id;
                const customerTypeClass = customer.customer_type === 'walk_in' ? 'text-muted' :
                                        customer.customer_type === 'vip' ? 'text-warning' :
                                        customer.customer_type === 'business' ? 'text-info' : 'text-dark';

                customersHtml += `
                    <div class="customer-item ${isSelected ? 'border-primary' : ''}"
                         onclick="selectCustomer(${customer.id}, '${customer.display_name}', ${JSON.stringify(customer).replace(/"/g, '&quot;')})">
                        <div class="customer-name ${customerTypeClass}">
                            ${customer.display_name}
                            ${customer.customer_type === 'vip' ? '<i class="bi bi-star-fill text-warning ms-1"></i>' : ''}
                            ${customer.tax_exempt ? '<i class="bi bi-shield-check text-success ms-1"></i>' : ''}
                        </div>
                        <div class="customer-details">
                            ${customer.customer_number} • ${customer.customer_type}
                            ${customer.membership_level ? ' • ' + customer.membership_level : ''}
                        </div>
                        ${isSelected ? '<div class="text-end mt-2"><i class="bi bi-check-circle-fill text-primary"></i></div>' : ''}
                    </div>
                `;
            });

            customerList.innerHTML = customersHtml;
        }

        function selectCustomer(customerId, customerName, customerData) {
            selectedCustomerId = customerId;
            selectedCustomerData = customerData;

            // Update UI
            document.getElementById('selectedCustomerName').textContent = customerName;
            document.getElementById('selectCustomerBtn').disabled = false;

            // Update visual selection
            document.querySelectorAll('.customer-item').forEach(item => {
                item.classList.remove('border-primary');
            });
            event.currentTarget.classList.add('border-primary');
        }

        function confirmCustomerSelection() {
            if (selectedCustomerId && selectedCustomerData) {
                // Show confirmation dialog
                const customerName = selectedCustomerData.display_name;
                const customerType = selectedCustomerData.customer_type;
                const customerNumber = selectedCustomerData.customer_number;

                const confirmMessage = `Are you sure you want to select this customer?\n\n` +
                                    `Name: ${customerName}\n` +
                                    `Type: ${customerType}\n` +
                                    `Number: ${customerNumber}`;

                if (confirm(confirmMessage)) {
                    // Update global customer data
                    window.selectedCustomer = selectedCustomerData;

                    // Close modal
                    const customerModal = bootstrap.Modal.getInstance(document.getElementById('customerModal'));
                    customerModal.hide();

                    // Update customer display
                    document.getElementById('selectedCustomerName').textContent = selectedCustomerData.display_name;

                    // Show success message
                    showCustomerSelectionSuccess(selectedCustomerData);

                    console.log('Customer selected:', selectedCustomerData);
                }
            }
        }

        function showCustomerSelectionSuccess(customerData) {
            // Create a temporary success message
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
            successDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            successDiv.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                <strong>Customer Selected:</strong> ${customerData.display_name}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;

            document.body.appendChild(successDiv);

            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (successDiv.parentNode) {
                    successDiv.parentNode.removeChild(successDiv);
                }
            }, 3000);
        }

        // Search functionality
        document.getElementById('customerSearch').addEventListener('input', function() {
            const searchTerm = this.value;
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                loadCustomers(searchTerm);
            }, 300);
        });

        // Event listeners
        document.getElementById('selectCustomerBtn').addEventListener('click', confirmCustomerSelection);

        // Till management functions
        function showTillSelection() {
            const modalElement = document.getElementById('tillSelectionModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function showNoTillsModal() {
            const modalElement = document.getElementById('noTillsModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function releaseTill() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            // Set loading state
            setButtonLoading(button, 'Checking...', true);

            // Check for held transactions before allowing till release
            checkHeldTransactionsBeforeTillOperation('release', button);
        }

        // Set button loading state
        function setButtonLoading(button, text, isLoading) {
            if (isLoading) {
                button.disabled = true;
                button.dataset.originalText = button.innerHTML;
                button.innerHTML = `<i class="bi bi-hourglass-split me-1"></i>${text}`;
                button.classList.add('disabled');
            } else {
                button.disabled = false;
                button.innerHTML = button.dataset.originalText || button.innerHTML;
                button.classList.remove('disabled');
                delete button.dataset.originalText;
            }
        }

        // Check for held transactions before till operations
        function checkHeldTransactionsBeforeTillOperation(operation, button) {
            fetch('get_held_transactions.php')
                .then(response => response.json())
                .then(data => {
                    // Reset button state
                    setButtonLoading(button, 'Checking...', false);

                    if (data.success && data.held_transactions && data.held_transactions.length > 0) {
                        const actionText = operation === 'switch' ? 'switch till' : operation === 'close' ? 'close till' : 'release till';
                        const heldCount = data.held_transactions.length;

                        alert(`Cannot ${actionText} with held transactions.\n\nYou have ${heldCount} held transaction${heldCount > 1 ? 's' : ''} that need to be processed first.\n\nPlease continue or void all held transactions before ${actionText}ing.`);
                        return;
                    }

                    // No held transactions, proceed with the operation
                    proceedWithTillOperation(operation);
                })
                .catch(error => {
                    // Reset button state on error
                    setButtonLoading(button, 'Checking...', false);
                    console.error('Error checking held transactions:', error);
                    alert('Error checking held transactions. Please try again.');
                });
        }

        // Proceed with till operation after validation
        function proceedWithTillOperation(operation) {
            switch(operation) {
                case 'switch':
                    const modalElement = document.getElementById('switchTillModal');
                    if (modalElement) {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = new bootstrap.Modal(modalElement);
                            modal.show();
                        } else {
                            // Fallback: show modal manually
                            modalElement.style.display = 'block';
                            modalElement.classList.add('show');
                            document.body.classList.add('modal-open');
                        }
                    } else {
                        console.error('Switch Till modal not found in DOM');
                        alert('Switch Till modal not found. Please refresh the page.');
                    }
                    break;
                case 'close':
                    const closeModalElement = document.getElementById('closeTillModal');
                    if (closeModalElement) {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = new bootstrap.Modal(closeModalElement);
                            modal.show();
                        } else {
                            // Fallback: show modal manually
                            closeModalElement.style.display = 'block';
                            closeModalElement.classList.add('show');
                            document.body.classList.add('modal-open');
                        }
                    } else {
                        console.error('Close Till modal not found in DOM');
                        alert('Close Till modal not found. Please refresh the page.');
                    }
                    break;
                case 'release':
                    if (confirm('Are you sure you want to release this till? Other cashiers will be able to use it.')) {
                        window.location.href = '?action=release_till';
                    }
                    break;
            }
        }

        function showCashDropAuth() {
            const modalElement = document.getElementById('cashDropAuthModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function showCashDrop() {
            const modalElement = document.getElementById('cashDropModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function showSwitchTill() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            // Set loading state
            setButtonLoading(button, 'Checking...', true);

            // Check for held transactions before allowing till switch
            checkHeldTransactionsBeforeTillOperation('switch', button);
        }

        function showCloseTill() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            // Set loading state
            setButtonLoading(button, 'Checking...', true);

            // Check for held transactions before allowing till close
            checkHeldTransactionsBeforeTillOperation('close', button);
        }

        function selectSwitchTill(tillId) {
            // Uncheck all radio buttons
            document.querySelectorAll('input[name="switch_till_id"]').forEach(radio => {
                radio.checked = false;
            });

            // Check the selected till
            document.getElementById('switch_till_' + tillId).checked = true;

            // Enable confirm button
            document.getElementById('confirmSwitchTill').disabled = false;

            // Add visual feedback
            document.querySelectorAll('.till-card').forEach(card => {
                card.classList.remove('border-primary', 'bg-light');
            });
            event.currentTarget.classList.add('border-primary', 'bg-light');
        }

        function selectTill(tillId) {
            // Uncheck all radio buttons
            document.querySelectorAll('input[name="till_id"]').forEach(radio => {
                radio.checked = false;
            });

            // Check the selected till
            document.getElementById('till_' + tillId).checked = true;

            // Enable confirm button
            document.getElementById('confirmTillSelection').disabled = false;

            // Add visual feedback
            document.querySelectorAll('.till-card').forEach(card => {
                card.classList.remove('border-primary', 'bg-light');
            });
            event.currentTarget.classList.add('border-primary', 'bg-light');
        }

        function validateTillSelection() {
            const tillSelected = document.querySelector('input[name="till_id"]:checked');
            const openingAmount = document.getElementById('opening_amount').value;
            const confirmBtn = document.getElementById('confirmTillSelection');

            confirmBtn.disabled = !(tillSelected && openingAmount && parseFloat(openingAmount) >= 0);
        }

        function updateCloseTillTotals() {
            const cashAmount = parseFloat(document.getElementById('cash_amount').value) || 0;
            const voucherAmount = parseFloat(document.getElementById('voucher_amount').value) || 0;
            const loyaltyAmount = parseFloat(document.getElementById('loyalty_points').value) || 0;
            const otherAmount = parseFloat(document.getElementById('other_amount').value) || 0;
            const tillBalance = <?php echo $selected_till['current_balance'] ?? 0; ?>;

            const totalClosing = cashAmount + voucherAmount + loyaltyAmount + otherAmount;
            const difference = totalClosing - tillBalance;

            document.getElementById('cash_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + cashAmount.toFixed(2);
            document.getElementById('voucher_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + voucherAmount.toFixed(2);
            document.getElementById('loyalty_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + loyaltyAmount.toFixed(2);
            document.getElementById('other_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + otherAmount.toFixed(2);
            document.getElementById('total_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + totalClosing.toFixed(2);

            const differenceElement = document.getElementById('difference_display');
            differenceElement.textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + difference.toFixed(2);

            if (difference > 0) {
                differenceElement.className = 'text-danger fw-bold';
            } else if (difference < 0) {
                differenceElement.className = 'text-warning fw-bold';
            } else {
                differenceElement.className = 'text-success fw-bold';
            }
        }

        // Category dropdown functionality
        function initializeCategoryDropdown() {
            const categoryDropdown = document.getElementById('categoryDropdown');
            const productCards = document.querySelectorAll('.product-card');

            if (!categoryDropdown) return;

            // Handle category selection
            categoryDropdown.addEventListener('change', function() {
                const selectedCategory = this.value;

                productCards.forEach(card => {
                    const categoryId = card.getAttribute('data-category-id');

                    if (selectedCategory === 'all' || categoryId === selectedCategory) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });

                // Update product count display
                const visibleProducts = document.querySelectorAll('.product-card[style*="block"], .product-card:not([style*="none"])').length;
                console.log(`Showing ${visibleProducts} products for category: ${selectedCategory}`);
            });
        }

        // Network status functionality
        function initializeNetworkStatus() {
            const networkStatus = document.getElementById('networkStatus');
            const networkText = networkStatus.querySelector('.network-text');

            if (!networkStatus) return;

            function updateNetworkStatus() {
                if (navigator.onLine) {
                    networkStatus.classList.remove('offline');
                    networkText.textContent = 'Online';
                    networkStatus.title = 'Network Connected';
                } else {
                    networkStatus.classList.add('offline');
                    networkText.textContent = 'Offline';
                    networkStatus.title = 'Network Disconnected';
                }
            }

            // Initial check
            updateNetworkStatus();

            // Listen for online/offline events
            window.addEventListener('online', updateNetworkStatus);
            window.addEventListener('offline', updateNetworkStatus);

            // Note: Periodic connectivity check disabled to avoid 404 errors
            // The browser's built-in online/offline events are sufficient for most use cases
        }

        // Logout functionality
        function initializeLogout() {
            // Logout function is already defined globally
            // This is just for consistency with other initializations
        }

        // Global logout function
        function logout() {
            if (confirm('Are you sure you want to logout? Any unsaved changes will be lost.')) {
                // Release till if selected
                if (typeof releaseTill === 'function') {
                    releaseTill();
                }

                // Redirect to logout
                window.location.href = '../logout.php';
            }
        }
    </script>

    <!-- No Tills Available Modal -->
    <div class="modal fade" id="noTillsModal" tabindex="-1" aria-labelledby="noTillsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="noTillsModalLabel">
                        <i class="bi bi-exclamation-triangle"></i> No Tills Available
                    </h5>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-4">
                        <i class="bi bi-cash-register" style="font-size: 4rem; color: #6c757d;"></i>
                    </div>
                    <h4 class="text-muted mb-3">No Tills Have Been Created</h4>
                    <p class="text-muted mb-4">
                        You need to create at least one till before you can start processing sales.
                        Please contact your administrator to set up tills for your POS system.
                    </p>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>What you can do:</strong>
                        <ul class="list-unstyled mt-2 mb-0">
                            <li>• Contact your system administrator</li>
                            <li>• Check if tills are properly configured</li>
                            <li>• Ensure you have the necessary permissions</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh Page
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Till Selection Modal -->
    <div class="modal fade" id="tillSelectionModal" tabindex="-1" aria-labelledby="tillSelectionModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="tillSelectionModalLabel">
                        <i class="bi bi-cash-register"></i> Select Till
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="select_till">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Please select a till and enter the opening amount to continue with sales operations. You can change this selection at any time.
                        </div>

                        <div class="row">
                            <?php foreach ($register_tills as $till): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100 till-card" onclick="selectTill(<?php echo $till['id']; ?>)">
                                    <div class="card-body text-center">
                                        <div class="till-icon mb-3">
                                            <i class="bi bi-cash-register" style="font-size: 2rem; color: #667eea;"></i>
                                        </div>
                                        <h5 class="card-title"><?php echo htmlspecialchars($till['till_name']); ?></h5>
                                        <p class="card-text">
                                            <strong>Code:</strong> <?php echo htmlspecialchars($till['till_code']); ?><br>
                                            <strong>Location:</strong> <?php echo htmlspecialchars($till['location'] ?? 'N/A'); ?><br>
                                            <strong>Status:</strong>
                                            <?php if (($till['till_status'] ?? 'closed') === 'opened'): ?>
                                                <?php if ($till['current_user_id']): ?>
                                                    <?php
                                                    // Get username of current user
                                                    $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                                                    $user_stmt->execute([$till['current_user_id']]);
                                                    $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                                                    ?>
                                                    <?php if ($till['current_user_id'] == $user_id): ?>
                                                    <span class="text-success fw-bold">
                                                        <i class="bi bi-person-check"></i> In Use (You)
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning fw-bold">
                                                        <i class="bi bi-person-x"></i> In Use (<?php echo htmlspecialchars($current_user['username'] ?? 'Unknown'); ?>)
                                                    </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                <span class="text-success fw-bold">
                                                    <i class="bi bi-unlock"></i> Available
                                                </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-secondary fw-bold">
                                                <i class="bi bi-lock"></i> Closed
                                            </span>
                                            <?php endif; ?>
                                        </p>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="till_id" value="<?php echo $till['id']; ?>" id="till_<?php echo $till['id']; ?>">
                                            <label class="form-check-label" for="till_<?php echo $till['id']; ?>">
                                                Select This Till
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-cash-coin"></i> Opening Amount
                                        </h6>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                            <input type="number" class="form-control" name="opening_amount" id="opening_amount"
                                                   step="0.01" min="0" placeholder="0.00" required>
                                        </div>
                                        <small class="text-muted">Enter the cash amount you're starting with in this till</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (empty($register_tills)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No Active Tills Available</h5>
                            <p class="text-muted">Please contact your administrator to set up active tills.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirmTillSelection" disabled>
                            <i class="bi bi-check-circle"></i> Confirm Selection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cash Drop Authentication Modal -->
    <div class="modal fade" id="cashDropAuthModal" tabindex="-1" aria-labelledby="cashDropAuthModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="cashDropAuthModalLabel">
                        <i class="bi bi-shield-lock"></i> Cash Drop Authentication
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="cash_drop_auth">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Please enter your credentials to proceed with cash drop operation.
                        </div>

                        <div class="mb-3">
                            <label for="auth_user_id" class="form-label">User ID</label>
                            <input type="text" class="form-control" name="user_id" id="auth_user_id"
                                   placeholder="Enter your user ID" autocomplete="username" required>
                        </div>

                        <div class="mb-3">
                            <label for="auth_password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="auth_password"
                                   placeholder="Enter your password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-shield-check"></i> Authenticate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cash Drop Modal -->
    <div class="modal fade" id="cashDropModal" tabindex="-1" aria-labelledby="cashDropModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="cashDropModalLabel">
                        <i class="bi bi-cash-stack"></i> Cash Drop
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="cash_drop">
                    <div class="modal-body">
                        <?php if (isset($_SESSION['cash_drop_authenticated']) && $_SESSION['cash_drop_authenticated']): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-shield-check"></i>
                            <strong>Authenticated as:</strong> <?php echo htmlspecialchars($_SESSION['cash_drop_username']); ?>
                            <a href="?action=logout_cash_drop" class="btn btn-sm btn-outline-danger ms-2">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            You must authenticate before proceeding with cash drop operations.
                        </div>
                        <?php endif; ?>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            This will drop the total amount of sales made today from the till. The amount is automatically calculated based on today's sales.
                        </div>

                        <?php
                        // Get total sales for today
                        $today = date('Y-m-d');
                        $stmt = $conn->prepare("
                            SELECT COALESCE(SUM(final_amount), 0) as total_sales
                            FROM sales
                            WHERE DATE(sale_date) = ? AND till_id = ?
                        ");
                        $stmt->execute([$today, $selected_till['id'] ?? 0]);
                        $sales_data = $stmt->fetch(PDO::FETCH_ASSOC);
                        $total_sales = floatval($sales_data['total_sales']);
                        ?>

                        <div class="card mb-3">
                            <div class="card-body text-center">
                                <h6 class="card-title">
                                    <i class="bi bi-calculator"></i> Today's Sales Summary
                                </h6>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Total Sales:</span>
                                            <strong class="text-success">
                                                <?php echo formatCurrency($total_sales, $settings); ?>
                                            </strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Status:</span>
                                            <strong class="text-info">
                                                <i class="bi bi-shield-check"></i> Till Active
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($total_sales > 0): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Drop Amount:</strong> <?php echo formatCurrency($total_sales, $settings); ?>
                            (Total sales for today)
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            No sales found for today. Nothing to drop.
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" id="notes" rows="3"
                                      placeholder="Enter any notes about this cash drop..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if (isset($_SESSION['cash_drop_authenticated']) && $_SESSION['cash_drop_authenticated']): ?>
                            <?php if ($total_sales > 0): ?>
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-cash-stack"></i> Drop <?php echo formatCurrency($total_sales, $settings); ?>
                            </button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-warning" disabled>
                                <i class="bi bi-cash-stack"></i> Nothing to Drop
                            </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="btn btn-warning" onclick="showCashDropAuth()">
                                <i class="bi bi-shield-lock"></i> Authenticate First
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Switch Till Modal -->
    <div class="modal fade" id="switchTillModal" tabindex="-1" aria-labelledby="switchTillModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="switchTillModalLabel">
                        <i class="bi bi-arrow-repeat"></i> Switch Till
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="switch_till">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Switch Till:</strong> Select a different till to switch to. Your current till will remain open.
                        </div>

                        <?php if (empty($switch_tills)): ?>
                        <div class="alert alert-warning text-center">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>No Other Tills Available</strong><br>
                            There are no other active tills to switch to.
                        </div>
                        <?php else: ?>
                        <div class="row">
                            <?php foreach ($switch_tills as $till): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card till-card" onclick="selectSwitchTill(<?php echo $till['id']; ?>)">
                                    <div class="card-body text-center">
                                        <div class="till-icon mb-3">
                                            <i class="bi bi-cash-register" style="font-size: 2rem; color: #667eea;"></i>
                                        </div>
                                        <h5 class="card-title"><?php echo htmlspecialchars($till['till_name']); ?></h5>
                                        <p class="card-text">
                                            <strong>Code:</strong> <?php echo htmlspecialchars($till['till_code']); ?><br>
                                            <strong>Location:</strong> <?php echo htmlspecialchars($till['location'] ?? 'N/A'); ?><br>
                                            <strong>Status:</strong>
                                            <?php if (($till['till_status'] ?? 'closed') === 'opened'): ?>
                                                <?php if ($till['current_user_id']): ?>
                                                    <?php
                                                    // Get username of current user
                                                    $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                                                    $user_stmt->execute([$till['current_user_id']]);
                                                    $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                                                    ?>
                                                    <?php if ($till['current_user_id'] == $user_id): ?>
                                                    <span class="text-success fw-bold">
                                                        <i class="bi bi-person-check"></i> In Use (You)
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="text-warning fw-bold">
                                                        <i class="bi bi-person-x"></i> In Use (<?php echo htmlspecialchars($current_user['username'] ?? 'Unknown'); ?>)
                                                    </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                <span class="text-success fw-bold">
                                                    <i class="bi bi-unlock"></i> Available
                                                </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-secondary fw-bold">
                                                <i class="bi bi-lock"></i> Closed
                                            </span>
                                            <?php endif; ?>
                                        </p>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="switch_till_id" value="<?php echo $till['id']; ?>" id="switch_till_<?php echo $till['id']; ?>">
                                            <label class="form-check-label" for="switch_till_<?php echo $till['id']; ?>">
                                                Switch to This Till
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirmSwitchTill" <?php echo empty($switch_tills) ? 'disabled' : ''; ?>>
                            <i class="bi bi-arrow-repeat"></i> Switch Till
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Close Till Modal -->
    <div class="modal fade" id="closeTillModal" tabindex="-1" aria-labelledby="closeTillModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="closeTillModalLabel">
                        <i class="bi bi-x-circle"></i> Close Till
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="close_till">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Close Till:</strong> This will permanently close the current till and reset its balance to zero. This action cannot be undone.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">
                                    <i class="bi bi-cash-coin"></i> Payment Breakdown
                                </h6>

                                <div class="mb-3">
                                    <label for="cash_amount" class="form-label">Cash Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                        <input type="number" class="form-control" name="cash_amount" id="cash_amount"
                                               step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="voucher_amount" class="form-label">Voucher Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                        <input type="number" class="form-control" name="voucher_amount" id="voucher_amount"
                                               step="0.01" min="0" placeholder="0.00">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="loyalty_points" class="form-label">Loyalty Points (Value)</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                        <input type="number" class="form-control" name="loyalty_points" id="loyalty_points"
                                               step="0.01" min="0" placeholder="0.00">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="other_amount" class="form-label">Other Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                        <input type="number" class="form-control" name="other_amount" id="other_amount"
                                               step="0.01" min="0" placeholder="0.00">
                                    </div>
                                    <input type="text" class="form-control mt-2" name="other_description" id="other_description"
                                           placeholder="Description of other payment type">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <h6 class="mb-3">
                                    <i class="bi bi-calculator"></i> Summary
                                </h6>

                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Current Till Balance:</span>
                                            <strong><?php echo formatCurrency($selected_till['current_balance'] ?? 0, $settings); ?></strong>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Cash:</span>
                                            <span id="cash_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Voucher:</span>
                                            <span id="voucher_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Loyalty Points:</span>
                                            <span id="loyalty_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Other:</span>
                                            <span id="other_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between">
                                            <strong>Total Closing:</strong>
                                            <strong id="total_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mt-2">
                                            <span>Difference:</span>
                                            <span id="difference_display" class="text-muted"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" name="allow_exceed" id="allow_exceed" checked>
                                    <label class="form-check-label" for="allow_exceed">
                                        Allow closing amount to exceed till balance
                                    </label>
                                </div>

                                <div class="mb-3 mt-3">
                                    <label for="closing_notes" class="form-label">Closing Notes (Optional)</label>
                                    <textarea class="form-control" name="closing_notes" id="closing_notes" rows="3"
                                              placeholder="Enter any notes about this till closing..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i> Close Till
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Held Transactions Modal -->
    <div class="modal fade" id="heldTransactionsModal" tabindex="-1" aria-labelledby="heldTransactionsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="heldTransactionsModalLabel">
                        <i class="bi bi-clock-history me-2"></i>Held Transactions
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="tillFilter" class="form-label">Filter by Till:</label>
                            <select class="form-select" id="tillFilter" onchange="applyHeldTransactionFilters()">
                                <option value="all">All Tills</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="cashierFilter" class="form-label">Filter by Cashier:</label>
                            <select class="form-select" id="cashierFilter" onchange="applyHeldTransactionFilters()">
                                <option value="all">All Cashiers</option>
                            </select>
                        </div>
                    </div>

                    <!-- Held Transactions Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Cashier</th>
                                    <th>Till</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Reason</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="heldTransactionsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Loading held transactions...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="loadHeldTransactionsData()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Till management event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Till selection event listeners
            document.querySelectorAll('input[name="till_id"]').forEach(radio => {
                radio.addEventListener('change', validateTillSelection);
            });

            // Switch till event listeners
            document.querySelectorAll('input[name="switch_till_id"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('confirmSwitchTill').disabled = false;
                });
            });

            const openingAmountInput = document.getElementById('opening_amount');
            if (openingAmountInput) {
                openingAmountInput.addEventListener('input', validateTillSelection);
            }

            // Close till calculations event listeners
            const cashAmountInput = document.getElementById('cash_amount');
            const voucherAmountInput = document.getElementById('voucher_amount');
            const loyaltyAmountInput = document.getElementById('loyalty_points');
            const otherAmountInput = document.getElementById('other_amount');

            if (cashAmountInput) cashAmountInput.addEventListener('input', updateCloseTillTotals);
            if (voucherAmountInput) voucherAmountInput.addEventListener('input', updateCloseTillTotals);
            if (loyaltyAmountInput) loyaltyAmountInput.addEventListener('input', updateCloseTillTotals);
            if (otherAmountInput) otherAmountInput.addEventListener('input', updateCloseTillTotals);

            // Category dropdown functionality
            initializeCategoryDropdown();

            // Network status functionality
            initializeNetworkStatus();

            // Logout functionality
            initializeLogout();

            // Show appropriate modal on page load if no till is selected
            <?php if (!$selected_till): ?>
                <?php if ($no_tills_available): ?>
                showNoTillsModal();
                <?php else: ?>
                showTillSelection();
                <?php endif; ?>
            <?php endif; ?>
        });

        // Cart verification functions for till actions
        function verifyCartEmpty(action) {
            if (window.cartData && window.cartData.length > 0) {
                const actionText = action === 'switch' ? 'switch till' : action === 'close' ? 'close till' : 'release till';
                alert(`Cannot ${actionText} with active products in cart. Please complete the current transaction or clear the cart first.`);
                return false;
            }
            return true;
        }

        // These functions are now handled by the proper implementations above

        // Load held transactions data
        function loadHeldTransactionsData() {
            // Check if modal exists before making the request
            const modal = document.getElementById('heldTransactionsModal');
            if (!modal) {
                console.error('Held transactions modal not found');
                return;
            }

            fetch('get_held_transactions.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayHeldTransactions(data.held_transactions, data.filters);
                    } else {
                        alert('Error loading held transactions: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading held transactions. Please try again.');
                });
        }

        // Display held transactions in modal
        function displayHeldTransactions(transactions, filters) {
            const tbody = document.getElementById('heldTransactionsTableBody');
            const tillFilter = document.getElementById('tillFilter');
            const cashierFilter = document.getElementById('cashierFilter');

            // Check if required elements exist
            if (!tbody || !tillFilter || !cashierFilter) {
                console.error('Required DOM elements not found for held transactions display');
                return;
            }

            // Update filters
            if (filters && filters.tills) {
                tillFilter.innerHTML = '<option value="all">All Tills</option>';
                filters.tills.forEach(till => {
                    tillFilter.innerHTML += `<option value="${till.id}">${till.till_name}</option>`;
                });
            }

            if (filters && filters.cashiers) {
                cashierFilter.innerHTML = '<option value="all">All Cashiers</option>';
                filters.cashiers.forEach(cashier => {
                    cashierFilter.innerHTML += `<option value="${cashier.id}">${cashier.username}</option>`;
                });
            }

            // Display transactions
            if (!transactions || transactions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No held transactions found</td></tr>';
                return;
            }

            tbody.innerHTML = '';
            transactions.forEach(transaction => {
                const row = document.createElement('tr');
                const currencySymbol = window.POSConfig?.currencySymbol || 'KES';
                const total = transaction.totals?.total || 0;
                const reason = transaction.reason || 'No reason provided';

                row.innerHTML = `
                    <td>${transaction.id || 'N/A'}</td>
                    <td>${transaction.cashier_name || 'Unknown'}</td>
                    <td>${transaction.till_name || '<span class="text-muted">No Till</span>'}</td>
                    <td>${transaction.item_count || 0} items</td>
                    <td>${currencySymbol} ${total.toFixed(2)}</td>
                    <td>${reason.replace(/'/g, "&#39;")}</td>
                    <td>
                        <button class="btn btn-sm btn-success" onclick="continueHeldTransaction(${transaction.id})">
                            <i class="bi bi-play-circle"></i> Continue
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="voidHeldTransaction(${transaction.id}, '${reason.replace(/'/g, "&#39;")}')">
                            <i class="bi bi-x-circle"></i> Void
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Continue held transaction
        function continueHeldTransaction(heldTransactionId) {
            if (window.cartData.length > 0) {
                alert('Cart must be empty to continue with held transaction. Please clear the cart first.');
                return;
            }

            if (!confirm('Continue with this held transaction? This will load the items into your cart.')) {
                return;
            }

            fetch('continue_held_transaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    held_transaction_id: heldTransactionId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update cart display
                    window.cartData = data.cart;
                    window.paymentTotals = data.totals;
                    updateCartDisplay(data.cart);

                    // Close modal
                    const modalElement = document.getElementById('heldTransactionsModal');
                    if (modalElement) {
                        const heldModal = bootstrap.Modal.getInstance(modalElement);
                        if (heldModal) {
                            heldModal.hide();
                        }
                    }

                    alert('Held transaction loaded successfully!');
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error continuing held transaction. Please try again.');
            });
        }

        // Void held transaction
        function voidHeldTransaction(heldTransactionId, originalReason) {
            const voidReason = prompt(`Void Held Transaction #${heldTransactionId}\n\nOriginal Reason: ${originalReason}\n\nEnter void reason (required):`);
            if (!voidReason || voidReason.trim() === '') {
                alert('Void reason is required.');
                return;
            }

            if (!confirm(`Are you sure you want to void this held transaction?\n\nTransaction ID: ${heldTransactionId}\nOriginal Reason: ${originalReason}\nVoid Reason: ${voidReason}\n\nThis action will be recorded in the audit trail.`)) {
                return;
            }

            fetch('void_held_transaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    held_transaction_id: heldTransactionId,
                    void_reason: voidReason
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Held transaction voided successfully!');
                    // Reload held transactions
                    loadHeldTransactionsData();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error voiding held transaction. Please try again.');
            });
        }

        // Apply held transaction filters
        function applyHeldTransactionFilters() {
            const tillFilterElement = document.getElementById('tillFilter');
            const cashierFilterElement = document.getElementById('cashierFilter');

            if (!tillFilterElement || !cashierFilterElement) {
                console.error('Filter elements not found');
                return;
            }

            const tillFilter = tillFilterElement.value;
            const cashierFilter = cashierFilterElement.value;

            let url = 'get_held_transactions.php?';
            const params = [];

            if (tillFilter && tillFilter !== 'all') {
                params.push(`filter_till=${tillFilter}`);
            }

            if (cashierFilter && cashierFilter !== 'all') {
                params.push(`filter_cashier=${cashierFilter}`);
            }

            url += params.join('&');

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayHeldTransactions(data.held_transactions, data.filters);
                    } else {
                        alert('Error loading held transactions: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading held transactions. Please try again.');
                });
        }

        // Void product functionality
        function voidProduct(cartIndex) {
            if (!window.cartData || !window.cartData[cartIndex]) {
                alert('Invalid product selection.');
                return;
            }

            const product = window.cartData[cartIndex];

            // Show void reason dialog
            const voidReason = prompt(`Void Product: ${product.name}\n\nEnter void reason (required):`);
            if (!voidReason || voidReason.trim() === '') {
                alert('Void reason is required.');
                return;
            }

            if (!confirm(`Are you sure you want to void this product?\n\nProduct: ${product.name}\nQuantity: ${product.quantity}\nAmount: ${window.POSConfig?.currencySymbol || 'KES'} ${(product.price * product.quantity).toFixed(2)}\n\nThis action will be recorded in the audit trail.`)) {
                return;
            }

            // Call void product API
            fetch('void_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cart_index: cartIndex,
                    void_reason: voidReason.trim()
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Update cart display
                    window.cartData = data.cart;
                    window.paymentTotals = data.totals;
                    updateCartDisplay(data.cart);
                    alert('Product voided successfully.');
                } else {
                    alert('Error voiding product: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error voiding product: ' + error.message);
            });
        }

        // Void cart functionality
        function voidCart() {
            if (!window.cartData || window.cartData.length === 0) {
                alert('Cart is empty. Nothing to void.');
                return;
            }

            // Show void reason dialog
            const voidReason = prompt(`Void Entire Cart (${window.cartData.length} items)\n\nEnter void reason (required):`);
            if (!voidReason || voidReason.trim() === '') {
                alert('Void reason is required.');
                return;
            }

            // Calculate total amount
            let totalAmount = 0;
            window.cartData.forEach(item => {
                totalAmount += item.price * item.quantity;
            });

            if (!confirm(`Are you sure you want to void the entire cart?\n\nItems: ${window.cartData.length}\nTotal Amount: ${window.POSConfig?.currencySymbol || 'KES'} ${totalAmount.toFixed(2)}\n\nThis action will be recorded in the audit trail.`)) {
                return;
            }

            // Call void cart API
            fetch('void_cart.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    void_reason: voidReason.trim()
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Update cart display
                    window.cartData = data.cart;
                    window.paymentTotals = data.totals;
                    updateCartDisplay(data.cart);
                    alert(`Cart voided successfully.\n\nVoided ${data.voided_items} items\nTotal Amount: ${window.POSConfig?.currencySymbol || 'KES'} ${data.voided_amount.toFixed(2)}`);
                } else {
                    alert('Error voiding cart: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error voiding cart: ' + error.message);
            });
        }
    </script>

    <style>
        .till-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .till-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }

        .till-card.border-primary {
            border-color: #667eea !important;
            background-color: #f8f9ff !important;
        }


        .product-card.disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: not-allowed;
        }

        /* Category Dropdown Styles */
        .category-dropdown-container {
            flex: 1;
        }

        .category-dropdown {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .category-dropdown:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
            outline: none;
        }

        .category-dropdown:hover {
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0,123,255,0.15);
            transform: translateY(-1px);
        }

        .category-dropdown:active {
            transform: translateY(0);
        }

        /* Custom dropdown arrow styling */
        .category-dropdown {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }


        /* Responsive adjustments */
        @media (max-width: 768px) {
            .category-dropdown {
                font-size: 0.9rem;
            }

            /* Stack columns vertically on mobile */
            .col-md-7, .col-md-5 {
                margin-bottom: 8px;
            }
        }

        .product-card.disabled:hover {
            transform: none;
            box-shadow: none;
        }

        /* Till Action Buttons Styling */
        .till-action-btn {
            font-weight: 600;
            border: 2px solid transparent;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            transition: all 0.2s ease;
            min-width: 120px;
        }

        .till-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            border-color: rgba(255,255,255,0.3);
        }

        .till-action-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }

        .till-action-btn.btn-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            border-color: #007bff;
        }

        .till-action-btn.btn-primary:hover {
            background: linear-gradient(135deg, #0056b3, #004085);
            border-color: #0056b3;
        }

        .till-action-btn.btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-color: #dc3545;
        }

        .till-action-btn.btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            border-color: #c82333;
        }

        .till-action-btn.btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            border-color: #6c757d;
        }

        .till-action-btn.btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            border-color: #5a6268;
        }

        .till-action-btn.btn-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            border-color: #ffc107;
            color: #212529;
        }

        .till-action-btn.btn-warning:hover {
            background: linear-gradient(135deg, #e0a800, #d39e00);
            border-color: #e0a800;
            color: #212529;
        }

        .till-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            color: white;
        }
    </style>
</body>
</html>
