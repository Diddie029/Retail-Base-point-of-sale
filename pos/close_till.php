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

// Check if user has till close permission (case insensitive for admin)
$is_admin_user = (strtolower($role_name) === 'admin');
$can_close_till = hasPermission('close_till', $permissions) || $is_admin_user;

// Security check: Prevent unauthorized access to till closing functionality
if (isset($_GET['action']) && $_GET['action'] === 'close_till') {
    if (!isset($_SESSION['till_close_authenticated']) || !$_SESSION['till_close_authenticated']) {
        $_SESSION['error_message'] = "You must authenticate before accessing till closing operations.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle till closing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_till') {
    // Check if user is authenticated for till close operations
    if (!isset($_SESSION['till_close_authenticated']) || !$_SESSION['till_close_authenticated']) {
        $_SESSION['error_message'] = "You must authenticate before performing till close operations.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Additional security: Verify the authenticated user has permission
    if (!isset($_SESSION['till_close_user_id']) || !isset($_SESSION['till_close_username'])) {
        $_SESSION['error_message'] = "Authentication session is invalid. Please authenticate again.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Get selected till from session
    $selected_till_id = $_SESSION['selected_till_id'] ?? null;
    if (!$selected_till_id) {
        $_SESSION['error_message'] = "No till selected. Please select a till first.";
        header('Location: ../pos/sale.php');
        exit();
    }

    // Get selected till information
    $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ?");
    $stmt->execute([$selected_till_id]);
    $selected_till = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$selected_till) {
        $_SESSION['error_message'] = "Selected till not found.";
        header('Location: ../pos/sale.php');
        exit();
    }

    // Check if cart has active products
    $cart = $_SESSION['cart'] ?? [];
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

    // Validate confirmation step
    $confirm_close_till = $_POST['confirm_close_till'] ?? '';
    $confirm_balance_checked = isset($_POST['confirm_balance_checked']);

    if (strtoupper(trim($confirm_close_till)) !== 'CLOSE TILL') {
        $_SESSION['error_message'] = "Please type 'CLOSE TILL' exactly to confirm the action.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    if (!$confirm_balance_checked) {
        $_SESSION['error_message'] = "Please confirm that you have physically counted the cash in the till.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    $cash_amount = floatval($_POST['cash_amount'] ?? 0);
    $voucher_amount = floatval($_POST['voucher_amount'] ?? 0);
    $loyalty_points = floatval($_POST['loyalty_points'] ?? 0);
    $other_amount = floatval($_POST['other_amount'] ?? 0);
    $other_description = $_POST['other_description'] ?? '';
    $closing_notes = $_POST['closing_notes'] ?? '';
    $allow_exceed = 1; // Always allow by default

    if ($selected_till) {
        // Additional security check: Verify user has access to this specific till
        $stmt = $conn->prepare("
            SELECT rt.*, u.username as assigned_user_name
            FROM register_tills rt
            LEFT JOIN users u ON rt.assigned_user_id = u.id
            WHERE rt.id = ? AND (rt.assigned_user_id = ? OR rt.assigned_user_id IS NULL OR ? = 1)
        ");
        $stmt->execute([$selected_till['id'], $user_id, $is_admin_user ? 1 : 0]);
        $till_access = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$till_access) {
            $_SESSION['error_message'] = "You do not have access to close this till. Please contact your administrator.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }

        // Check if till is currently open and assigned to this user (or admin can close any till)
        if ($till_access['till_status'] === 'closed' && !$is_admin_user) {
            $_SESSION['error_message'] = "This till is already closed or not assigned to you.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }

        // Get opening amount from session
        $opening_amount = floatval($_SESSION['till_opening_amount'] ?? 0);

        // Get total sales for today for this cashier
        $today = date('Y-m-d');

        // Get total sales for the current till (all cashiers) for today using the new function
        $total_sales = getTillSalesTotal($conn, $selected_till['id'], $today);

        // Get total cash drops for today for this till (all cashiers) using the new function
        $total_drops = getTotalCashDrops($conn, $selected_till['id'], null, $today);

        // Calculate expected balance: opening + sales - drops
        $expected_balance = $opening_amount + $total_sales - $total_drops;

        // Calculate expected CASH balance (for cash reconciliation only)
        $expected_cash_balance = $opening_amount + $total_sales - $total_drops;

        // Calculate total closing amount
        $total_closing = $cash_amount + $voucher_amount + $loyalty_points + $other_amount;

        // Calculate difference between expected and actual
        $difference = $total_closing - $expected_balance;

        // Calculate CASH shortage (only compare cash amount with expected cash)
        $cash_difference = $cash_amount - $expected_cash_balance;
        $voucher_difference = $voucher_amount; // Vouchers should match exactly what's expected

        // Always allow closing regardless of amount difference
        // Determine shortage type and breakdown
        $shortage_type = 'exact';
        $cash_shortage = 0;
        $voucher_shortage = 0;
        $other_shortage = 0;

        if ($cash_difference < -0.01) {
            $cash_shortage = abs($cash_difference); // Cash is short
            $shortage_type = 'shortage';
        } elseif ($voucher_amount < 0) { // Negative vouchers indicate shortage
            $voucher_shortage = abs($voucher_amount);
            $shortage_type = 'voucher_shortage';
        } elseif ($other_amount < 0) { // Negative other indicates shortage
            $other_shortage = abs($other_amount);
            $shortage_type = 'other_shortage';
        } elseif ($difference > 0.01) {
            $shortage_type = 'excess'; // More money than expected
        }

        // Log the till closing action for audit trail
        error_log("Till Closing - User: $username (ID: $user_id) closing Till: {$selected_till['till_name']} (ID: {$selected_till['id']})");
        error_log("Till Closing - Opening: $opening_amount, Sales: $total_sales, Drops: $total_drops, Expected: $expected_balance, Expected Cash: $expected_cash_balance, Actual: $total_closing, Difference: $difference");
        error_log("Till Closing - Shortages - Cash: $cash_shortage, Voucher: $voucher_shortage, Other: $other_shortage, Type: $shortage_type");

        // Create till closing record with additional calculation details
        $stmt = $conn->prepare("
            INSERT INTO till_closings (till_id, user_id, opening_amount, total_sales, total_drops, expected_balance, expected_cash_balance, cash_amount, voucher_amount, loyalty_points, other_amount, other_description, actual_counted_amount, total_amount, difference, cash_shortage, voucher_shortage, other_shortage, shortage_type, closing_notes, allow_exceed, closed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $selected_till['id'],
            $user_id,
            $opening_amount,
            $total_sales,
            $total_drops,
            $expected_balance,
            $expected_cash_balance,
            $cash_amount,
            $voucher_amount,
            $loyalty_points,
            $other_amount,
            $other_description,
            $cash_amount, // actual_counted_amount (focusing on cash for now)
            $total_closing,
            $difference,
            $cash_shortage,
            $voucher_shortage,
            $other_shortage,
            $shortage_type,
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

        $success_message = "Till closed successfully. ";
        $success_message .= "Opening: " . formatCurrency($opening_amount, $settings) . ", ";
        $success_message .= "Sales: " . formatCurrency($total_sales, $settings) . ", ";
        $success_message .= "Drops: " . formatCurrency($total_drops, $settings) . ", ";
        $success_message .= "Expected: " . formatCurrency($expected_balance, $settings) . ", ";
        $success_message .= "Actual: " . formatCurrency($total_closing, $settings);

        // Show detailed shortage breakdown
        $shortage_details = [];
        if ($cash_shortage > 0) {
            $shortage_details[] = "Cash Shortage: " . formatCurrency($cash_shortage, $settings);
        }
        if ($voucher_shortage > 0) {
            $shortage_details[] = "Voucher Shortage: " . formatCurrency($voucher_shortage, $settings);
        }
        if ($other_shortage > 0) {
            $shortage_details[] = "Other Shortage: " . formatCurrency($other_shortage, $settings);
        }

        if (!empty($shortage_details)) {
            $success_message .= ", Shortages: " . implode(", ", $shortage_details);
        } elseif ($difference > 0.01) {
            $success_message .= ", Excess: " . formatCurrency($difference, $settings);
        }

        $success_message .= " <a href='#' onclick='showTillClosingSlipModal(); return false;' class='text-decoration-none'><i class='bi bi-printer'></i> Print slips manually if needed</a>";
        $_SESSION['success_message'] = $success_message;

        // Generate till closing slip
        $till_closing_slip_data = [
            'till_id' => $selected_till['id'],
            'till_name' => $selected_till['till_name'],
            'till_code' => $selected_till['till_code'],
            'cashier_name' => $username,
            'closing_user_name' => $_SESSION['till_close_username'] ?? $username,
            'closing_date' => date('Y-m-d H:i:s'),
            'opening_amount' => $opening_amount,
            'total_sales' => $total_sales,
            'total_drops' => $total_drops,
            'expected_balance' => $expected_balance,
            'expected_cash_balance' => $expected_cash_balance,
            'actual_cash' => $cash_amount,
            'voucher_amount' => $voucher_amount,
            'loyalty_points' => $loyalty_points,
            'other_amount' => $other_amount,
            'other_description' => $other_description,
            'total_closing' => $total_closing,
            'difference' => $difference,
            'cash_shortage' => $cash_shortage,
            'voucher_shortage' => $voucher_shortage,
            'other_shortage' => $other_shortage,
            'shortage_type' => $shortage_type,
            'closing_notes' => $closing_notes,
            'company_name' => $settings['company_name'] ?? 'Point of Sale System',
            'company_address' => $settings['company_address'] ?? '',
            'company_phone' => $settings['company_phone'] ?? '',
            'currency_symbol' => $settings['currency_symbol'] ?? 'KES'
        ];

        // Generate till closing slip HTML
        $till_closing_slip_html = generateTillClosingSlipHTML($till_closing_slip_data);

        // Store for printing
        $_SESSION['till_closing_slip_html'] = $till_closing_slip_html;
        $_SESSION['auto_print_till_closing'] = true;

        // Invalidate cashier session for security - require re-authentication on sale page
        if (!$is_admin_user) {
            // Set flag to require re-authentication on sale page
            $_SESSION['till_closure_occurred'] = true;
            $_SESSION['till_closure_timestamp'] = time();
            $_SESSION['till_closure_till_id'] = $selected_till['id'];

            // Log the session invalidation
            error_log("Till closure session invalidation triggered for user: $username (ID: $user_id) on Till: {$selected_till['till_name']}");

            // Optional: Clear sensitive session data
            unset($_SESSION['till_close_authenticated']);
            unset($_SESSION['till_close_user_id']);
            unset($_SESSION['till_close_username']);
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
        try {
            // Validate input
            $auth_user_id = trim($auth_user_id);
            $auth_password = trim($auth_password);

            if (empty($auth_user_id) || empty($auth_password)) {
                throw new Exception("Please enter both User ID/Employment ID and password.");
            }

            // Authenticate user
            $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ? OR id = ?");
            $stmt->execute([$auth_user_id, $auth_user_id]);
            $auth_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auth_user) {
                throw new Exception("User not found. Please check your User ID/Username and try again.");
            }

            if (!$auth_user || !password_verify($auth_password, $auth_user['password'])) {
                throw new Exception("Invalid password. Please check your password and try again.");
            }

            // Check if user has till close permission (case insensitive for admin)
            if (strtolower($auth_user['role']) === 'admin' || hasPermission('close_till', $permissions)) {
                $_SESSION['till_close_authenticated'] = true;
                $_SESSION['till_close_user_id'] = $auth_user['id'];
                $_SESSION['till_close_username'] = $auth_user['username'];

                $intended_action = $_POST['intended_action'] ?? 'close_till';
                $_SESSION['intended_action'] = $intended_action;

                $_SESSION['success_message'] = "Authentication successful. You can now proceed with till closing.";

                // Log successful authentication
                error_log("Till close authentication successful: User ID {$auth_user['id']} ({$auth_user['username']})");
            } else {
                throw new Exception("You don't have permission to close tills. Please contact your administrator.");
            }
        } catch (Exception $e) {
            // Log failed authentication attempts
            error_log("Till close authentication failed: " . $e->getMessage() . " | Attempted ID: " . $auth_user_id);
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Please enter both User ID/Employment ID and password.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle till close logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'till_close_logout') {
    // Clear till close authentication session
    unset($_SESSION['till_close_authenticated']);
    unset($_SESSION['till_close_user_id']);
    unset($_SESSION['till_close_username']);
    unset($_SESSION['intended_action']);

    $_SESSION['success_message'] = "Logged out from till closing operations.";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get user information
$selected_till_id = $_SESSION['selected_till_id'] ?? null;
$selected_till = null;

if ($selected_till_id) {
    $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ?");
    $stmt->execute([$selected_till_id]);
    $selected_till = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate totals if till is selected
$opening_amount = 0;
$total_sales = 0;
$total_drops = 0;
$expected_balance = 0;

if ($selected_till) {
    // Get opening amount from session
    $opening_amount = floatval($_SESSION['till_opening_amount'] ?? 0);

    // Get total sales for today
    $today = date('Y-m-d');
    $total_sales = getTillSalesTotal($conn, $selected_till['id'], $today);

    // Get total cash drops for today
    $total_drops = getTotalCashDrops($conn, $selected_till['id'], null, $today);

    // Calculate expected balance (total expected including all payment types)
    $expected_balance = $opening_amount + $total_sales - $total_drops;

    // Calculate expected CASH balance (excluding vouchers and loyalty points for cash reconciliation)
    $expected_cash_balance = $opening_amount + $total_sales - $total_drops;
}

// Security setting: Hide amounts to prevent theft (DEFAULT: HIDDEN)
$hide_amounts = $settings['hide_till_amounts'] ?? '1'; // Default: hide amounts
$can_view_amounts = hasPermission('view_till_amounts', $permissions) || $is_admin_user;
$show_amounts = ($hide_amounts === '1' && !$can_view_amounts) ? false : true;

// Header functionality not needed for standalone close till interface

// formatCurrency() function is already available in include/functions.php

// Helper function to format currency with theft prevention
function formatAmountSecure($amount, $settings, $show_amounts = true) {
    if (!$show_amounts) {
        return '***.**';
    }
    return formatCurrency($amount, $settings);
}

function hasHeldTransactions($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM held_transactions WHERE user_id = ? AND status = 'held'");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (PDOException $e) {
        error_log("Error checking held transactions: " . $e->getMessage());
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Till Closing - <?php echo $settings['company_name'] ?? 'Point of Sale System'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .till-closing-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: none;
            border-radius: 10px;
        }
        .card-header {
            background-color: #dc3545;
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #bd2130;
        }
        .currency-input {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .form-control:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        .summary-card {
            background-color: #e9ecef;
        }
        .total-row {
            border-top: 2px solid #dee2e6;
            padding-top: 10px;
        }
        .difference-alert {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid till-closing-container py-4">
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i>
                <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (!$selected_till): ?>
            <!-- No Till Selected -->
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="mb-0"><i class="bi bi-x-circle"></i> No Till Selected</h4>
                        </div>
                        <div class="card-body text-center py-5">
                            <i class="bi bi-cash-stack display-1 text-muted mb-4"></i>
                            <h5>No Till Selected</h5>
                            <p class="text-muted mb-4">You need to select a till before you can close it.</p>
                            <a href="../pos/sale.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left"></i> Go to POS
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php if (!$show_amounts): ?>
            <!-- Security Warning (DEFAULT: AMOUNTS HIDDEN) -->
            <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-shield-exclamation"></i>
                <strong>Security Mode Active:</strong> Monetary amounts are hidden by default to prevent theft. <?php echo $can_view_amounts ? 'Click "Show Amounts" to view values.' : 'Only authorized personnel can view amounts.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <!-- Till Closing Interface -->
            <div class="row">
                <div class="col-md-8">
                    <!-- Cash Flow Breakdown -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Cash Flow Breakdown</h5>
                            <?php if ($can_view_amounts && $hide_amounts === '1'): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleAmountsBtn">
                                <i class="bi bi-eye"></i> <?php echo $show_amounts ? 'Hide Amounts' : 'Show Amounts'; ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-cash-coin text-success me-2"></i>
                                            <span>Opening Amount</span>
                                        </div>
                                        <strong class="text-success" id="opening_amount_display">
                                            <?php echo formatAmountSecure($opening_amount, $settings, $show_amounts); ?>
                                        </strong>
                                    </div>

                                    <div class="d-flex justify-content-between mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-receipt text-primary me-2"></i>
                                            <span>Total Sales</span>
                                        </div>
                                        <strong class="text-primary" id="total_sales_display">
                                            <?php echo formatAmountSecure($total_sales, $settings, $show_amounts); ?>
                                        </strong>
                                    </div>

                                    <div class="d-flex justify-content-between mb-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-arrow-down-circle text-warning me-2"></i>
                                            <span>Cash Drops</span>
                                        </div>
                                        <strong class="text-warning" id="total_drops_display">
                                            -<?php echo formatAmountSecure($total_drops, $settings, $show_amounts); ?>
                                        </strong>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h6 class="card-title">Expected Total to Collect</h6>
                                            <div class="h3 text-primary mb-0" id="expected_total_display">
                                                <?php echo formatAmountSecure($expected_balance, $settings, $show_amounts); ?>
                                            </div>
                                            <small class="text-muted">
                                                Formula: <?php echo $show_amounts ? 'Opening + Sales - Drops' : '*** + *** - ***'; ?> = <?php echo formatAmountSecure($opening_amount, $settings, $show_amounts); ?> + <?php echo formatAmountSecure($total_sales, $settings, $show_amounts); ?> - <?php echo formatAmountSecure($total_drops, $settings, $show_amounts); ?> = <?php echo formatAmountSecure($expected_balance, $settings, $show_amounts); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Breakdown Form -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-calculator"></i> Payment Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="close_till">

                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="mb-3">Actual Counted Cash Amount</h6>

                                        <div class="mb-3">
                                            <label for="cash_amount" class="form-label">KES</label>
                                            <input type="number" step="0.01" class="form-control currency-input"
                                                   name="cash_amount" id="cash_amount" value="0.00"
                                                   placeholder="Enter actual cash amount counted" required>
                                            <div class="form-text">Enter the actual cash amount counted in the till</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="voucher_amount" class="form-label">Voucher Amount</label>
                                            <input type="number" step="0.01" class="form-control"
                                                   name="voucher_amount" id="voucher_amount" value="0.00"
                                                   placeholder="Enter voucher amount">
                                        </div>

                                        <div class="mb-3">
                                            <label for="loyalty_points" class="form-label">Loyalty Points (Value)</label>
                                            <input type="number" step="0.01" class="form-control"
                                                   name="loyalty_points" id="loyalty_points" value="0.00"
                                                   placeholder="Enter loyalty points value">
                                        </div>

                                        <div class="mb-3">
                                            <label for="other_amount" class="form-label">Other Amount</label>
                                            <input type="number" step="0.01" class="form-control"
                                                   name="other_amount" id="other_amount" value="0.00"
                                                   placeholder="Enter other payment amount">
                                        </div>

                                        <div class="mb-3">
                                            <label for="other_description" class="form-label">Description of other payment type</label>
                                            <input type="text" class="form-control"
                                                   name="other_description" id="other_description"
                                                   placeholder="e.g., Credit Card, Mobile Money, etc.">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="mb-3">Summary</h6>

                                        <div class="card summary-card">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Expected Balance:</span>
                                                    <strong class="text-info" id="expected_balance_display">
                                                        <?php echo formatAmountSecure($expected_balance, $settings, $show_amounts); ?>
                                                    </strong>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span>Actual Cash Counted:</span>
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
                                                <div class="d-flex justify-content-between mb-2">
                                                    <span><strong>Total Counted:</strong></span>
                                                    <strong id="total_counted_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</strong>
                                                </div>
                                                <hr>
                                                <div class="d-flex justify-content-between">
                                                    <strong>Total Closing:</strong>
                                                    <strong id="total_display"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</strong>
                                                </div>
                                                <div class="alert alert-sm mt-2 mb-0" id="difference_alert" style="display: none;">
                                                    <i class="bi bi-info-circle"></i>
                                                    <span id="difference_message"></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3 mt-3">
                                            <label for="closing_notes" class="form-label">Closing Notes (Optional)</label>
                                            <textarea class="form-control" name="closing_notes" id="closing_notes" rows="3"
                                                      placeholder="Enter any notes about this till closing..."></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- Security Confirmation -->
                                <div class="card mb-3">
                                    <div class="card-header bg-warning text-dark">
                                        <h6 class="mb-0">
                                            <i class="bi bi-shield-check"></i> Security Confirmation
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="confirm_balance_checked" name="confirm_balance_checked" required>
                                                <label class="form-check-label" for="confirm_balance_checked">
                                                    <strong>I confirm that I have physically counted the cash in the till and entered the correct amounts above.</strong>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="confirm_close_till" class="form-label">
                                                <strong>Type 'CLOSE TILL' to confirm:</strong>
                                            </label>
                                            <input type="text" class="form-control" name="confirm_close_till" id="confirm_close_till"
                                                   placeholder="Type 'CLOSE TILL' exactly" required>
                                            <div class="form-text">This action will permanently close the till and cannot be undone.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="../pos/sale.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Back to POS
                                    </a>
                                    <button type="submit" class="btn btn-danger btn-lg" id="closeTillBtn" disabled>
                                        <i class="bi bi-x-circle"></i> Close Till
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Till Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Till Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label"><strong>Till Name:</strong></label>
                                <div class="fw-bold"><?php echo htmlspecialchars($selected_till['till_name']); ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Till Code:</strong></label>
                                <div class="fw-bold"><?php echo htmlspecialchars($selected_till['till_code']); ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Current Status:</strong></label>
                                <div>
                                    <span class="badge bg-<?php echo $selected_till['till_status'] === 'open' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($selected_till['till_status']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label"><strong>Current Balance:</strong></label>
                                <div class="fw-bold text-success">
                                    <?php echo formatAmountSecure($selected_till['current_balance'], $settings, $show_amounts); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Instructions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-lightbulb"></i> Instructions</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!$show_amounts): ?>
                            <div class="alert alert-warning mb-3">
                                <h6><i class="bi bi-shield-check"></i> Security Mode Active</h6>
                                <p class="mb-0">Monetary amounts are hidden by default to prevent theft. <?php echo $can_view_amounts ? 'Click "Show Amounts" above to view values before closing.' : 'Only authorized personnel can view amounts.'; ?></p>
                            </div>
                            <?php endif; ?>

                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle"></i> How to Close Till:</h6>
                                <ol class="mb-0 mt-2">
                                    <li>Count all cash in the till drawer</li>
                                    <li>Enter the actual cash amount in the "KES" field</li>
                                    <li>Enter any voucher or other payment amounts</li>
                                    <li>Check the "I confirm..." checkbox</li>
                                    <li>Type "CLOSE TILL" exactly to confirm</li>
                                    <li>Click "Close Till" to complete</li>
                                </ol>
                            </div>

                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Important:</strong> This action cannot be undone. Make sure all amounts are entered correctly before closing.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Till closing form validation and calculations
        document.addEventListener('DOMContentLoaded', function() {
            const cashAmountInput = document.getElementById('cash_amount');
            const voucherAmountInput = document.getElementById('voucher_amount');
            const loyaltyAmountInput = document.getElementById('loyalty_points');
            const otherAmountInput = document.getElementById('other_amount');
            const confirmInput = document.getElementById('confirm_close_till');
            const balanceCheckbox = document.getElementById('confirm_balance_checked');
            const closeTillBtn = document.getElementById('closeTillBtn');
            const toggleAmountsBtn = document.getElementById('toggleAmountsBtn');

            // Currency symbol
            const currencySymbol = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>';

            // Security variables (DEFAULT: AMOUNTS HIDDEN)
            const hideAmounts = <?php echo $hide_amounts; ?>;
            const canViewAmounts = <?php echo $can_view_amounts ? 'true' : 'false'; ?>;
            let amountsVisible = <?php echo $show_amounts ? 'true' : 'false'; ?>; // Default: false (hidden)

            // Toggle amounts visibility (for authorized users only)
            function toggleAmountsVisibility() {
                if (!canViewAmounts || hideAmounts !== '1') return;

                amountsVisible = !amountsVisible;

                // Update button text and icon
                if (toggleAmountsBtn) {
                    toggleAmountsBtn.innerHTML = amountsVisible ?
                        '<i class="bi bi-eye-slash"></i> Hide Amounts' :
                        '<i class="bi bi-eye"></i> Show Amounts';
                }

                // Reload page to show/hide amounts
                location.reload();
            }

            // Set initial button text based on current visibility state
            if (toggleAmountsBtn && hideAmounts === '1' && canViewAmounts) {
                toggleAmountsBtn.innerHTML = amountsVisible ?
                    '<i class="bi bi-eye-slash"></i> Hide Amounts' :
                    '<i class="bi bi-eye"></i> Show Amounts';
            }

            // Add toggle button event listener
            if (toggleAmountsBtn) {
                toggleAmountsBtn.addEventListener('click', toggleAmountsVisibility);
            }

            function updateCalculations() {
                const cash = parseFloat(cashAmountInput?.value || 0);
                const voucher = parseFloat(voucherAmountInput?.value || 0);
                const loyalty = parseFloat(loyaltyAmountInput?.value || 0);
                const other = parseFloat(otherAmountInput?.value || 0);

                const totalCounted = cash + voucher + loyalty + other;

                // Update display values
                if (document.getElementById('cash_display')) {
                    document.getElementById('cash_display').textContent = currencySymbol + ' ' + cash.toFixed(2);
                }
                if (document.getElementById('voucher_display')) {
                    document.getElementById('voucher_display').textContent = currencySymbol + ' ' + voucher.toFixed(2);
                }
                if (document.getElementById('loyalty_display')) {
                    document.getElementById('loyalty_display').textContent = currencySymbol + ' ' + loyalty.toFixed(2);
                }
                if (document.getElementById('other_display')) {
                    document.getElementById('other_display').textContent = currencySymbol + ' ' + other.toFixed(2);
                }
                if (document.getElementById('total_counted_display')) {
                    document.getElementById('total_counted_display').textContent = currencySymbol + ' ' + totalCounted.toFixed(2);
                }
                if (document.getElementById('total_display')) {
                    document.getElementById('total_display').textContent = currencySymbol + ' ' + totalCounted.toFixed(2);
                }

                // Update difference alert (only if amounts are visible)
                let expectedBalance = 0;
                let expectedCashBalance = 0;
                let difference = 0;
                let cashDifference = 0;
                let cashShortage = 0;
                let voucherShortage = 0;
                let otherShortage = 0;
                let shortageType = 'exact';

                if (amountsVisible) {
                    expectedBalance = <?php echo $expected_balance; ?>;
                    expectedCashBalance = <?php echo $expected_cash_balance; ?>;
                    difference = totalCounted - expectedBalance;
                    cashDifference = cash - expectedCashBalance;

                    // Calculate shortages by payment type
                    if (cashDifference < -0.01) {
                        cashShortage = Math.abs(cashDifference);
                        shortageType = 'cash_shortage';
                    } else if (voucher < 0) {
                        voucherShortage = Math.abs(voucher);
                        shortageType = 'voucher_shortage';
                    } else if (other < 0) {
                        otherShortage = Math.abs(other);
                        shortageType = 'other_shortage';
                    } else if (difference > 0.01) {
                        shortageType = 'excess';
                    }
                }

                const differenceAlert = document.getElementById('difference_alert');
                const differenceMessage = document.getElementById('difference_message');

                if (amountsVisible && (cashShortage > 0 || voucherShortage > 0 || otherShortage > 0 || difference > 0.01)) {
                    differenceAlert.style.display = 'block';

                    let shortageBreakdown = [];
                    if (cashShortage > 0) {
                        shortageBreakdown.push(`Cash Shortage: ${currencySymbol} ${cashShortage.toFixed(2)}`);
                    }
                    if (voucherShortage > 0) {
                        shortageBreakdown.push(`Voucher Shortage: ${currencySymbol} ${voucherShortage.toFixed(2)}`);
                    }
                    if (otherShortage > 0) {
                        shortageBreakdown.push(`Other Shortage: ${currencySymbol} ${otherShortage.toFixed(2)}`);
                    }

                    if (shortageBreakdown.length > 0) {
                        differenceAlert.className = 'alert alert-danger alert-sm mt-2 mb-0';
                        differenceMessage.textContent = `Shortages detected: ${shortageBreakdown.join(', ')}.`;
                    } else if (difference > 0.01) {
                        differenceAlert.className = 'alert alert-success alert-sm mt-2 mb-0';
                        differenceMessage.textContent = `Closing amount exceeds expected balance by ${currencySymbol} ${difference.toFixed(2)}.`;
                    } else {
                        differenceAlert.style.display = 'none';
                    }
                } else {
                    differenceAlert.style.display = 'none';
                }

                // Validate form
                validateCloseTillForm();
            }

            function validateCloseTillForm() {
                const cash = parseFloat(cashAmountInput?.value || 0);
                const confirmText = confirmInput?.value || '';
                const balanceConfirmed = balanceCheckbox?.checked || false;

                const isValid = cash >= 0 && confirmText.toUpperCase() === 'CLOSE TILL' && balanceConfirmed;

                if (closeTillBtn) {
                    closeTillBtn.disabled = !isValid;
                }
            }

            // Add event listeners
            [cashAmountInput, voucherAmountInput, loyaltyAmountInput, otherAmountInput].forEach(input => {
                if (input) {
                    input.addEventListener('input', updateCalculations);
                }
            });

            confirmInput?.addEventListener('input', validateCloseTillForm);
            balanceCheckbox?.addEventListener('change', validateCloseTillForm);

            // Initialize calculations
            updateCalculations();
        });
    </script>
</body>
</html>
