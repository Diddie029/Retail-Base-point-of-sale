<?php
// Cache bust - Fixed cashier_id to user_id issue - Version 2.1 - Force refresh
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check POS authentication
requirePOSAuthentication();

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

// Check if user is admin (case insensitive)
error_log("Admin Debug - User role value: '" . $user_role . "'");
$is_admin_user = (strtolower($user_role) === 'admin');
error_log("Admin Debug - Is admin user check result: " . ($is_admin_user ? 'Yes' : 'No'));

if ($is_admin_user) {
    // Admin users get all permissions
    try {
        $perm_stmt = $conn->prepare("SELECT name FROM permissions");
        $perm_stmt->execute();
        $permissions = $perm_stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("Admin Debug - Loaded " . count($permissions) . " permissions for admin user");
        error_log("Admin Debug - First few permissions: " . implode(', ', array_slice($permissions, 0, 5)));
        error_log("Admin Debug - Cash drop permission exists: " . (in_array('cash_drop', $permissions) ? 'Yes' : 'No'));
    } catch (Exception $e) {
        error_log('Could not load all permissions for admin user: ' . $e->getMessage());
        $permissions = [];
    }
} elseif ($role_id) {
    // Regular users get permissions based on their role
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

// Get register tills with error handling
try {
    $stmt = $conn->query("
        SELECT * FROM register_tills
        WHERE is_active = 1
        ORDER BY till_name
    ");
    $register_tills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching register tills: " . $e->getMessage());
    $register_tills = [];
    $_SESSION['error_message'] = "Error loading tills. Please refresh the page.";
}

// Check if no tills exist
$no_tills_available = empty($register_tills);

// Check if user has selected a till for this session
$selected_till = null;
if (isset($_SESSION['selected_till_id'])) {
    try {
        $stmt = $conn->prepare("SELECT * FROM register_tills WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['selected_till_id']]);
        $selected_till = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If till was deleted or deactivated, clear the session
        if (!$selected_till) {
            unset($_SESSION['selected_till_id']);
            $_SESSION['error_message'] = "The selected till is no longer available. Please select a different till.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching selected till: " . $e->getMessage());
        unset($_SESSION['selected_till_id']);
        $_SESSION['error_message'] = "Error loading selected till. Please select a till again.";
    }
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
    $allow_exceed = isset($_POST['allow_exceed']) ? 1 : 0;

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
        
        // Get total sales for the current cashier (user_id) for today using the new function
        $total_sales = getCashierSalesTotal($conn, $selected_till['id'], $user_id, $today);
        
        // Get total cash drops for today for this cashier using the new function
        $total_drops = getTotalCashDrops($conn, $selected_till['id'], $user_id, $today);
        
        // Calculate expected balance: opening + sales - drops
        $expected_balance = $opening_amount + $total_sales - $total_drops;
        
        // Calculate total closing amount
        $total_closing = $cash_amount + $voucher_amount + $loyalty_points + $other_amount;

        // Calculate difference between expected and actual
        $difference = $total_closing - $expected_balance;

        // Check if closing amount is reasonable (unless allowed to exceed)
        if (!$allow_exceed && abs($difference) > 0.01) { // Allow for small rounding differences
            if ($difference > 0) {
                $_SESSION['error_message'] = "Closing amount (" . formatCurrency($total_closing, $settings) . ") exceeds expected balance (" . formatCurrency($expected_balance, $settings) . ") by " . formatCurrency($difference, $settings) . ". Please check amounts or enable 'Allow Exceed' option.";
        } else {
                $_SESSION['error_message'] = "Closing amount (" . formatCurrency($total_closing, $settings) . ") is less than expected balance (" . formatCurrency($expected_balance, $settings) . ") by " . formatCurrency(abs($difference), $settings) . ". Please check amounts or enable 'Allow Exceed' option.";
            }
        } else {
            // Determine shortage type
            $shortage_type = 'exact';
            if ($difference < -0.01) {
                $shortage_type = 'shortage'; // Less money than expected
            } elseif ($difference > 0.01) {
                $shortage_type = 'excess'; // More money than expected
            }

            // Log the till closing action for audit trail
            error_log("Till Closing - User: $username (ID: $user_id) closing Till: {$selected_till['till_name']} (ID: {$selected_till['id']})");
            error_log("Till Closing - Opening: $opening_amount, Sales: $total_sales, Drops: $total_drops, Expected: $expected_balance, Actual: $total_closing, Difference: $difference");

            // Create till closing record with additional calculation details
            $stmt = $conn->prepare("
                INSERT INTO till_closings (till_id, user_id, opening_amount, total_sales, total_drops, expected_balance, cash_amount, voucher_amount, loyalty_points, other_amount, other_description, actual_counted_amount, total_amount, difference, shortage_type, closing_notes, allow_exceed, closed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $selected_till['id'],
                $user_id,
                $opening_amount,
                $total_sales,
                $total_drops,
                $expected_balance,
                $cash_amount,
                $voucher_amount,
                $loyalty_points,
                $other_amount,
                $other_description,
                $cash_amount, // actual_counted_amount (focusing on cash for now)
                $total_closing,
                $difference,
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
            if (abs($difference) > 0.01) {
                $success_message .= ", Difference: " . formatCurrency($difference, $settings);
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
                'actual_cash' => $cash_amount,
                'voucher_amount' => $voucher_amount,
                'loyalty_points' => $loyalty_points,
                'other_amount' => $other_amount,
                'other_description' => $other_description,
                'total_closing' => $total_closing,
                'difference' => $difference,
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
                throw new Exception("Please enter both User ID/Username and password.");
            }

            // Verify user credentials - check multiple possible identifiers
            $stmt = $conn->prepare("
                SELECT id, username, password, role, employment_id, user_id, employee_id
                FROM users
                WHERE (id = ? OR employment_id = ? OR user_id = ? OR username = ? OR employee_id = ?)
                AND status = 'active'
            ");
            $stmt->execute([$auth_user_id, $auth_user_id, $auth_user_id, $auth_user_id, $auth_user_id]);
            $auth_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auth_user) {
                throw new Exception("User not found or account is inactive. Please check your User ID, Username, or Employee ID.");
            }

            if (!password_verify($auth_password, $auth_user['password'])) {
                throw new Exception("Invalid password. Please check your password and try again.");
            }

            // Check if user has cash drop permission (case insensitive for admin)
            if (strtolower($auth_user['role']) === 'admin' || hasPermission('cash_drop', $permissions)) {
                $_SESSION['cash_drop_authenticated'] = true;
                $_SESSION['cash_drop_user_id'] = $auth_user['id'];
                $_SESSION['cash_drop_username'] = $auth_user['username'];
                
                $intended_action = $_POST['intended_action'] ?? 'drop';
                $_SESSION['intended_action'] = $intended_action;
                
                $_SESSION['success_message'] = "Authentication successful. You can now proceed with drop.";
                
                // Log successful authentication
                error_log("Drop authentication successful: User ID {$auth_user['id']} ({$auth_user['username']})");
            } else {
                throw new Exception("You don't have permission to perform drop operations. Please contact your administrator.");
            }
        } catch (Exception $e) {
            // Log failed authentication attempts
            error_log("Drop authentication failed: " . $e->getMessage() . " | Attempted ID: " . $auth_user_id);
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Please enter both User ID/Employment ID and password.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle drop (automatic based on sales)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'drop') {
    $drop_amount = floatval($_POST['drop_amount'] ?? 0);
    $notes = $_POST['notes'] ?? '';

    // Check if user is authenticated for drop operations
    if (!isset($_SESSION['cash_drop_authenticated']) || !$_SESSION['cash_drop_authenticated']) {
        $_SESSION['error_message'] = "You must authenticate before performing drop operations.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate drop amount
    if ($drop_amount <= 0) {
        $_SESSION['error_message'] = "Please enter a valid drop amount greater than zero.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    // Validate confirmation checkbox
    if (!isset($_POST['confirm_drop']) || $_POST['confirm_drop'] !== 'on') {
        $_SESSION['error_message'] = "Please check the confirmation box to acknowledge this action cannot be undone.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($selected_till) {
        try {
            // Validate required session data
            if (!isset($_SESSION['cash_drop_user_id']) || !isset($_SESSION['cash_drop_username'])) {
                throw new Exception("Authentication session data is missing. Please authenticate again.");
            }

            // Validate till balance (optional - allow drops even if insufficient balance)
            if ($selected_till['current_balance'] < $drop_amount) {
                $warning_msg = "Warning: Insufficient till balance. Available: " . formatCurrency($selected_till['current_balance'], $settings) . ", Requested: " . formatCurrency($drop_amount, $settings) . ". ";
                // Still allow the drop but add warning to notes
                $notes = $warning_msg . $notes;
            }

            // Create drop record using the new function
            $drop_data = [
                'till_id' => $selected_till['id'],
                'user_id' => $_SESSION['cash_drop_user_id'],
                'drop_amount' => $drop_amount,
                'drop_type' => 'drop',
                'notes' => 'Manual drop: ' . formatCurrency($drop_amount, $settings) . ' on ' . date('Y-m-d H:i:s') . ($notes ? " | Notes: {$notes}" : "")
            ];

            $drop_id = createCashDrop($conn, $drop_data);
            if (!$drop_id) {
                throw new Exception("Failed to create drop record in database.");
            }

            // Update till balance (remove money from till)
            $new_balance = $selected_till['current_balance'] - $drop_amount;
            $stmt = $conn->prepare("UPDATE register_tills SET current_balance = ? WHERE id = ?");
            $update_result = $stmt->execute([$new_balance, $selected_till['id']]);

            if (!$update_result || $stmt->rowCount() === 0) {
                throw new Exception("Failed to update till balance. Please try again.");
            }

            // Update session
            $selected_till['current_balance'] = $new_balance;
            $_SESSION['till_opening_amount'] = $new_balance;
            $_SESSION['success_message'] = "Drop processed successfully by " . $_SESSION['cash_drop_username'] . ". Dropped " . formatCurrency($drop_amount, $settings) . " from the till. Printing slips...";

            // Generate drop slips and auto-print
            $drop_slips_html = generateDropSlipsHTML([
                'drop_id' => $drop_id,
                'drop_amount' => $drop_amount,
                'till_name' => $selected_till['till_name'],
                'till_number' => $selected_till['id'],
                'cashier_name' => $_SESSION['cash_drop_username'],
                'dropper_name' => $_SESSION['cash_drop_username'],
                'company_name' => $settings['company_name'] ?? 'Point of Sale System',
                'drop_date' => date('Y-m-d H:i:s'),
                'notes' => $notes
            ]);

            // Store for potential manual printing if auto-print fails
            $_SESSION['drop_slips_html'] = $drop_slips_html;
            $_SESSION['auto_print_drop'] = true;

            // Clear authentication after successful drop
            unset($_SESSION['cash_drop_authenticated']);
            unset($_SESSION['cash_drop_user_id']);
            unset($_SESSION['cash_drop_username']);

        } catch (Exception $e) {
            // Log the error for debugging
            error_log("Drop processing error: " . $e->getMessage() . " | User: " . ($_SESSION['cash_drop_username'] ?? 'Unknown') . " | Till: " . ($selected_till['id'] ?? 'Unknown'));
            $_SESSION['error_message'] = "Error processing drop: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "No till selected. Please select a till first.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle till close authentication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'till_close_auth') {
    $auth_user_id = $_POST['user_id'] ?? '';
    $auth_password = $_POST['password'] ?? '';

    if ($auth_user_id && $auth_password) {
        try {
            // Validate input
            $auth_user_id = trim($auth_user_id);
            $auth_password = trim($auth_password);
            
            if (empty($auth_user_id) || empty($auth_password)) {
                throw new Exception("Please enter both User ID/Username and password.");
            }

            // Verify user credentials - check multiple possible identifiers
            $stmt = $conn->prepare("
                SELECT id, username, password, role, employment_id, user_id, employee_id
                FROM users
                WHERE (id = ? OR employment_id = ? OR user_id = ? OR username = ? OR employee_id = ?)
                AND status = 'active'
            ");
            $stmt->execute([$auth_user_id, $auth_user_id, $auth_user_id, $auth_user_id, $auth_user_id]);
            $auth_user = $stmt->fetch(PDO::FETCH_ASSOC);

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

// Handle cash drop logout
if (isset($_GET['action']) && $_GET['action'] === 'logout_cash_drop') {
    unset($_SESSION['cash_drop_authenticated']);
    unset($_SESSION['cash_drop_user_id']);
    unset($_SESSION['cash_drop_username']);
    $_SESSION['success_message'] = "Logged out from cash drop operations.";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle till close logout
if (isset($_GET['action']) && $_GET['action'] === 'logout_till_close') {
    unset($_SESSION['till_close_authenticated']);
    unset($_SESSION['till_close_user_id']);
    unset($_SESSION['till_close_username']);
    $_SESSION['success_message'] = "Logged out from till close operations.";
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

// Get products for the POS interface (including Auto BOM products)
$stmt = $conn->query("
    SELECT 
        p.*, 
        c.name as category_name,
        abc.config_name as auto_bom_config_name,
        abc.base_product_id,
        bp.name as base_product_name,
        CASE 
            WHEN p.auto_bom_type IS NOT NULL THEN 'Auto BOM'
            ELSE 'Regular'
        END as product_type
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN auto_bom_configs abc ON p.id = abc.product_id
    LEFT JOIN products bp ON abc.base_product_id = bp.id
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
               || hasPermission('view_analytics', $permissions)
               || $is_admin_user;
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
        
        /* Enhanced Button Styles */
        .quotation-btn {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
        }
        
        .quotation-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #0b5ed7, #0a58ca);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(13, 110, 253, 0.4);
        }
        
        .quotation-btn:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
        }
        
        .quotation-btn:disabled {
            background: #6c757d;
            color: #fff;
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .payment-btn {
            background: linear-gradient(135deg, #198754, #157347);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.75rem 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(25, 135, 84, 0.3);
        }
        
        .payment-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #157347, #146c43);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(25, 135, 84, 0.4);
        }
        
        .payment-btn:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(25, 135, 84, 0.3);
        }
        
        .payment-btn:disabled {
            background: #6c757d;
            color: #fff;
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Button Icons */
        .quotation-btn i,
        .payment-btn i {
            font-size: 1rem;
        }
        
        /* Till Display Styling */
        .till-display-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .till-display-info .badge {
            font-size: 0.8rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .till-display-info .badge.fs-6 {
            font-size: 0.9rem !important;
            font-weight: 600;
        }

        /* Responsive adjustments */
        @media (max-width: 576px) {
            .quotation-btn,
            .payment-btn {
                padding: 0.6rem 0.4rem;
                font-size: 0.8rem;
            }
            
            .quotation-btn i,
            .payment-btn i {
                font-size: 0.9rem;
            }
            
            .till-display-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .till-display-info .badge {
                font-size: 0.7rem;
                padding: 0.4rem 0.6rem;
            }
        }

        /* Modal focus management */
        .modal[inert] {
            pointer-events: none;
        }
        
        .modal[inert] * {
            pointer-events: none;
        }
        
        .modal:not([inert]) {
            pointer-events: auto;
        }
        
        .modal:not([inert]) * {
            pointer-events: auto;
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
                <div class="till-display-info">
                    <span class="badge bg-success fs-6">
                        <i class="bi bi-cash-register"></i> <?php echo htmlspecialchars($selected_till['till_name']); ?>
                    </span>
                    <span class="badge bg-success text-white">
                        <i class="bi bi-unlock"></i> Opened
                    </span>
                    <span class="badge bg-primary text-white">
                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($username); ?>
                    </span>
                </div>
                <div class="row g-1">
                    <div class="col-4">
                        <button type="button" class="btn btn-xs btn-primary till-action-btn w-100" onclick="showSwitchTill()" title="Switch Till">
                            <i class="bi bi-arrow-repeat"></i> Switch Till
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-xs btn-secondary till-action-btn w-100" onclick="releaseTill()" title="Release Till">
                            <i class="bi bi-person-dash"></i> Release Till
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-xs btn-info till-action-btn refresh-btn w-100" onclick="refreshPage()" title="Refresh Page">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    <div class="col-4">
                        <?php 
                        // Check permissions - admins automatically have all permissions
                        $has_cash_drop_permission = hasPermission('cash_drop', $permissions);
                        // For admins, always show cash drop regardless of permission in database
                        $show_cash_drop = $has_cash_drop_permission || $is_admin_user;
                        
                        // Debug: Log permission information
                        error_log("Admin Debug - User Role: " . $user_role);
                        error_log("Admin Debug - Is Admin User: " . ($is_admin_user ? 'Yes' : 'No'));
                        error_log("Admin Debug - Permissions Count: " . count($permissions));
                        error_log("Admin Debug - Has Cash Drop Permission: " . ($has_cash_drop_permission ? 'Yes' : 'No'));
                        error_log("Admin Debug - Show Cash Drop: " . ($show_cash_drop ? 'Yes' : 'No'));
                        ?>
                        <?php if ($show_cash_drop): ?>
                        <button type="button" class="btn btn-xs btn-warning till-action-btn w-100" onclick="showDropAuth()" title="Drop">
                            <i class="bi bi-cash-stack"></i> Drop
                        </button>
                        <?php else: ?>
                        <!-- Debug: Show button for testing even without permission -->
                        <button type="button" class="btn btn-xs btn-warning till-action-btn w-100" onclick="alert('Drop Debug Info:\nRole: <?php echo $user_role; ?>\nIs Admin: <?php echo $is_admin_user ? 'Yes' : 'No'; ?>\nPermissions Count: <?php echo count($permissions); ?>\nHas Permission: <?php echo $has_cash_drop_permission ? 'Yes' : 'No'; ?>\nShow Drop: <?php echo $show_cash_drop ? 'Yes' : 'No'; ?>')" title="Drop (Debug)">
                            <i class="bi bi-cash-stack"></i> Drop
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-xs btn-danger till-action-btn w-100" onclick="showCloseTill()" title="Close Till">
                            <i class="bi bi-x-circle"></i> Close Till
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-xs btn-dark till-action-btn w-100" id="signOutBtn" onclick="signOutFromPOS()" title="Sign out from POS">
                            <i class="bi bi-box-arrow-right"></i> Sign Out
                        </button>
                    </div>
                </div>
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
        <?php if (isset($_SESSION['drop_slips_html']) && !isset($_SESSION['till_closing_slip_html'])): ?>
            <a href="#" onclick="showDropSlipsModal(); return false;" class="alert-link ms-2">
                <i class="bi bi-printer"></i> Print slips manually if needed
            </a>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['pos_auth_success'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-shield-check"></i> <?php echo $_SESSION['pos_auth_success']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['pos_auth_success']); ?>
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
                    <div class="search-section bg-white border-bottom">
                        <div class="container-fluid">
                            <div class="row g-3 align-items-center">
                                <!-- Search Column (60%) -->
                                <div class="col-lg-7 col-md-12">
                                    <div class="search-input-wrapper">
                                        <div class="input-group">
                                            <span class="input-group-text search-icon">
                                                <i class="bi bi-search"></i>
                                            </span>
                                            <input type="text" class="form-control search-input" id="productSearch" 
                                                   placeholder="Ready to scan barcode..." 
                                                   title="Scan barcodes here - Ready for continuous scanning" 
                                                   autocomplete="off" 
                                                   spellcheck="false">
                                            <span class="input-group-text barcode-indicator" id="barcodeIndicator" style="display: none;">
                                                <i class="bi bi-upc-scan text-primary"></i>
                                            </span>
                                            <button class="btn btn-outline-secondary clear-btn" type="button" onclick="clearSearch()" title="Clear Search">
                                                <i class="bi bi-x-circle"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Categories Column (40%) -->
                                <div class="col-lg-5 col-md-12">
                                    <div class="category-filter-wrapper">
                                        <div class="d-flex align-items-center">
                                            <span class="filter-label text-muted me-3">
                                                <i class="bi bi-funnel me-2"></i>Filter by Category:
                                            </span>
                                            <div class="category-dropdown-container flex-grow-1">
                                                <select class="form-select category-dropdown" id="categoryDropdown">
                                                    <option value="all" selected>All Categories</option>
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
                        </div>
                    </div>

                    <!-- Products Grid -->
                    <div class="product-grid" id="productGrid">
                        <?php foreach ($products as $product): ?>
                    <?php $isOnSale = isProductOnSale($product); ?>
                    <div class="product-card position-relative <?php echo !$selected_till ? 'disabled' : ''; ?>" 
                        data-product-id="<?php echo $product['id']; ?>" 
                        data-category-id="<?php echo $product['category_id']; ?>"
                        data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                        data-product-price="<?php echo number_format(getCurrentProductPrice($product), 2); ?>"
                        data-product-regular-price="<?php echo number_format($product['price'], 2); ?>"
                        data-product-sale-price="<?php echo $isOnSale ? number_format($product['sale_price'], 2) : ''; ?>"
                        data-product-stock="<?php echo $product['quantity']; ?>"
                        data-product-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                        data-product-barcode="<?php echo htmlspecialchars($product['barcode']); ?>"
                    >
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
                                <p class="text-muted small mb-1"><?php echo htmlspecialchars($product['category_name']); ?></p>
                                <?php if ($product['product_type'] === 'Auto BOM'): ?>
                                <div class="position-absolute top-0 end-0 p-1">
                                    <i class="fas fa-cog text-info small" title="Auto BOM Product - Based on <?php echo htmlspecialchars($product['base_product_name']); ?>" style="font-size: 0.8rem;"></i>
                                </div>
                                <?php endif; ?>
                                <div class="fw-bold text-success">
                                    <?php echo $settings['currency_symbol'] ?? 'KES'; ?> 
                                    <?php if ($isOnSale): ?>
                                        <span class="original-price text-muted" style="text-decoration:line-through; font-size:0.85rem;">
                                            <?php echo number_format($product['price'], 2); ?>
                                        </span>
                                        <span class="sale-price ms-1 text-danger">
                                            <?php echo number_format($product['sale_price'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <?php echo number_format($product['price'], 2); ?>
                                    <?php endif; ?>
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
                            
                            <!-- Sale of Quotation and Process Payment Buttons -->
                            <div class="row g-2">
                                <div class="col-6">
                                    <button class="btn btn-outline-primary w-100 btn-sm quotation-btn" onclick="openQuotationModal()" <?php echo !$selected_till ? 'disabled' : ''; ?>>
                                        <i class="bi bi-file-earmark-text me-1"></i> 
                                        <span class="d-none d-sm-inline">Sale of Quotation</span>
                                        <span class="d-inline d-sm-none">Quotation</span>
                                    </button>
                                </div>
                                <div class="col-6">
                                    <button class="btn btn-success w-100 btn-sm payment-btn" onclick="processPayment()" <?php echo ($cart_count == 0 || !$selected_till) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-credit-card me-1"></i> 
                                        <span class="d-none d-sm-inline">Process Payment</span>
                                        <span class="d-inline d-sm-none">Payment</span>
                                    </button>
                                </div>
                            </div>
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

    <!-- Quotation Selection Modal -->
    <div class="modal fade" id="quotationModal" tabindex="-1" aria-labelledby="quotationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="quotationModalLabel">
                        <i class="bi bi-file-earmark-text me-2"></i>Sale of Quotation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Quotation Number Input -->
                    <div class="mb-4">
                        <label for="quotationNumber" class="form-label fw-bold">
                            <i class="bi bi-hash me-1"></i>Quotation Number
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-file-earmark-text"></i>
                            </span>
                            <input type="text" class="form-control form-control-lg" id="quotationNumber" 
                                   placeholder="Enter quotation number (e.g., QUO-2024-001)" 
                                   autocomplete="off">
                            <button class="btn btn-outline-primary" type="button" onclick="searchQuotation()">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Enter the quotation number to convert it into a sale. The quotation will be automatically approved when converted.
                        </div>
                    </div>

                    <!-- Quotation Details -->
                    <div id="quotationDetails" style="display: none;">
                        <div class="card border-info">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-file-earmark-check me-2"></i>Quotation Details
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Quotation #:</strong> <span id="quotationNumberDisplay"></span></p>
                                        <p><strong>Customer:</strong> <span id="quotationCustomer"></span></p>
                                        <p><strong>Date:</strong> <span id="quotationDate"></span></p>
                                        <p><strong>Status:</strong> <span id="quotationStatus" class="badge"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Subtotal:</strong> <span id="quotationSubtotal"></span></p>
                                        <p><strong>Tax:</strong> <span id="quotationTax"></span></p>
                                        <p><strong>Total:</strong> <span id="quotationTotal" class="fw-bold text-primary"></span></p>
                                    </div>
                                </div>
                                
                                <!-- Quotation Items -->
                                <div class="mt-3">
                                    <h6>Items:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Qty</th>
                                                    <th>Price</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody id="quotationItems">
                                                <!-- Items will be populated here -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Error Message -->
                    <div id="quotationError" class="alert alert-danger" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <span id="quotationErrorMessage"></span>
                    </div>

                    <!-- Loading Spinner -->
                    <div id="quotationLoading" class="text-center" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Searching for quotation...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="convertQuotationBtn" onclick="convertQuotationToSale()" disabled>
                        <i class="bi bi-cart-plus me-1"></i>Add to Cart
                    </button>
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

        // DOM Ready state checker to prevent deferred DOM node errors
        function isDOMReady() {
            return document.readyState === 'loading' || document.readyState === 'interactive' || document.readyState === 'complete';
        }

        // Safe DOM element access function
        function safeGetElementById(id) {
            if (!isDOMReady()) {
                console.warn(`Attempted to access element '${id}' before DOM is ready`);
                return null;
            }
            return document.getElementById(id);
        }

        // Safe querySelector function
        function safeQuerySelector(selector) {
            if (!isDOMReady()) {
                console.warn(`Attempted to query selector '${selector}' before DOM is ready`);
                return null;
            }
            return document.querySelector(selector);
        }

        // Update time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            const dateString = now.toLocaleDateString([], {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
            const timeElement = safeGetElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = `${timeString} ${dateString}`;
            }
        }

        // Update time every second - only start after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            updateTime();
            setInterval(updateTime, 1000);
        });

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

        // Cache product data on page load - only after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            cacheProductData();
        });

        // Initialize scan mode - focus search input for immediate scanning
        function initializeScanMode() {
            const searchInput = safeGetElementById('productSearch');
            const posMain = safeQuerySelector('.pos-main');
            
            if (searchInput) {
                // Focus the search input for immediate barcode scanning
                searchInput.focus();
                
                // Add scan mode class to indicate ready state
                if (posMain) {
                    posMain.classList.add('scan-mode');
                }
            }
        }

        // Initialize scan mode on page load - only after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initializeScanMode, 500);
        });

        // Add keyboard shortcut for refresh (Ctrl+R or F5 override)
        document.addEventListener('keydown', function(event) {
            // Override F5 and Ctrl+R to use immediate refresh function
            if (event.key === 'F5' || (event.ctrlKey && event.key === 'r')) {
                event.preventDefault();
                performRefresh();
            }
        });

        // Product search and filtering - optimized with debouncing
        let searchTimeout = null;
        let barcodeScanTimeout = null;
        let isBarcodeScanning = false;
        
        // Initialize search functionality after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = safeGetElementById('productSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.trim();
                    
                    // Clear previous timeouts
                    if (searchTimeout) {
                        clearTimeout(searchTimeout);
                    }
                    if (barcodeScanTimeout) {
                        clearTimeout(barcodeScanTimeout);
                    }

                    // Ultra-fast barcode detection for rapid scanning
                    const isBarcodePattern = /^[A-Za-z0-9\-_]{6,}$/.test(searchTerm);
                    const isLikelyBarcode = searchTerm.length >= 6 && isBarcodePattern;
                    
                    if (isLikelyBarcode) {
                        // Handle barcode scanning - ultra-fast for multiple rapid scans
                        isBarcodeScanning = true;
                        barcodeScanTimeout = setTimeout(() => {
                            handleBarcodeScan(searchTerm);
                        }, 100); // Ultra-fast 100ms for instant scanning
                    } else if (searchTerm.length >= 3) {
                        // Handle regular text search for shorter terms
                        isBarcodeScanning = false;
                        searchTimeout = setTimeout(() => {
                            performProductSearch(searchTerm);
                        }, 400); // Longer debounce to avoid interfering with scanning
                    } else if (searchTerm.length === 0) {
                        // Clear search immediately when empty
                        performProductSearch('');
                    }
                });
            }
        });

        // Enhanced product search function
        function performProductSearch(searchTerm) {
            // Check if till is selected for non-empty searches
            if (searchTerm.trim() !== '') {
                <?php if (!$selected_till): ?>
                alert('Please select a till before searching products');
                showTillSelection();
                return;
                <?php endif; ?>
            }

            const searchTermLower = searchTerm.toLowerCase();
            const productCards = document.querySelectorAll('.product-card');

            // Use requestAnimationFrame for smooth UI updates
            requestAnimationFrame(() => {
                productCards.forEach(card => {
                    const productName = card.querySelector('h6').textContent.toLowerCase();
                    const categoryName = card.querySelector('p').textContent.toLowerCase();
                    const productSku = card.querySelector('.product-sku')?.textContent.toLowerCase() || '';
                    const productBarcode = card.getAttribute('data-product-barcode')?.toLowerCase() || '';

                    // Search in name, category, SKU, and barcode
                    if (productName.includes(searchTermLower) || 
                        categoryName.includes(searchTermLower) ||
                        productSku.includes(searchTermLower) ||
                        productBarcode.includes(searchTermLower)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        // Handle Enter key for immediate scanning/searching - only after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = safeGetElementById('productSearch');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        const searchTerm = this.value.trim();
                        
                        if (searchTerm.length === 0) {
                            return;
                        }
                        
                        // Clear any existing timeouts for immediate action
                        if (barcodeScanTimeout) {
                            clearTimeout(barcodeScanTimeout);
                        }
                        if (searchTimeout) {
                            clearTimeout(searchTimeout);
                        }
                        
                        // Enhanced barcode detection
                        const isBarcodePattern = /^[A-Za-z0-9\-_]{6,}$/.test(searchTerm);
                        const isLikelyBarcode = searchTerm.length >= 6 && isBarcodePattern;
                        
                        if (isLikelyBarcode) {
                            // Trigger barcode scan immediately
                            handleBarcodeScan(searchTerm);
                        } else {
                            // Perform regular search immediately
                            performProductSearch(searchTerm);
                        }
                    }
                });
            }
        });

        // Auto-focus search input for continuous scanning
        document.addEventListener('click', function(event) {
            // If clicking outside modals and not on form elements, focus search
            const isModalClick = event.target.closest('.modal');
            const isFormElement = event.target.matches('input, select, textarea, button');
            const isCartArea = event.target.closest('.cart-container');
            
            if (!isModalClick && !isFormElement && !isCartArea) {
                const searchInput = document.getElementById('productSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }
        });

        // Product selection - optimized with targeted event delegation - only after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            const productGrid = safeGetElementById('productGrid');
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
        });

        // Track pending API calls to prevent duplicates
        const pendingApiCalls = new Set();

        // Rapid barcode scanning - Optimized for instant multiple product scanning
        async function handleBarcodeScan(barcode) {
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before scanning barcodes');
            showTillSelection();
            return;
            <?php endif; ?>

            if (!barcode || barcode.length < 6) {
                return;
            }

            const searchInput = document.getElementById('productSearch');
            const barcodeIndicator = document.getElementById('barcodeIndicator');
            
            if (!searchInput) {
                console.error('Search input element not found');
                return;
            }

            // Immediately clear input and prepare for next scan
            searchInput.value = '';
            searchInput.focus();

            try {
                // Show brief scanning indicator
                searchInput.classList.add('barcode-scanning');
                if (barcodeIndicator) {
                    barcodeIndicator.style.display = 'block';
                }

                const response = await fetch(`../api/scan_barcode.php?barcode=${encodeURIComponent(barcode)}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    if (data.exact_match) {
                        // Single product found - add directly to cart instantly
                        const product = data.product;
                        
                        if (product.is_out_of_stock) {
                            showQuickFeedback(`${product.name} - OUT OF STOCK`, 'error');
                        } else {
                            // Add to cart instantly without waiting
                            addToCartInstant(product.id, 1, product);
                            
                            // Show minimal success feedback
                            showQuickFeedback(`✓ ${product.name}`, 'success');
                        }
                    } else {
                        // Multiple products - auto-select first available
                        const availableProducts = data.products.filter(p => !p.is_out_of_stock);
                        if (availableProducts.length > 0) {
                            const firstProduct = availableProducts[0];
                            addToCartInstant(firstProduct.id, 1, firstProduct);
                            showQuickFeedback(`✓ ${firstProduct.name}`, 'success');
                        } else {
                            showQuickFeedback('No available products found', 'error');
                        }
                    }
                } else {
                    showQuickFeedback('Product not found', 'error');
                }
            } catch (error) {
                console.error('Barcode scan error:', error);
                showQuickFeedback('Scan error', 'error');
            } finally {
                // Reset scanning indicator very quickly
                setTimeout(() => {
                    searchInput.classList.remove('barcode-scanning');
                    if (barcodeIndicator) {
                        barcodeIndicator.style.display = 'none';
                    }
                }, 200); // Very brief delay
            }
        }

        // Instant cart addition for rapid scanning
        function addToCartInstant(productId, quantity, productData) {
            // Update UI instantly without waiting for server response
            const currentCart = window.cartData || [];
            const existingItemIndex = currentCart.findIndex(item => item.id == productId);

            let updatedCart;
            if (existingItemIndex >= 0) {
                // Item exists, increase quantity
                updatedCart = [...currentCart];
                updatedCart[existingItemIndex].quantity += quantity;
            } else {
                // New item, add to cart
                const cartItem = {
                    id: productId,
                    product_id: productId,
                    name: productData.name,
                    price: productData.price,
                    quantity: quantity,
                    category_name: productData.category_name || '',
                    image_url: productData.image_url || '',
                    sku: productData.sku || ''
                };
                updatedCart = [...currentCart, cartItem];
            }

            // Update global cart data and UI immediately
            window.cartData = updatedCart;
            updateCartDisplay(updatedCart);

            // Sync with server in background (fire and forget)
            syncCartWithServer(productId, quantity);
        }

        // Background sync with server
        async function syncCartWithServer(productId, quantity) {
            try {
                const response = await fetch('add_to_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `product_id=${productId}&quantity=${quantity}`
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        // Server sync successful - update with server data if different
                        if (JSON.stringify(data.cart) !== JSON.stringify(window.cartData)) {
                            window.cartData = data.cart;
                            window.paymentTotals = data.totals;
                            updateCartDisplay(data.cart);
                        }
                    }
                }
            } catch (error) {
                console.error('Background sync error:', error);
                // Don't show error to user - cart already updated locally
            }
        }

        // Quick feedback for rapid scanning
        function showQuickFeedback(message, type = 'success') {
            // Remove any existing feedback
            const existingFeedback = document.querySelector('.quick-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }

            // Create minimal feedback element
            const feedbackDiv = document.createElement('div');
            feedbackDiv.className = 'quick-feedback';
            
            const icon = type === 'success' ? 'check-circle-fill' : 'x-circle-fill';
            const bgColor = type === 'success' ? '#10b981' : '#ef4444';
            
            feedbackDiv.style.cssText = `
                position: fixed;
                top: 70px;
                right: 20px;
                z-index: 9999;
                background: ${bgColor};
                color: white;
                border: none;
                border-radius: 8px;
                padding: 0.5rem 1rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                font-weight: 600;
                font-size: 0.9rem;
                animation: quickSlideIn 0.2s ease-out;
                max-width: 300px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            `;
            
            feedbackDiv.innerHTML = `<i class="bi bi-${icon} me-1"></i>${message}`;

            document.body.appendChild(feedbackDiv);

            // Auto-remove after 1 second for very fast scanning
            setTimeout(() => {
                if (feedbackDiv.parentNode) {
                    feedbackDiv.style.animation = 'quickSlideOut 0.2s ease-in';
                    setTimeout(() => {
                        feedbackDiv.remove();
                    }, 200);
                }
            }, 1000);
        }

        // Legacy function for compatibility
        function showScanFeedback(message, type = 'success') {
            showQuickFeedback(message, type);
        }

        // Legacy function for compatibility
        function showBarcodeScanSuccess(product) {
            showScanFeedback(`✓ Added: ${product.name}`, 'success');
        }

        // Show barcode product selection modal
        function showBarcodeProductSelection(products, barcode) {
            // Create modal if it doesn't exist
            let modal = document.getElementById('barcodeProductModal');
            if (!modal) {
                modal = createBarcodeProductModal();
                document.body.appendChild(modal);
            }

            // Update modal content
            const modalBody = modal.querySelector('#barcodeProductList');
            const modalTitle = modal.querySelector('#barcodeProductModalLabel');
            
            modalTitle.innerHTML = `<i class="bi bi-upc-scan me-2"></i>Select Product for Barcode: ${barcode}`;
            
            if (products.length === 0) {
                modalBody.innerHTML = `
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-exclamation-triangle fs-1"></i>
                        <p class="mt-2">No products found for barcode: ${barcode}</p>
                    </div>
                `;
            } else {
                let productsHtml = '';
                products.forEach((product, index) => {
                    const currencySymbol = window.POSConfig?.currencySymbol || 'KES';
                    const isOutOfStock = product.is_out_of_stock;
                    
                    productsHtml += `
                        <div class="barcode-product-item ${isOutOfStock ? 'out-of-stock' : ''}" 
                             onclick="selectBarcodeProduct(${product.id}, '${product.name.replace(/'/g, "\\'")}', ${product.is_out_of_stock})">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">${product.name}</h6>
                                    <small class="text-muted">${product.display_text}</small>
                                    <div class="mt-1">
                                        <span class="badge bg-primary me-1">${product.sku || 'No SKU'}</span>
                                        <span class="badge bg-info me-1">${product.category_name || 'No Category'}</span>
                                        ${isOutOfStock ? '<span class="badge bg-danger">Out of Stock</span>' : ''}
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success">
                                        ${currencySymbol} ${product.price.toFixed(2)}
                                        ${product.is_on_sale ? `<br><small class="text-muted text-decoration-line-through">${currencySymbol} ${product.regular_price.toFixed(2)}</small>` : ''}
                                    </div>
                                    <small class="text-muted">Stock: ${product.quantity}</small>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                modalBody.innerHTML = productsHtml;
            }

            // Show modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }

        // Create barcode product selection modal
        function createBarcodeProductModal() {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'barcodeProductModal';
            modal.setAttribute('tabindex', '-1');
            modal.setAttribute('aria-labelledby', 'barcodeProductModalLabel');
            modal.setAttribute('aria-hidden', 'true');
            
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="barcodeProductModalLabel">
                                <i class="bi bi-upc-scan me-2"></i>Select Product
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="barcodeProductList">
                                <!-- Products will be loaded here -->
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </div>
                </div>
            `;
            
            return modal;
        }

        // Select barcode product and add to cart
        function selectBarcodeProduct(productId, productName, isOutOfStock) {
            if (isOutOfStock) {
                alert(`Product "${productName}" is out of stock.`);
                return;
            }

            // Add to cart
            addToCart(productId, 1);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('barcodeProductModal'));
            if (modal) {
                modal.hide();
            }
            
            // Clear search input
            document.getElementById('productSearch').value = '';
            
            // Show success message
            showBarcodeScanSuccess({ name: productName, barcode: '' });
        }

        // Clear search and reset product grid
        function clearSearch() {
            const searchInput = document.getElementById('productSearch');
            searchInput.value = '';
            searchInput.focus();
            
            // Reset product grid display
            const productCards = document.querySelectorAll('.product-card');
            productCards.forEach(card => {
                card.style.display = 'block';
            });
            
            // Reset category filter
            const categoryDropdown = document.getElementById('categoryDropdown');
            if (categoryDropdown) {
                categoryDropdown.value = 'all';
            }
        }

        // Async function to add item to cart
        async function addToCartAsync(productId, quantity, fallbackCart) {
            // Create unique key for this API call
            const callKey = `${productId}-${quantity}-${Date.now()}`;

            // Ensure quantity is an integer
            const quantityInt = Math.floor(parseFloat(quantity) || 1);

            // Prevent duplicate calls for the same product and quantity
            if (pendingApiCalls.has(`${productId}-${quantityInt}`)) {
                return;
            }

            pendingApiCalls.add(`${productId}-${quantityInt}`);

            try {
                const response = await fetch('add_to_cart.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `product_id=${productId}&quantity=${quantityInt}`
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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before adding products to cart');
            showTillSelection();
            return;
            <?php endif; ?>

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

            // Update Sign Out button based on cart status (debounced)
            if (window.updateSignOutTimeout) {
                clearTimeout(window.updateSignOutTimeout);
            }
            window.updateSignOutTimeout = setTimeout(updateSignOutButton, 50);

            // Update cart items display efficiently
            updateCartItemsDisplay(cart, elements.cartItems);
        }

        // Optimized cart items display update with instant rendering
        function updateCartItemsDisplay(cart, cartItemsElement) {
            if (cart.length === 0) {
                cartItemsElement.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x fs-1"></i>
                        <p class="mt-2 mb-1">No items in cart</p>
                        <small>Scan barcodes to add products</small>
                    </div>
                `;
                return;
            }

            // Use DocumentFragment for optimal performance
            const fragment = document.createDocumentFragment();
            const currencySymbol = window.POSConfig?.currencySymbol || 'KES';

            cart.forEach((item, index) => {
                const cartItemDiv = document.createElement('div');
                cartItemDiv.className = 'cart-item cart-item-scanned';
                cartItemDiv.setAttribute('data-index', index);
                cartItemDiv.setAttribute('data-product-id', item.id || item.product_id);

                // Optimized HTML with minimal content for faster rendering
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
                                    ${currencySymbol} ${parseFloat(item.price).toFixed(2)} × ${item.quantity} = ${currencySymbol} ${(parseFloat(item.price) * item.quantity).toFixed(2)}
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="quantity-controls">
                        <span class="quantity-badge">${item.quantity}</span>
                        <button class="btn btn-outline-danger btn-sm ms-2" onclick="voidProduct(${index})" title="Remove Item">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </div>
                `;

                fragment.appendChild(cartItemDiv);
            });

            // Single DOM update for maximum performance
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

        // Quotation functionality
        function openQuotationModal() {
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before accessing quotations');
            showTillSelection();
            return;
            <?php endif; ?>

            const modal = new bootstrap.Modal(document.getElementById('quotationModal'));
            modal.show();
            
            // Clear previous data
            document.getElementById('quotationNumber').value = '';
            document.getElementById('quotationDetails').style.display = 'none';
            document.getElementById('quotationError').style.display = 'none';
            document.getElementById('quotationLoading').style.display = 'none';
            document.getElementById('convertQuotationBtn').disabled = true;
            
            // Focus on input
            setTimeout(() => {
                document.getElementById('quotationNumber').focus();
            }, 300);
        }

        function searchQuotation() {
            const quotationNumber = document.getElementById('quotationNumber').value.trim();
            
            if (!quotationNumber) {
                showQuotationError('Please enter a quotation number');
                return;
            }
            
            // Show loading
            document.getElementById('quotationLoading').style.display = 'block';
            document.getElementById('quotationDetails').style.display = 'none';
            document.getElementById('quotationError').style.display = 'none';
            document.getElementById('convertQuotationBtn').disabled = true;
            
            // Search for quotation
            fetch('search_quotation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    quotation_number: quotationNumber
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('quotationLoading').style.display = 'none';
                
                if (data.success) {
                    // Allow any quotation to be converted (it will be auto-approved)
                    displayQuotationDetails(data.quotation);
                    document.getElementById('convertQuotationBtn').disabled = false;
                } else {
                    showQuotationError(data.error || 'Quotation not found');
                }
            })
            .catch(error => {
                document.getElementById('quotationLoading').style.display = 'none';
                showQuotationError('Error searching for quotation. Please try again.');
                console.error('Quotation search error:', error);
            });
        }

        function displayQuotationDetails(quotation) {
            // Display quotation info
            document.getElementById('quotationNumberDisplay').textContent = quotation.quotation_number;
            document.getElementById('quotationCustomer').textContent = quotation.customer_name || 'Walk-in Customer';
            document.getElementById('quotationDate').textContent = new Date(quotation.created_at).toLocaleDateString();
            
            // Status badge
            const statusBadge = document.getElementById('quotationStatus');
            statusBadge.textContent = quotation.status.toUpperCase();
            
            // Color code the status badge
            if (quotation.status === 'approved') {
                statusBadge.className = 'badge bg-success';
            } else if (quotation.status === 'draft') {
                statusBadge.className = 'badge bg-warning';
            } else if (quotation.status === 'sent') {
                statusBadge.className = 'badge bg-info';
            } else if (quotation.status === 'rejected') {
                statusBadge.className = 'badge bg-danger';
            } else if (quotation.status === 'expired') {
                statusBadge.className = 'badge bg-secondary';
            } else {
                statusBadge.className = 'badge bg-primary';
            }
            
            // Financial info
            const currencySymbol = window.POSConfig.currencySymbol;
            document.getElementById('quotationSubtotal').textContent = currencySymbol + ' ' + parseFloat(quotation.subtotal).toFixed(2);
            document.getElementById('quotationTax').textContent = currencySymbol + ' ' + parseFloat(quotation.tax_amount || 0).toFixed(2);
            document.getElementById('quotationTotal').textContent = currencySymbol + ' ' + parseFloat(quotation.final_amount).toFixed(2);
            
            // Display items
            const itemsContainer = document.getElementById('quotationItems');
            itemsContainer.innerHTML = '';
            
            if (quotation.items && quotation.items.length > 0) {
                quotation.items.forEach(item => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${item.product_name}</td>
                        <td>${item.quantity}</td>
                        <td>${currencySymbol} ${parseFloat(item.unit_price).toFixed(2)}</td>
                        <td>${currencySymbol} ${parseFloat(item.total_price).toFixed(2)}</td>
                    `;
                    itemsContainer.appendChild(row);
                });
            }
            
            // Show details
            document.getElementById('quotationDetails').style.display = 'block';
        }

        function showQuotationError(message) {
            document.getElementById('quotationErrorMessage').textContent = message;
            document.getElementById('quotationError').style.display = 'block';
        }

        function convertQuotationToSale() {
            const quotationNumber = document.getElementById('quotationNumber').value.trim();
            
            if (!quotationNumber) {
                showQuotationError('Please enter a quotation number');
                return;
            }
            
            // Show loading on button
            const button = document.getElementById('convertQuotationBtn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Adding to Cart...';
            button.disabled = true;
            
            // Convert quotation to sale
            fetch('convert_quotation_to_sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    quotation_number: quotationNumber
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store quotation info for later use
                    window.currentQuotationId = data.quotation_id;
                    window.currentQuotationNumber = data.quotation_number;
                    
                    // Clear the cart first
                    clearCartAsync().then(() => {
                        // Add items to cart sequentially to avoid race conditions
                        if (data.items && data.items.length > 0) {
                            let currentIndex = 0;

                            const addNextItem = () => {
                                if (currentIndex >= data.items.length) {
                                    // All items have been added
                                    showQuickFeedback(`Quotation ${data.quotation_number} items added to cart!`, 'success');

                                    // Close modal
                                    const modal = bootstrap.Modal.getInstance(document.getElementById('quotationModal'));
                                    modal.hide();

                                    // Update cart display with current cart state
                                    updateCartDisplay(window.cartData || []);

                                    return;
                                }

                                const item = data.items[currentIndex];
                                currentIndex++;

                                // Add item to cart - this will trigger the async call
                                addToCart(item.product_id, item.quantity);

                                // Schedule next item after a short delay to prevent overwhelming the server
                                setTimeout(addNextItem, 100);
                            };

                            // Start adding items
                            addNextItem();
                        } else {
                            showQuotationError('No items found in quotation');
                        }
                    }).catch(error => {
                        console.error('Error clearing cart:', error);
                        showQuotationError('Error clearing cart. Please try again.');
                    });
                } else {
                    showQuotationError(data.error || 'Error converting quotation to sale');
                }
            })
            .catch(error => {
                showQuotationError('Error converting quotation. Please try again.');
                console.error('Quotation conversion error:', error);
            })
            .finally(() => {
                // Reset button
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Allow Enter key to search quotation - only after DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            const quotationInput = safeGetElementById('quotationNumber');
            if (quotationInput) {
                quotationInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchQuotation();
                    }
                });
            }
        });

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

            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before holding transactions');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before accessing held transactions');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before accessing customers');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before selecting customers');
            showTillSelection();
            return;
            <?php endif; ?>

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
                // Ensure modal is properly prepared before showing
                modalElement.removeAttribute('inert');
                modalElement.setAttribute('aria-hidden', 'false');
                
                const modal = new bootstrap.Modal(modalElement, {
                    focus: false, // We'll handle focus manually
                    keyboard: false,
                    backdrop: 'static'
                });
                
                // Add event listeners (only once)
                if (!modalElement.dataset.listenersAdded) {
                    modalElement.addEventListener('show.bs.modal', function() {
                        // Ensure proper state before showing
                        this.removeAttribute('inert');
                        this.setAttribute('aria-hidden', 'false');
                    });
                    
                    modalElement.addEventListener('shown.bs.modal', function() {
                        // Focus management after modal is fully shown
                        setTimeout(() => {
                            manageModalFocus(this);
                            validateTillSelection();
                        }, 100);
                    });
                    
                    modalElement.addEventListener('hide.bs.modal', function() {
                        // Remove focus before hiding
                        const focusedElement = this.querySelector(':focus');
                        if (focusedElement) {
                            focusedElement.blur();
                        }
                    });
                    
                    modalElement.addEventListener('hidden.bs.modal', function() {
                        hideModalProperly(this);
                    });
                    
                    modalElement.dataset.listenersAdded = 'true';
                }
                
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

            // Check if cart has items first
            if (!verifyCartEmpty('release')) {
                return;
            }

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

        // JavaScript currency formatting function
        function formatCurrencyJS(amount) {
            const symbol = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?>';
            const position = '<?php echo $settings['currency_position'] ?? 'before'; ?>';
            const decimals = <?php echo intval($settings['currency_decimal_places'] ?? 2); ?>;
            
            // Format amount with k/m notation for large numbers
            let formattedAmount;
            if (amount >= 1000000) {
                formattedAmount = (amount / 1000000).toFixed(1) + 'm';
            } else if (amount >= 10000) {
                formattedAmount = (amount / 1000).toFixed(1) + 'k';
            } else {
                formattedAmount = amount.toFixed(decimals);
            }
            
            if (position === 'before') {
                return symbol + ' ' + formattedAmount;
            } else {
                return formattedAmount + ' ' + symbol;
            }
        }

        // Focus management for modals
        function manageModalFocus(modalElement) {
            if (!modalElement) return;
            
            // Remove aria-hidden and inert when modal is shown
            modalElement.setAttribute('aria-hidden', 'false');
            modalElement.removeAttribute('inert');
            
            // Find all focusable elements
            const focusableElements = modalElement.querySelectorAll(
                'input:not([disabled]), button:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]):not([disabled])'
            );
            
            if (focusableElements.length > 0) {
                // Focus on the first focusable element
                focusableElements[0].focus();
            }
        }

        // Hide modal properly
        function hideModalProperly(modalElement) {
            if (!modalElement) return;
            
            // Set aria-hidden and inert when modal is hidden
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.setAttribute('inert', '');
            
            // Remove focus from any focused elements inside the modal
            const focusedElement = modalElement.querySelector(':focus');
            if (focusedElement) {
                focusedElement.blur();
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
                        showModal(modalElement);
                        } else {
                        alert('Switch Till modal not found. Please refresh the page.');
                    }
                    break;
                case 'close':
                    // Always require authentication for till closing
                    showCloseTillAuth();
                    break;
                case 'release':
                    if (confirm('Are you sure you want to release this till? Other cashiers will be able to use it.')) {
                        window.location.href = '?action=release_till';
                    }
                    break;
            }
        }

        function showDropAuth() {
            document.getElementById('intended_action').value = 'drop';
            const modalElement = document.getElementById('cashDropAuthModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function showDrop() {
            const modalElement = document.getElementById('dropModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        }

        function showSwitchTill() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            // Check if cart has items first
            if (!verifyCartEmpty('switch')) {
                return;
            }

            // Set loading state
            setButtonLoading(button, 'Checking...', true);

            // Check for held transactions before allowing till switch
            checkHeldTransactionsBeforeTillOperation('switch', button);
        }

        function showCloseTill() {
            // Prevent double-clicking
            const button = event.target;
            if (button.disabled) return;

            // Check if cart has items first
            if (!verifyCartEmpty('close')) {
                return;
            }

            // Set loading state
            setButtonLoading(button, 'Checking...', true);

            // Check for held transactions before allowing till close
            checkHeldTransactionsBeforeTillOperation('close', button);
        }

        function showCloseTillDirect() {
            // Direct function to show close till modal (bypasses held transaction check)
            showCloseTillModal();
        }

        function showCloseTillAuth() {
            const modalElement = document.getElementById('tillCloseAuthModal');
            
            if (modalElement) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                } else {
                    // Fallback for manual modal display
                    modalElement.style.display = 'block';
                    modalElement.classList.add('show');
                    modalElement.removeAttribute('aria-hidden');
                    document.body.classList.add('modal-open');
                }
            } else {
                console.error('Till Close Authentication Modal not found!');
                alert('Authentication modal not found. Please refresh the page.');
            }
        }

        function showCloseTillModal() {
            // Always require authentication for till closing
            showCloseTillAuth();
        }

        function showCloseTillForm() {
            // Show the actual till closing form (called after authentication)
            let attempts = 0;
            const maxAttempts = 5;
            
            function tryShowModal() {
                attempts++;
                const closeModalElement = document.getElementById('closeTillModal');
                
                if (closeModalElement) {
                    showModal(closeModalElement);
                } else if (attempts < maxAttempts) {
                    // Retry after a short delay
                    setTimeout(tryShowModal, 300);
                } else {
                    // Final attempt: try to find modal by content or create a basic one
                    const alternativeModal = findModalByContent();
                    if (alternativeModal) {
                        showModal(alternativeModal);
                    } else {
                        createFallbackCloseTillModal();
                    }
                }
            }
            
            tryShowModal();
        }

        function findModalByContent() {
            // Try to find modal by searching for specific content
            const allModals = document.querySelectorAll('.modal');
            for (let modal of allModals) {
                // Check if this modal contains "Close Till" content
                if (modal.textContent.includes('Close Till') && 
                    modal.textContent.includes('close_till') &&
                    modal.textContent.includes('Expected Balance')) {
                    return modal;
                }
            }
            return null;
        }

        function showModal(modalElement) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
                
                // Ensure proper focus management when modal is shown
                modalElement.addEventListener('shown.bs.modal', function() {
                    // Remove aria-hidden when modal is fully shown
                    modalElement.removeAttribute('aria-hidden');
                    
                    // Focus on the first focusable element
                    const firstFocusable = modalElement.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                    if (firstFocusable) {
                        firstFocusable.focus();
                    }
                });
                
                // Restore aria-hidden when modal is hidden
                modalElement.addEventListener('hidden.bs.modal', function() {
                    modalElement.setAttribute('aria-hidden', 'true');
                });
            } else {
                // Fallback for manual modal display
                modalElement.style.display = 'block';
                modalElement.classList.add('show');
                modalElement.removeAttribute('aria-hidden');
                document.body.classList.add('modal-open');
                
                // Focus management for fallback
                const firstFocusable = modalElement.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusable) {
                    firstFocusable.focus();
                }
            }
        }

        function hideModal(modalElement) {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            } else {
                // Fallback for manual modal hiding
                modalElement.style.display = 'none';
                modalElement.classList.remove('show');
                modalElement.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            }
        }

        function createFallbackCloseTillModal() {
            // Create a basic close till modal if the original one is not found
            const modalHtml = `
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
                                    <p>Are you sure you want to close this till?</p>
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
            `;
            
            // Add the modal to the body
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            
            // Now try to show it
            setTimeout(() => {
                const closeModalElement = document.getElementById('closeTillModal');
                if (closeModalElement) {
                    showModal(closeModalElement);
                }
            }, 100);
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

        // New till selection function for dropdown
        function onTillSelection() {
            const dropdown = document.getElementById('till_dropdown');
            const selectedValue = dropdown ? dropdown.value : '';
            const selectedOption = dropdown ? dropdown.options[dropdown.selectedIndex] : null;
            
            if (selectedValue && selectedOption) {
                // Show till details
                showTillDetails(selectedOption);
                
                // Show opening amount section - force display
                const openingAmountSection = document.getElementById('opening_amount_section');
                if (openingAmountSection) {
                    openingAmountSection.style.display = 'block';
                    openingAmountSection.style.visibility = 'visible';
                    
                    // Focus on the opening amount input for better UX
                    setTimeout(() => {
                        const openingAmountInput = document.getElementById('opening_amount');
                        if (openingAmountInput) {
                            openingAmountInput.focus();
                        }
                    }, 200);
                }
                
                // Validate selection
                validateTillSelection();
            } else {
                // Hide sections if no till selected
                const tillDetailsCard = document.getElementById('till_details_card');
                const openingAmountSection = document.getElementById('opening_amount_section');
                const confirmBtn = document.getElementById('confirmTillSelection');
                
                if (tillDetailsCard) {
                    tillDetailsCard.style.display = 'none';
                    tillDetailsCard.style.visibility = 'hidden';
                }
                if (openingAmountSection) {
                    openingAmountSection.style.display = 'none';
                    openingAmountSection.style.visibility = 'hidden';
                }
                if (confirmBtn) confirmBtn.disabled = true;
            }
        }

        function showTillDetails(option) {
            const tillName = option.getAttribute('data-till-name') || '-';
            const tillCode = option.getAttribute('data-till-code') || '-';
            const location = option.getAttribute('data-location') || '-';
            const status = option.getAttribute('data-status') || 'closed';
            const currentUser = option.getAttribute('data-current-user') || '';
            const currentBalance = parseFloat(option.getAttribute('data-current-balance') || 0);
            
            // Update till details display
            document.getElementById('selected_till_name').textContent = tillName;
            document.getElementById('selected_till_code').textContent = tillCode;
            document.getElementById('selected_till_location').textContent = location;
            
            // Format status display
            let statusDisplay = '';
            let statusClass = '';
            if (status === 'opened') {
                if (currentUser) {
                    statusDisplay = '<i class="bi bi-person-check text-warning"></i> In Use';
                    statusClass = 'text-warning';
                } else {
                    statusDisplay = '<i class="bi bi-unlock text-success"></i> Available';
                    statusClass = 'text-success';
                }
            } else {
                statusDisplay = '<i class="bi bi-lock text-secondary"></i> Closed';
                statusClass = 'text-secondary';
            }
            
            document.getElementById('selected_till_status').innerHTML = statusDisplay;
            document.getElementById('selected_till_balance').textContent = formatCurrencyJS(currentBalance);
            
            // Get current user name if available
            let currentUserName = '-';
            if (currentUser) {
                // This would need to be populated from server data
                currentUserName = 'User ID: ' + currentUser;
            }
            document.getElementById('selected_till_user').textContent = currentUserName;
            
            // Show the details card
            document.getElementById('till_details_card').style.display = 'block';
        }

        function setOpeningAmount(amount) {
            const openingAmountInput = document.getElementById('opening_amount');
            if (openingAmountInput) {
                openingAmountInput.value = amount;
                validateTillSelection();
            }
        }

        function refreshTillList() {
            // Reload the page to refresh till data
            window.location.reload();
        }

        function forceShowOpeningAmount() {
            // Force show opening amount section (for backup)
            const openingAmountSection = document.getElementById('opening_amount_section');
            if (openingAmountSection) {
                openingAmountSection.style.display = 'block';
                openingAmountSection.style.visibility = 'visible';
                
                // Focus on input
                setTimeout(() => {
                    const openingAmountInput = document.getElementById('opening_amount');
                    if (openingAmountInput) {
                        openingAmountInput.focus();
                    }
                }, 100);
            }
        }

        function forceValidateSelection() {
            // Force validation of till selection (for backup)
            validateTillSelection();
        }

        function autoFillExpectedAmount() {
            // Get expected balance from the calculation summary
            const expectedBalanceElement = document.getElementById('expected_balance_display');
            const expectedBalance = parseFloat(expectedBalanceElement?.textContent?.replace(/[^\d.-]/g, '') || 0);
            
            if (expectedBalance > 0) {
                // Auto-fill the cash amount with the expected balance
                document.getElementById('cash_amount').value = expectedBalance.toFixed(2);
                
                // Clear other amounts
                document.getElementById('voucher_amount').value = '';
                document.getElementById('loyalty_points').value = '';
                document.getElementById('other_amount').value = '';
                
                // Update the totals
                updateCloseTillTotals();
                
                // Show success message
                const cashInput = document.getElementById('cash_amount');
                cashInput.classList.add('border-success');
                setTimeout(() => {
                    cashInput.classList.remove('border-success');
                }, 2000);
            }
        }

        function selectTill(tillId) {
            // Legacy function - now handled by dropdown
            const dropdown = document.getElementById('till_dropdown');
            if (dropdown) {
                dropdown.value = tillId;
                onTillSelection();
            }
        }

        function validateTillSelection() {
            const tillDropdown = document.getElementById('till_dropdown');
            const openingAmount = document.getElementById('opening_amount');
            const confirmBtn = document.getElementById('confirmTillSelection');
            const statusElement = document.getElementById('selection-status');

            if (tillDropdown && confirmBtn) {
                const tillSelected = tillDropdown.value !== '';
                
                if (tillSelected) {
                    // Till is selected, check if opening amount is entered
                    const amountValue = openingAmount ? openingAmount.value : '';
                    const amountParsed = parseFloat(amountValue);
                    const amountEntered = openingAmount && amountValue !== '' && amountParsed >= 0;
                    
                    if (amountEntered) {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="bi bi-check-circle"></i> Confirm Selection';
                        confirmBtn.className = 'btn btn-success';
                        if (statusElement) {
                            statusElement.innerHTML = '<i class="bi bi-check-circle text-success"></i> Ready to confirm';
                            statusElement.className = 'text-success';
                        }
                    } else {
                        confirmBtn.disabled = true;
                        confirmBtn.innerHTML = '<i class="bi bi-exclamation-circle"></i> Enter Opening Amount';
                        confirmBtn.className = 'btn btn-primary';
                        if (statusElement) {
                            statusElement.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> Please enter opening amount';
                            statusElement.className = 'text-warning';
                        }
                    }
                } else {
                    // No till selected
                    confirmBtn.disabled = true;
                    confirmBtn.innerHTML = '<i class="bi bi-check-circle"></i> Confirm Selection';
                    confirmBtn.className = 'btn btn-primary';
                    if (statusElement) {
                        statusElement.innerHTML = '<i class="bi bi-info-circle text-info"></i> Please select a till';
                        statusElement.className = 'text-info';
                    }
                }
            }
        }

        function updateCloseTillTotals() {
            const cashAmount = parseFloat(document.getElementById('cash_amount').value) || 0;
            const voucherAmount = parseFloat(document.getElementById('voucher_amount').value) || 0;
            const loyaltyAmount = parseFloat(document.getElementById('loyalty_points').value) || 0;
            const otherAmount = parseFloat(document.getElementById('other_amount').value) || 0;
            
            // Get expected balance from the calculation summary
            const expectedBalanceElement = document.getElementById('expected_balance_display');
            const expectedBalance = parseFloat(expectedBalanceElement?.textContent?.replace(/[^\d.-]/g, '') || 0);

            const totalClosing = cashAmount + voucherAmount + loyaltyAmount + otherAmount;
            const difference = totalClosing - expectedBalance;

            document.getElementById('cash_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + cashAmount.toFixed(2);
            document.getElementById('voucher_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + voucherAmount.toFixed(2);
            document.getElementById('loyalty_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + loyaltyAmount.toFixed(2);
            document.getElementById('other_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + otherAmount.toFixed(2);
            document.getElementById('total_counted_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + totalClosing.toFixed(2);
            document.getElementById('total_display').textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + totalClosing.toFixed(2);

            const differenceElement = document.getElementById('difference_display');
            const differenceAlert = document.getElementById('difference_alert');
            const differenceMessage = document.getElementById('difference_message');
            
            differenceElement.textContent = '<?php echo $settings['currency_symbol'] ?? 'KES'; ?> ' + difference.toFixed(2);

            // Update styling and alerts based on difference
            if (Math.abs(difference) <= 0.01) {
                // Perfect match
                differenceElement.className = 'text-success fw-bold';
                differenceAlert.style.display = 'none';
            } else if (difference > 0) {
                // Over amount
                differenceElement.className = 'text-danger fw-bold';
                differenceAlert.className = 'alert alert-danger alert-sm mt-2 mb-0';
                differenceAlert.style.display = 'block';
                differenceMessage.innerHTML = `<i class="bi bi-exclamation-triangle"></i> Closing amount exceeds expected balance by ${'<?php echo $settings['currency_symbol'] ?? 'KES'; ?>'} ${Math.abs(difference).toFixed(2)}.`;
            } else {
                // Under amount
                differenceElement.className = 'text-warning fw-bold';
                differenceAlert.className = 'alert alert-warning alert-sm mt-2 mb-0';
                differenceAlert.style.display = 'block';
                differenceMessage.innerHTML = `<i class="bi bi-exclamation-triangle"></i> Closing amount is ${'<?php echo $settings['currency_symbol'] ?? 'KES'; ?>'} ${Math.abs(difference).toFixed(2)} less than expected balance.`;
            }
        }

        // Category dropdown functionality
        function initializeCategoryDropdown() {
            const categoryDropdown = document.getElementById('categoryDropdown');
            const productCards = document.querySelectorAll('.product-card');

            if (!categoryDropdown) return;

            // Handle category selection
            categoryDropdown.addEventListener('change', function() {
                // Check if till is selected
                <?php if (!$selected_till): ?>
                alert('Please select a till before filtering products');
                showTillSelection();
                this.value = 'all'; // Reset to show all categories
                return;
                <?php endif; ?>

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
        }

        // Logout functionality
        function initializeLogout() {
        }

        // Modal accessibility functionality
        function initializeModalAccessibility() {
            // Add event listeners to all modals for proper accessibility
            const modals = document.querySelectorAll('.modal');
            
            modals.forEach(modal => {
                // When modal is shown, remove aria-hidden and manage focus
                modal.addEventListener('shown.bs.modal', function() {
                    modal.removeAttribute('aria-hidden');
                    
                    // Focus on the first focusable element
                    const firstFocusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                    if (firstFocusable) {
                        firstFocusable.focus();
                    }
                });
                
                // When modal is hidden, restore aria-hidden
                modal.addEventListener('hidden.bs.modal', function() {
                    modal.setAttribute('aria-hidden', 'true');
                });
                
                // Handle escape key to close modal
                modal.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    }
                });
            });
        }

        // Global logout function
        function logout() {
            // Check if user has an active till that's not closed
            const hasActiveTill = <?php echo $selected_till ? 'true' : 'false'; ?>;
            const tillClosed = <?php echo $selected_till && isset($selected_till['is_closed']) && $selected_till['is_closed'] == 1 ? 'true' : 'false'; ?>;
            
            if (hasActiveTill && !tillClosed) {
                alert('Cannot logout while till is open. Please close your till first.');
                return;
            }
            
            if (confirm('Are you sure you want to logout? Any unsaved changes will be lost.')) {
                // Redirect to logout
                window.location.href = '../auth/logout.php';
            }
        }

        // Refresh page function - immediate refresh without confirmation
        function refreshPage() {
            performRefresh();
        }

        // Show cart blocking notification
        function showCartBlockingNotification(itemCount, total, currencySymbol) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'cart-blocking-notification';
            notification.innerHTML = `
                <div class="notification-content">
                    <div class="notification-header">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                        <h5>Cannot Sign Out</h5>
                    </div>
                    <div class="notification-body">
                        <p>You have <strong>${itemCount} item(s)</strong> in your cart worth <strong>${currencySymbol} ${total.toFixed(2)}</strong></p>
                        <p class="mb-0">Please complete the transaction or clear the cart first.</p>
                    </div>
                    <div class="notification-actions">
                        <button class="btn btn-warning btn-sm" onclick="processPayment()">
                            <i class="bi bi-credit-card"></i> Process Payment
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="voidCart()">
                            <i class="bi bi-x-circle"></i> Clear Cart
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="closeCartNotification()">
                            <i class="bi bi-x"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            // Add styles
            notification.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                border: 2px solid #ffc107;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                z-index: 9999;
                min-width: 400px;
                max-width: 500px;
            `;
            
            // Add to page
            document.body.appendChild(notification);
            
            // Add backdrop
            const backdrop = document.createElement('div');
            backdrop.className = 'notification-backdrop';
            backdrop.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9998;
            `;
            document.body.appendChild(backdrop);
        }
        
        // Close cart notification
        function closeCartNotification() {
            const notification = document.querySelector('.cart-blocking-notification');
            const backdrop = document.querySelector('.notification-backdrop');
            if (notification) notification.remove();
            if (backdrop) backdrop.remove();
        }
        
        // Sign out from POS function - optimized for performance
        let signOutInProgress = false;
        function signOutFromPOS() {
            // Prevent multiple rapid clicks
            if (signOutInProgress) return;
            
            // Use requestAnimationFrame to prevent blocking
            requestAnimationFrame(() => {
                // Check if cart has items - prevent sign out if cart is not empty
                if (window.cartData && window.cartData.length > 0) {
                    const cartTotal = window.cartData.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                    const currencySymbol = window.POSConfig?.currencySymbol || 'KES';
                    
                    showCartBlockingNotification(window.cartData.length, cartTotal, currencySymbol);
                    return;
                }
                
                // Use a more efficient confirmation method
                if (confirm('Sign out from POS? You can sign in again to continue.')) {
                    signOutInProgress = true;
                    
                    // Immediate redirect without waiting
                    window.location.replace('logout_auth.php');
                }
            });
        }

        // Update Sign Out button based on cart status - optimized
        let lastCartState = null;
        function updateSignOutButton() {
            const signOutBtn = document.getElementById('signOutBtn');
            if (!signOutBtn) return;
            
            const hasItems = window.cartData && window.cartData.length > 0;
            
            // Only update if state has changed
            if (lastCartState === hasItems) return;
            lastCartState = hasItems;
            
            // Use requestAnimationFrame for smooth updates
            requestAnimationFrame(() => {
                if (hasItems) {
                    signOutBtn.disabled = true;
                    signOutBtn.classList.remove('btn-dark');
                    signOutBtn.classList.add('btn-secondary');
                    signOutBtn.title = 'Cannot sign out with items in cart';
                    signOutBtn.innerHTML = '<i class="bi bi-lock"></i> Sign Out';
                } else {
                    signOutBtn.disabled = false;
                    signOutBtn.classList.remove('btn-secondary');
                    signOutBtn.classList.add('btn-dark');
                    signOutBtn.title = 'Sign out from POS';
                    signOutBtn.innerHTML = '<i class="bi bi-box-arrow-right"></i> Sign Out';
                }
            });
        }


        // Perform the actual page refresh
        function performRefresh() {
            // Show loading indicator
            const refreshBtn = document.querySelector('.refresh-btn');
            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            }

            // Immediate refresh
            window.location.reload();
        }

        // Auto-sync every 10 minutes
        function initializeAutoSync() {
            setInterval(() => {
                window.location.reload();
            }, 10 * 60 * 1000); // 10 minutes in milliseconds
        }

        // Start auto-sync on page load
        setTimeout(() => {
            initializeAutoSync();
        }, 1000);
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
    <div class="modal fade" id="tillSelectionModal" tabindex="-1" aria-labelledby="tillSelectionModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bs-focus="true">
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

                        <!-- Till Selection Dropdown -->
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="till_dropdown" class="form-label">
                                        <i class="bi bi-cash-register"></i> Select Till
                                    </label>
                                    <select class="form-select form-select-lg" name="till_id" id="till_dropdown" required>
                                        <option value="">Choose a till...</option>
                            <?php foreach ($register_tills as $till): ?>
                                        <option value="<?php echo $till['id']; ?>" 
                                                data-till-name="<?php echo htmlspecialchars($till['till_name']); ?>"
                                                data-till-code="<?php echo htmlspecialchars($till['till_code']); ?>"
                                                data-location="<?php echo htmlspecialchars($till['location'] ?? 'N/A'); ?>"
                                                data-status="<?php echo $till['till_status'] ?? 'closed'; ?>"
                                                data-current-user="<?php echo $till['current_user_id'] ?? ''; ?>"
                                                data-current-balance="<?php echo $till['current_balance'] ?? 0; ?>"
                                                <?php if (($till['current_user_id'] ?? '') != '' && ($till['current_user_id'] ?? '') != $user_id): ?>disabled<?php endif; ?>>
                                            <?php echo htmlspecialchars($till['till_name']); ?> 
                                            (<?php echo htmlspecialchars($till['till_code']); ?>)
                                            <?php if (($till['current_user_id'] ?? '') != '' && ($till['current_user_id'] ?? '') != $user_id): ?>
                                                - In Use
                                            <?php elseif (($till['till_status'] ?? 'closed') === 'opened'): ?>
                                                - Available
                                                    <?php else: ?>
                                                - Closed
                                                    <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                        </div>
                                    </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="button" class="btn btn-outline-primary w-100" onclick="refreshTillList()">
                                        <i class="bi bi-arrow-clockwise"></i> Refresh
                                    </button>
                                    <button type="button" class="btn btn-outline-success w-100 mt-2" onclick="forceShowOpeningAmount()" title="Force show opening amount section">
                                        <i class="bi bi-eye"></i> Show Amount Input
                                    </button>
                                    <button type="button" class="btn btn-outline-warning w-100 mt-2" onclick="forceValidateSelection()" title="Force validate selection">
                                        <i class="bi bi-check-circle"></i> Force Validate
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Till Details Card -->
                        <div id="till_details_card" class="card mb-3" style="display: none;">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="bi bi-info-circle"></i> Till Details
                                </h6>
                            </div>
                                    <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Name:</strong> <span id="selected_till_name">-</span></p>
                                        <p><strong>Code:</strong> <span id="selected_till_code">-</span></p>
                                        <p><strong>Location:</strong> <span id="selected_till_location">-</span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Status:</strong> <span id="selected_till_status">-</span></p>
                                        <p><strong>Current Balance:</strong> <span id="selected_till_balance">-</span></p>
                                        <p><strong>Current User:</strong> <span id="selected_till_user">-</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Opening Amount Input -->
                        <div id="opening_amount_section" class="card" style="display: none;">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0">
                                            <i class="bi bi-cash-coin"></i> Opening Amount
                                        </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="input-group input-group-lg">
                                            <span class="input-group-text bg-success text-white">
                                                <i class="bi bi-currency-exchange"></i>
                                                <?php echo $settings['currency_symbol'] ?? 'KES'; ?>
                                            </span>
                                            <input type="number" class="form-control" name="opening_amount" id="opening_amount"
                                                   step="0.01" min="0" placeholder="0.00" required>
                                        </div>
                                        <div class="form-text">
                                            <i class="bi bi-info-circle"></i>
                                            Enter the cash amount you're starting with in this till. The confirm button will be enabled once you enter an amount.
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-grid gap-2">
                                            <button type="button" class="btn btn-outline-secondary" onclick="setOpeningAmount(0)">
                                                <i class="bi bi-0-circle"></i> Set to 0
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="setOpeningAmount(100)">
                                                <i class="bi bi-100"></i> Set to 100
                                            </button>
                                        </div>
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
                        <div class="me-auto">
                            <small class="text-muted" id="selection-status">
                                <i class="bi bi-info-circle"></i> Please select a till and enter opening amount
                            </small>
                        </div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="confirmTillSelection" disabled>
                            <i class="bi bi-check-circle"></i> Confirm Selection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Till Close Authentication Modal -->
    <div class="modal fade" id="tillCloseAuthModal" tabindex="-1" aria-labelledby="tillCloseAuthModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="tillCloseAuthModalLabel">
                        <i class="bi bi-shield-lock"></i> Till Close Authentication
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="till_close_auth">
                    <input type="hidden" name="intended_action" id="intended_till_close_action" value="close_till">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Please enter your credentials to proceed with till closing operation. You can authenticate using your User ID, Username, or Employee ID.
                        </div>

                        <div class="mb-3">
                            <label for="till_close_auth_user_id" class="form-label">User ID, Username, or Employee ID</label>
                            <input type="text" class="form-control" name="user_id" id="till_close_auth_user_id"
                                   placeholder="Enter your User ID, Username, or Employee ID" autocomplete="username" required>
                            <div class="form-text">You can use your User ID (e.g., USR1), Username, or Employee ID to authenticate.</div>
                        </div>

                        <div class="mb-3">
                            <label for="till_close_auth_password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="till_close_auth_password"
                                   placeholder="Enter your password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-shield-check"></i> Authenticate
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
                        <i class="bi bi-shield-lock"></i> Drop Authentication
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="cash_drop_auth">
                    <input type="hidden" name="intended_action" id="intended_action" value="drop">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Please enter your credentials to proceed with drop operation. You can authenticate using your User ID, Username, or Employee ID.
                        </div>

                        <div class="mb-3">
                            <label for="auth_user_id" class="form-label">User ID, Username, or Employee ID</label>
                            <input type="text" class="form-control" name="user_id" id="auth_user_id"
                                   placeholder="Enter your User ID, Username, or Employee ID" autocomplete="username" required>
                            <div class="form-text">You can use your User ID (e.g., USR1), Username, or Employee ID to authenticate.</div>
                        </div>

                        <div class="mb-3">
                            <label for="auth_password" class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" id="auth_password"
                                   placeholder="Enter your password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" id="authSubmitBtn">
                            <i class="bi bi-shield-check"></i> Authenticate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Drop Modal -->
    <div class="modal fade" id="dropModal" tabindex="-1" aria-labelledby="dropModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="dropModalLabel">
                        <i class="bi bi-cash-stack"></i> Drop
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="drop">
                    <div class="modal-body">
                        <?php if (!isset($_SESSION['cash_drop_authenticated']) || !$_SESSION['cash_drop_authenticated']): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            You must authenticate before proceeding with drop operations.
                        </div>
                        <?php endif; ?>

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Drop:</strong> Enter the amount you want to drop from the till. This can be for sales, petty cash, adjustments, or any other reason.
                        </div>

                        <div class="card mb-3">
                            <div class="card-body text-center">
                                <h6 class="card-title">
                                    <i class="bi bi-calculator"></i> Till Information
                                </h6>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Cashier:</span>
                                            <strong class="text-primary">
                                                <?php echo htmlspecialchars($_SESSION['cash_drop_username'] ?? 'Unknown'); ?>
                                            </strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Current Till:</span>
                                            <strong class="text-info">
                                                <?php echo htmlspecialchars($selected_till['till_name'] ?? 'Unknown'); ?>
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="drop_amount" class="form-label">
                                <i class="bi bi-cash-stack"></i> Drop Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? '$'; ?></span>
                                <input type="number" class="form-control" name="drop_amount" id="drop_amount"
                                       placeholder="0.00" step="0.01" min="0" required>
                            </div>
                            <div class="form-text">Enter the amount to drop from the till (positive value)</div>
                        </div>

                        <div class="mb-3">
                            <label for="drop_notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" id="drop_notes" rows="3"
                                      placeholder="Enter reason for this drop (e.g., daily sales, petty cash, adjustment)..."></textarea>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm_drop" id="confirm_drop" required>
                                <label class="form-check-label" for="confirm_drop">
                                    <strong>I confirm this drop action cannot be undone</strong>
                                </label>
                            </div>
                            <div class="form-text text-muted">
                                Please check this box to confirm you understand this action will permanently remove money from the till.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if (isset($_SESSION['cash_drop_authenticated']) && $_SESSION['cash_drop_authenticated']): ?>
                            <button type="submit" class="btn btn-warning" id="dropSubmitBtn">
                                <i class="bi bi-cash-stack"></i> Process Drop
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-warning" onclick="showDropAuth()">
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
                        <?php if (!isset($_SESSION['till_close_authenticated']) || !$_SESSION['till_close_authenticated']): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-shield-exclamation"></i>
                            <strong>Authentication Required:</strong> You must authenticate before accessing till closing operations.
                        </div>
                        <div class="text-center py-4">
                            <button type="button" class="btn btn-danger btn-lg" onclick="showCloseTillAuth()">
                                <i class="bi bi-shield-lock"></i> Authenticate to Continue
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Close Till:</strong> This will permanently close the current till and reset its balance to zero. This action cannot be undone.
                        </div>

                        <?php if ($selected_till): ?>
                        <?php
                        // Get calculation data for closing using the new functions
                        $opening_amount = floatval($_SESSION['till_opening_amount'] ?? 0);
                        $today = date('Y-m-d');
                        
                        // Get total sales for today for this cashier using the new function
                        $total_sales = getCashierSalesTotal($conn, $selected_till['id'], $user_id, $today);
                        
                        // Get total cash drops for today for this cashier using the new function
                        $total_drops = getTotalCashDrops($conn, $selected_till['id'], $user_id, $today);
                        
                        // Calculate expected balance: opening + sales - drops
                        $expected_balance = $opening_amount + $total_sales - $total_drops;
                        ?>

                        <!-- Till Calculation Summary -->
                        <div class="card mb-3">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">
                                    <i class="bi bi-calculator"></i> Till Calculation Summary
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-person"></i> Cashier:</span>
                                            <strong class="text-primary"><?php echo htmlspecialchars($username); ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-cash-coin"></i> Opening Amount:</span>
                                            <strong class="text-primary"><?php echo formatCurrency($opening_amount, $settings); ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-cart-plus"></i> Total Sales (This Cashier):</span>
                                            <strong class="text-success"><?php echo formatCurrency($total_sales, $settings); ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-cash-stack"></i> Total Drops (This Cashier):</span>
                                            <strong class="text-warning"><?php echo formatCurrency($total_drops, $settings); ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-equals"></i> Expected Balance:</span>
                                            <strong class="text-info"><?php echo formatCurrency($expected_balance, $settings); ?></strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span><i class="bi bi-info-circle"></i> Formula:</span>
                                            <small class="text-muted">(Opening + Sales) - Drops</small>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-lightbulb"></i>
                                    <strong>Expected Balance:</strong> <?php echo formatCurrency($expected_balance, $settings); ?> 
                                    (Based on this cashier's sales and drops - should match your actual closing amount)
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3">
                                    <i class="bi bi-cash-coin"></i> Payment Breakdown
                                </h6>

                                <div class="mb-3">
                                    <label for="cash_amount" class="form-label">Actual Counted Cash Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><?php echo $settings['currency_symbol'] ?? 'KES'; ?></span>
                                        <input type="number" class="form-control" name="cash_amount" id="cash_amount"
                                               step="0.01" placeholder="0.00" required>
                                        <button type="button" class="btn btn-outline-secondary" onclick="autoFillExpectedAmount()" title="Auto-fill expected balance">
                                            <i class="bi bi-magic"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Enter the actual cash amount counted in the till</div>
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
                                            <span>Expected Balance:</span>
                                            <strong class="text-info" id="expected_balance_display"><?php echo formatCurrency($expected_balance ?? 0, $settings); ?></strong>
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
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Difference:</span>
                                            <span id="difference_display" class="text-muted"><?php echo $settings['currency_symbol'] ?? 'KES'; ?> 0.00</span>
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
                                        <div class="alert alert-sm mt-2 mb-0" id="difference_alert" style="display: none;">
                                            <i class="bi bi-info-circle"></i>
                                            <span id="difference_message"></span>
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

                        <!-- Security Confirmation -->
                        <div class="card mb-3">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">
                                    <i class="bi bi-shield-check"></i> Security Confirmation
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="confirm_close_till" class="form-label">Type "CLOSE TILL" to confirm:</label>
                                    <input type="text" class="form-control" id="confirm_close_till" name="confirm_close_till" 
                                           placeholder="Type CLOSE TILL to confirm" required>
                                    <div class="form-text">This confirmation is required for security purposes.</div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="confirm_balance_checked" name="confirm_balance_checked" required>
                                    <label class="form-check-label" for="confirm_balance_checked">
                                        I confirm that I have physically counted the cash in the till and verified the amounts.
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <?php if (isset($_SESSION['till_close_authenticated']) && $_SESSION['till_close_authenticated']): ?>
                            <button type="submit" class="btn btn-danger" id="closeTillSubmitBtn" disabled>
                                <i class="bi bi-lock"></i> Complete Confirmation
                            </button>
                            <a href="?action=logout_till_close" class="btn btn-outline-warning">
                                <i class="bi bi-person-dash"></i> Logout
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-danger" onclick="showCloseTillAuth()">
                                <i class="bi bi-shield-lock"></i> Authenticate First
                            </button>
                        <?php endif; ?>
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
            // Initialize Sign Out button state with delay to avoid blocking
            setTimeout(updateSignOutButton, 100);
            
            // Check if user is authenticated and show drop modal
            <?php if (isset($_SESSION['cash_drop_authenticated']) && $_SESSION['cash_drop_authenticated']): ?>
                setTimeout(function() {
                    showDrop();
                }, 500);
                <?php unset($_SESSION['intended_action']); ?>
            <?php endif; ?>
            
            // Check if user is authenticated and show close till modal
            <?php if (isset($_SESSION['till_close_authenticated']) && $_SESSION['till_close_authenticated'] && isset($_SESSION['intended_action']) && $_SESSION['intended_action'] === 'close_till'): ?>
                setTimeout(function() {
                    showCloseTillForm();
                }, 500);
                <?php unset($_SESSION['intended_action']); ?>
            <?php endif; ?>

            // Add form validation for authentication forms only
            const authForms = document.querySelectorAll('form[action=""][method="POST"]');
            authForms.forEach(function(form) {
                const userIdInput = form.querySelector('#auth_user_id, #till_close_auth_user_id');
                const passwordInput = form.querySelector('#auth_password, #till_close_auth_password');
                
                // Only add validation to forms that have authentication fields
                if (userIdInput && passwordInput) {
                    form.addEventListener('submit', function(e) {
                        const submitBtn = form.querySelector('#authSubmitBtn, #tillCloseSubmitBtn');
                        
                        if (!userIdInput || !passwordInput) return;
                    
                    // Validate inputs
                    if (!userIdInput.value.trim()) {
                        e.preventDefault();
                        alert('Please enter your User ID, Username, or Employee ID.');
                        userIdInput.focus();
                        return;
                    }
                    
                    if (!passwordInput.value.trim()) {
                        e.preventDefault();
                        alert('Please enter your password.');
                        passwordInput.focus();
                        return;
                    }
                    
                        // Show loading state
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Authenticating...';
                        }
                    });
                }
            });

            // Add form validation for drop form
            const dropForm = document.querySelector('form[method="POST"] input[name="action"][value="drop"]')?.closest('form');
            if (dropForm) {
                dropForm.addEventListener('submit', function(e) {
                    const submitBtn = document.getElementById('dropSubmitBtn');
                    const dropAmountInput = document.getElementById('drop_amount');
                    const confirmCheckbox = document.getElementById('confirm_drop');
                    const notesInput = document.getElementById('drop_notes');

                    if (!submitBtn || submitBtn.disabled) return;

                    // Validate drop amount
                    if (!dropAmountInput || !dropAmountInput.value.trim()) {
                        e.preventDefault();
                        alert('Please enter a drop amount.');
                        dropAmountInput.focus();
                        return;
                    }

                    const dropAmount = parseFloat(dropAmountInput.value);
                    if (isNaN(dropAmount) || dropAmount <= 0) {
                        e.preventDefault();
                        alert('Please enter a valid drop amount greater than zero.');
                        dropAmountInput.focus();
                        return;
                    }

                    // Validate confirmation checkbox
                    if (!confirmCheckbox || !confirmCheckbox.checked) {
                        e.preventDefault();
                        alert('Please check the confirmation box to acknowledge this action cannot be undone.');
                        confirmCheckbox.focus();
                        return;
                    }

                    // Get notes for confirmation
                    const notes = notesInput ? notesInput.value.trim() : '';
                    const notesText = notes ? `\n\nNotes: "${notes}"` : '';

                    // Detailed confirmation dialog
                    const confirmMessage =
                        '🚨 DROP CONFIRMATION 🚨\n\n' +
                        `Amount to Drop: $${dropAmount.toFixed(2)}\n` +
                        `Till: <?php echo htmlspecialchars($selected_till['till_name'] ?? 'Unknown'); ?>\n` +
                        `Cashier: <?php echo htmlspecialchars($_SESSION['cash_drop_username'] ?? 'Unknown'); ?>${notesText}\n\n` +
                        '⚠️  WARNING: This action will PERMANENTLY remove money from the till!\n\n' +
                        'Are you absolutely sure you want to proceed?';

                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return;
                    }

                    // Show loading state
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing Drop...';
                });
            }

            // Till dropdown selection event listener
            const tillDropdown = document.getElementById('till_dropdown');
            if (tillDropdown) {
                tillDropdown.addEventListener('change', function(e) {
                    onTillSelection();
                });
                
                // Also add input event listener as backup
                tillDropdown.addEventListener('input', function(e) {
                    onTillSelection();
                });
            }

            // Switch till event listeners
            document.querySelectorAll('input[name="switch_till_id"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    document.getElementById('confirmSwitchTill').disabled = false;
                });
            });

            const openingAmountInput = document.getElementById('opening_amount');
            if (openingAmountInput) {
                openingAmountInput.addEventListener('input', function() {
                    validateTillSelection();
                });
                openingAmountInput.addEventListener('change', function() {
                    validateTillSelection();
                });
                openingAmountInput.addEventListener('keyup', function() {
                    validateTillSelection();
                });
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

            // Initialize modal accessibility
            initializeModalAccessibility();


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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before continuing held transactions');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before voiding held transactions');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before voiding products');
            showTillSelection();
            return;
            <?php endif; ?>

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
            // Check if till is selected
            <?php if (!$selected_till): ?>
            alert('Please select a till before voiding cart');
            showTillSelection();
            return;
            <?php endif; ?>

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

        /* Search Section Redesign */
        .search-section {
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid #e9ecef !important;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .search-input-wrapper {
            position: relative;
        }

        .search-input {
            height: 48px;
            font-size: 1rem;
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding-left: 3rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1), 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }

        .search-icon {
            background: transparent;
            border: none;
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            color: #6b7280;
            font-size: 1.1rem;
        }

        .barcode-indicator {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 2px solid #3b82f6;
            border-radius: 8px;
            color: #1e40af;
            font-weight: 600;
        }

        .clear-btn {
            height: 48px;
            border-radius: 0 12px 12px 0;
            border: 2px solid #e9ecef;
            border-left: none;
            background: #f8fafc;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .clear-btn:hover {
            background: #ef4444;
            border-color: #ef4444;
            color: white;
            transform: translateY(-1px);
        }

        .category-filter-wrapper {
            padding-left: 1rem;
        }

        .filter-label {
            font-size: 0.95rem;
            font-weight: 600;
            color: #4b5563 !important;
            white-space: nowrap;
        }

        .filter-label i {
            color: #667eea;
        }

        .category-dropdown-container {
            min-width: 200px;
        }

        .category-dropdown {
            height: 48px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            color: #374151;
        }

        .category-dropdown:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1), 0 4px 12px rgba(0, 0, 0, 0.15);
            outline: none;
            transform: translateY(-1px);
        }

        .category-dropdown:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }

        /* Custom dropdown arrow styling */
        .category-dropdown {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23667eea' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m1 6 7 7 7-7'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px 12px;
            padding-right: 3rem;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .search-section {
                padding: 1rem 1.5rem;
            }
            
            .category-filter-wrapper {
                padding-left: 0;
                margin-top: 1rem;
            }

            .filter-label {
                margin-bottom: 0.5rem;
                display: block;
            }

            .category-dropdown-container {
                min-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .search-section {
                padding: 1rem;
            }

            .search-input, .category-dropdown, .clear-btn {
                height: 44px;
                font-size: 0.9rem;
            }

            .filter-label {
                font-size: 0.85rem;
            }
        }

        .product-card.disabled:hover {
            transform: none;
            box-shadow: none;
        }

        /* Till Action Buttons Styling */
        .till-action-btn {
            font-weight: 600;
            border: 1px solid transparent;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transition: all 0.2s ease;
            min-width: 60px;
            font-size: 0.75rem;
            padding: 0.25rem 0.4rem;
            line-height: 1.2;
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

        .till-action-btn.btn-info {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            border-color: #06b6d4;
            color: white;
        }

        .till-action-btn.btn-info:hover {
            background: linear-gradient(135deg, #0891b2, #0e7490);
            border-color: #0891b2;
            color: white;
            transform: translateY(-2px);
        }

        .till-action-btn.btn-dark {
            background: linear-gradient(135deg, #343a40, #212529);
            border-color: #343a40;
            color: white;
        }

        .till-action-btn.btn-dark:hover {
            background: linear-gradient(135deg, #212529, #000000);
            border-color: #212529;
            color: white;
            transform: translateY(-2px);
        }

        /* 3-Column Button Layout */
        .row.g-1 .col-4 {
            margin-bottom: 0.25rem;
        }

        /* Remove margin from the last row */
        .row.g-1 .col-4:nth-last-child(-n+3) {
            margin-bottom: 0;
        }

        /* Ensure buttons fill the column width properly */
        .till-action-btn.w-100 {
            width: 100% !important;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
        }

        /* Smaller icons for compact buttons */
        .till-action-btn i {
            font-size: 0.8rem;
        }
        
        /* Cart blocking notification styles */
        .cart-blocking-notification .notification-content {
            padding: 1.5rem;
        }
        
        .cart-blocking-notification .notification-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            color: #856404;
        }
        
        .cart-blocking-notification .notification-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .cart-blocking-notification .notification-body {
            margin-bottom: 1.5rem;
        }
        
        .cart-blocking-notification .notification-body p {
            margin-bottom: 0.5rem;
            color: #495057;
        }
        
        .cart-blocking-notification .notification-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .cart-blocking-notification .notification-actions .btn {
            flex: 1;
            max-width: 120px;
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

        /* Barcode Product Selection Modal Styles */
        .barcode-product-item {
            border: 2px solid transparent;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .barcode-product-item:hover {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }

        .barcode-product-item.out-of-stock {
            opacity: 0.6;
            cursor: not-allowed;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }

        .barcode-product-item.out-of-stock:hover {
            transform: none;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-color: transparent;
        }

        .barcode-product-item h6 {
            color: #1f2937;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .barcode-product-item .badge {
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Barcode scanning indicator */
        .barcode-scanning {
            position: relative;
        }

        .barcode-scanning::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.3), transparent);
            animation: barcodeScan 1.5s infinite;
        }

        @keyframes barcodeScan {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Scan feedback animations */
        @keyframes slideInDown {
            0% {
                transform: translateX(-50%) translateY(-20px);
                opacity: 0;
            }
            100% {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideOutUp {
            0% {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            100% {
                transform: translateX(-50%) translateY(-20px);
                opacity: 0;
            }
        }

        /* Quick feedback animations for rapid scanning */
        @keyframes quickSlideIn {
            0% {
                transform: translateX(100%);
                opacity: 0;
            }
            100% {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes quickSlideOut {
            0% {
                transform: translateX(0);
                opacity: 1;
            }
            100% {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Enhanced barcode scanning indicator */
        .search-input.barcode-scanning {
            border-color: #10b981 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2), 0 4px 12px rgba(0, 0, 0, 0.15) !important;
            animation: scanPulse 1s infinite;
        }

        @keyframes scanPulse {
            0%, 100% {
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2), 0 4px 12px rgba(0, 0, 0, 0.15);
            }
            50% {
                box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.1), 0 4px 12px rgba(0, 0, 0, 0.15);
            }
        }

        /* Scan ready state */
        .search-input:focus {
            border-color: #667eea !important;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1), 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }

        /* Quick scan mode styling */
        .pos-main.scan-mode .search-input {
            border-color: #10b981;
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
        }

        /* Scanned cart item styling */
        .cart-item-scanned {
            border-left: 4px solid #10b981;
            animation: itemAdded 0.3s ease-out;
        }

        @keyframes itemAdded {
            0% {
                transform: translateX(-10px);
                opacity: 0.7;
                background: #ecfdf5;
            }
            100% {
                transform: translateX(0);
                opacity: 1;
                background: inherit;
            }
        }

        /* Quantity badge for scanned items */
        .quantity-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-weight: 700;
            font-size: 0.9rem;
            min-width: 40px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
            border: 2px solid white;
        }

        /* Simplified quantity controls for rapid scanning */
        .cart-item-scanned .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Enhanced remove button for scanned items */
        .cart-item-scanned .btn-outline-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 2px solid #ef4444;
            color: #dc2626;
            padding: 0.25rem;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .cart-item-scanned .btn-outline-danger:hover {
            background: #ef4444;
            color: white;
            transform: scale(1.1);
        }
    </style>

    <!-- Drop Slips Modal -->
    <div class="modal fade" id="dropSlipsModal" tabindex="-1" aria-labelledby="dropSlipsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="dropSlipsModalLabel">
                        <i class="bi bi-printer"></i> Drop Slips Generated
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (isset($_SESSION['drop_slips_html'])): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Auto-printing failed or you requested manual printing. Use the buttons below to print the slips manually.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-center mb-3">
                                    <i class="bi bi-building"></i> OFFICE COPY
                                </h6>
                                <div class="border rounded p-2 bg-light">
                                    <iframe id="officeSlipFrame" style="width: 100%; height: 400px; border: none;"></iframe>
                                </div>
                                <div class="text-center mt-2">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="printSlip('office')">
                                        <i class="bi bi-printer"></i> Print Office Copy
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-center mb-3">
                                    <i class="bi bi-person-badge"></i> CASHIER COPY
                                </h6>
                                <div class="border rounded p-2 bg-light">
                                    <iframe id="cashierSlipFrame" style="width: 100%; height: 400px; border: none;"></iframe>
                                </div>
                                <div class="text-center mt-2">
                                    <button type="button" class="btn btn-success btn-sm" onclick="printSlip('cashier')">
                                        <i class="bi bi-printer"></i> Print Cashier Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printBothSlips()">
                        <i class="bi bi-printer"></i> Print Both Copies
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Till Closing Slip Modal -->
    <div class="modal fade" id="tillClosingSlipModal" tabindex="-1" aria-labelledby="tillClosingSlipModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="tillClosingSlipModalLabel">
                        <i class="bi bi-printer"></i> Till Closing Slip Generated
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i>
                        <strong>Till closing slip generated successfully!</strong> You can print the slip manually if needed.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-building"></i> Office Copy
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <iframe id="officeTillClosingSlipFrame" style="width: 100%; height: 500px; border: none;"></iframe>
                                </div>
                                <div class="card-footer">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="printTillClosingSlip('office')">
                                        <i class="bi bi-printer"></i> Print Office Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-person"></i> Cashier Copy
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <iframe id="cashierTillClosingSlipFrame" style="width: 100%; height: 500px; border: none;"></iframe>
                                </div>
                                <div class="card-footer">
                                    <button type="button" class="btn btn-info btn-sm" onclick="printTillClosingSlip('cashier')">
                                        <i class="bi bi-printer"></i> Print Cashier Copy
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-danger" onclick="printBothTillClosingSlips()">
                        <i class="bi bi-printer"></i> Print Both Copies
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load drop slips into iframes when modal is shown
        document.getElementById('dropSlipsModal').addEventListener('show.bs.modal', function() {
            setTimeout(() => {
                const officeFrame = document.getElementById('officeSlipFrame');
                const cashierFrame = document.getElementById('cashierSlipFrame');

                if (officeFrame && cashierFrame) {
                    // Load office slip
                    const officeDoc = officeFrame.contentDocument || officeFrame.contentWindow.document;
                    officeDoc.open();
                    officeDoc.write(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Office Drop Slip</title>
                            <style>
                                @page { size: 80mm auto; margin: 0mm; }
                                @media print {
                                    html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                                    .no-print { display: none !important; }
                                    .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                                }
                                body { font-family: 'Courier New', monospace; margin: 0; padding: 10px; }
                                .receipt-container { max-width: 300px; margin: 0 auto; border: 1px solid #ccc; padding: 10px; background: white; }
                                .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                                .content { margin: 10px 0; }
                                .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                                .label { font-weight: bold; }
                                .office-copy { background: #f8f9fa; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                            </style>
                        </head>
                        <body>
                            <div class="receipt-container">
                                <div class="office-copy">OFFICE COPY</div>
                                <?php echo $_SESSION['drop_slips_html'] ?? ''; ?>
                            </div>
                        </body>
                        </html>
                    `);
                    officeDoc.close();

                    // Load cashier slip
                    const cashierDoc = cashierFrame.contentDocument || cashierFrame.contentWindow.document;
                    cashierDoc.open();
                    cashierDoc.write(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Cashier Drop Slip</title>
                            <style>
                                @page { size: 80mm auto; margin: 0mm; }
                                @media print {
                                    html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                                    .no-print { display: none !important; }
                                    .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                                }
                                body { font-family: 'Courier New', monospace; margin: 0; padding: 10px; }
                                .receipt-container { max-width: 300px; margin: 0 auto; border: 1px solid #ccc; padding: 10px; background: white; }
                                .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                                .content { margin: 10px 0; }
                                .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                                .label { font-weight: bold; }
                                .cashier-copy { background: #e8f5e8; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                            </style>
                        </head>
                        <body>
                            <div class="receipt-container">
                                <div class="cashier-copy">CASHIER COPY</div>
                                <?php echo $_SESSION['drop_slips_html'] ?? ''; ?>
                            </div>
                        </body>
                        </html>
                    `);
                    cashierDoc.close();
                }
            }, 100);
        });

        // Print individual slips
        function printSlip(type) {
            const frameId = type === 'office' ? 'officeSlipFrame' : 'cashierSlipFrame';
            const frame = document.getElementById(frameId);
            if (frame) {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    alert('Printing failed. Please use Ctrl+P (or Cmd+P on Mac) to print manually.');
                }
            }
        }

        // Print both slips
        function printBothSlips() {
            printSlip('office');
            setTimeout(() => printSlip('cashier'), 1000);
        }

        // Show drop slips modal for manual printing
        function showDropSlipsModal() {
            const modal = new bootstrap.Modal(document.getElementById('dropSlipsModal'));
            modal.show();
        }

        // Show till closing slip modal for manual printing
        function showTillClosingSlipModal() {
            const modal = new bootstrap.Modal(document.getElementById('tillClosingSlipModal'));
            modal.show();
        }

        // Print individual till closing slips
        function printTillClosingSlip(type) {
            const frameId = type === 'office' ? 'officeTillClosingSlipFrame' : 'cashierTillClosingSlipFrame';
            const frame = document.getElementById(frameId);
            if (frame) {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    console.error('Error printing till closing slip:', e);
                }
            }
        }

        // Print both till closing slips
        function printBothTillClosingSlips() {
            printTillClosingSlip('office');
            setTimeout(() => printTillClosingSlip('cashier'), 1000);
        }

        // Auto-print drop slips if needed
        <?php if (isset($_SESSION['auto_print_drop']) && $_SESSION['auto_print_drop']): ?>
            setTimeout(function() {
                showSuccessNotification('Printing drop slips...');
                autoPrintDropSlips();
                <?php
                unset($_SESSION['auto_print_drop']);
                // Clear drop slips HTML after 30 seconds to free memory
                ?>
                setTimeout(() => {
                    fetch(window.location.href, { method: 'POST', body: new FormData() });
                }, 30000);
            }, 500);
        <?php endif; ?>

        // Auto-print till closing slip if needed
        <?php if (isset($_SESSION['auto_print_till_closing']) && $_SESSION['auto_print_till_closing']): ?>
            setTimeout(function() {
                showSuccessNotification('Printing till closing slip...');
                autoPrintTillClosingSlip();
                <?php unset($_SESSION['auto_print_till_closing']); ?>
            }, 2000);
        <?php endif; ?>

        // Global modal focus management
        document.addEventListener('DOMContentLoaded', function() {
            // Handle all modals for proper focus management
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                // Initialize modal with proper attributes
                modal.setAttribute('aria-hidden', 'true');
                modal.setAttribute('inert', '');
                
                modal.addEventListener('show.bs.modal', function() {
                    // Prepare modal before showing
                    this.removeAttribute('inert');
                });
                
                modal.addEventListener('shown.bs.modal', function() {
                    manageModalFocus(this);
                });
                
                modal.addEventListener('hide.bs.modal', function() {
                    // Prepare modal before hiding
                    const focusedElement = this.querySelector(':focus');
                    if (focusedElement) {
                        focusedElement.blur();
                    }
                });
                
                modal.addEventListener('hidden.bs.modal', function() {
                    hideModalProperly(this);
                });
            });
        });

        // Success notification function
        function showSuccessNotification(message) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = 'alert alert-success alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="bi bi-check-circle"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(notification);

            // Auto-remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Auto-print drop slips
        function autoPrintDropSlips() {
            // Create hidden iframes for printing
            const officeFrame = document.createElement('iframe');
            const cashierFrame = document.createElement('iframe');

            officeFrame.style.display = 'none';
            cashierFrame.style.display = 'none';
            officeFrame.id = 'auto_office_frame';
            cashierFrame.id = 'auto_cashier_frame';

            document.body.appendChild(officeFrame);
            document.body.appendChild(cashierFrame);

            // Load office slip
            const officeDoc = officeFrame.contentDocument || officeFrame.contentWindow.document;
            officeDoc.open();
            officeDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Office Drop Slip</title>
                    <style>
                        @page { size: 80mm auto; margin: 0mm; }
                        @media print {
                            html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                            .no-print { display: none !important; }
                            .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                        }
                        body { font-family: 'Courier New', monospace; margin: 0; padding: 0; }
                        .receipt-container { max-width: 300px; margin: 0; padding: 10px; background: white; }
                        .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                        .content { margin: 10px 0; }
                        .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                        .label { font-weight: bold; }
                        .office-copy { background: #f8f9fa; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="office-copy">OFFICE COPY</div>
                        <?php echo $_SESSION['drop_slips_html'] ?? ''; ?>
                    </div>
                </body>
                </html>
            `);
            officeDoc.close();

            // Load cashier slip
            const cashierDoc = cashierFrame.contentDocument || cashierFrame.contentWindow.document;
            cashierDoc.open();
            cashierDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Cashier Drop Slip</title>
                    <style>
                        @page { size: 80mm auto; margin: 0mm; }
                        @media print {
                            html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                            .no-print { display: none !important; }
                            .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                        }
                        body { font-family: 'Courier New', monospace; margin: 0; padding: 0; }
                        .receipt-container { max-width: 300px; margin: 0; padding: 10px; background: white; }
                        .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                        .content { margin: 10px 0; }
                        .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                        .label { font-weight: bold; }
                        .cashier-copy { background: #e8f5e8; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="cashier-copy">CASHIER COPY</div>
                        <?php echo $_SESSION['drop_slips_html'] ?? ''; ?>
                    </div>
                </body>
                </html>
            `);
            cashierDoc.close();

            // Print office copy first
            setTimeout(() => {
                try {
                    officeFrame.contentWindow.focus();
                    officeFrame.contentWindow.print();
                } catch (e) {
                    console.warn('Auto print failed for office copy:', e);
                }
            }, 100);

            // Print cashier copy after delay
            setTimeout(() => {
                try {
                    cashierFrame.contentWindow.focus();
                    cashierFrame.contentWindow.print();
                } catch (e) {
                    console.warn('Auto print failed for cashier copy:', e);
                }

                // Clean up iframes
                setTimeout(() => {
                    document.body.removeChild(officeFrame);
                    document.body.removeChild(cashierFrame);
                }, 2000);
            }, 1500);
        }

        // Auto-print till closing slip
        function autoPrintTillClosingSlip() {
            // Create hidden iframes for printing
            const officeFrame = document.createElement('iframe');
            const cashierFrame = document.createElement('iframe');

            officeFrame.style.display = 'none';
            cashierFrame.style.display = 'none';
            officeFrame.id = 'auto_office_till_closing_frame';
            cashierFrame.id = 'auto_cashier_till_closing_frame';

            document.body.appendChild(officeFrame);
            document.body.appendChild(cashierFrame);

            // Load office slip
            const officeDoc = officeFrame.contentDocument || officeFrame.contentWindow.document;
            officeDoc.open();
            officeDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Office Till Closing Slip</title>
                    <style>
                        @page { size: 80mm auto; margin: 0mm; }
                        @media print {
                            html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                            .no-print { display: none !important; }
                            .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                        }
                        body { font-family: 'Courier New', monospace; margin: 0; padding: 0; }
                        .receipt-container { max-width: 300px; margin: 0; padding: 10px; background: white; }
                        .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                        .content { margin: 10px 0; }
                        .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                        .label { font-weight: bold; }
                        .office-copy { background: #f8f9fa; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="office-copy">OFFICE COPY</div>
                        ${<?php echo json_encode($_SESSION['till_closing_slip_html'] ?? ''); ?>}
                    </div>
                </body>
                </html>
            `);
            officeDoc.close();

            // Load cashier slip
            const cashierDoc = cashierFrame.contentDocument || cashierFrame.contentWindow.document;
            cashierDoc.open();
            cashierDoc.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Cashier Till Closing Slip</title>
                    <style>
                        @page { size: 80mm auto; margin: 0mm; }
                        @media print {
                            html, body { margin: 0 !important; padding: 0 !important; font-size: 13px !important; line-height: 1.35 !important; color: #000 !important; background: white !important; -webkit-print-color-adjust: exact !important; }
                            .no-print { display: none !important; }
                            .receipt-container { max-width: 80mm !important; width: 80mm !important; margin: 0 auto !important; box-shadow: none !important; border: none !important; page-break-inside: avoid; padding: 4px 2px !important; font-family: 'Courier New', monospace !important; font-weight: bold !important; display: block !important; height: auto !important; }
                        }
                        body { font-family: 'Courier New', monospace; margin: 0; padding: 0; }
                        .receipt-container { max-width: 300px; margin: 0; padding: 10px; background: white; }
                        .header { text-align: center; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; }
                        .content { margin: 10px 0; }
                        .footer { text-align: center; border-top: 1px solid #000; padding-top: 5px; margin-top: 10px; font-size: 10px; }
                        .label { font-weight: bold; }
                        .cashier-copy { background: #e3f2fd; padding: 5px; text-align: center; margin-bottom: 10px; font-size: 12px; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="receipt-container">
                        <div class="cashier-copy">CASHIER COPY</div>
                        ${<?php echo json_encode($_SESSION['till_closing_slip_html'] ?? ''); ?>}
                    </div>
                </body>
                </html>
            `);
            cashierDoc.close();

            // Print office copy first
            setTimeout(() => {
                try {
                    officeFrame.contentWindow.focus();
                    officeFrame.contentWindow.print();
                } catch (e) {
                    console.warn('Auto print failed for office copy:', e);
                }
            }, 100);

            // Print cashier copy after delay
            setTimeout(() => {
                try {
                    cashierFrame.contentWindow.focus();
                    cashierFrame.contentWindow.print();
                } catch (e) {
                    console.warn('Auto print failed for cashier copy:', e);
                }

                // Clean up iframes
                setTimeout(() => {
                    document.body.removeChild(officeFrame);
                    document.body.removeChild(cashierFrame);
                }, 2000);
            }, 1500);
        }

        // Close Till Confirmation Validation
        function validateCloseTillForm() {
            const confirmInput = document.getElementById('confirm_close_till');
            const balanceCheckbox = document.getElementById('confirm_balance_checked');
            const submitButton = document.getElementById('closeTillSubmitBtn');
            
            if (confirmInput && balanceCheckbox && submitButton) {
                function checkValidation() {
                    const isConfirmed = confirmInput.value.toUpperCase().trim() === 'CLOSE TILL';
                    const isBalanceChecked = balanceCheckbox.checked;
                    
                    if (isConfirmed && isBalanceChecked) {
                        submitButton.disabled = false;
                        submitButton.classList.remove('btn-secondary');
                        submitButton.classList.add('btn-danger');
                        submitButton.innerHTML = '<i class="bi bi-x-circle"></i> Close Till';
                    } else {
                        submitButton.disabled = true;
                        submitButton.classList.remove('btn-danger');
                        submitButton.classList.add('btn-secondary');
                        submitButton.innerHTML = '<i class="bi bi-lock"></i> Complete Confirmation';
                    }
                }
                
                // Initial check
                checkValidation();
                
                // Add event listeners
                confirmInput.addEventListener('input', checkValidation);
                balanceCheckbox.addEventListener('change', checkValidation);
            }
        }

        // Initialize close till validation when modal is shown
        document.addEventListener('DOMContentLoaded', function() {
            const closeTillModal = document.getElementById('closeTillModal');
            if (closeTillModal) {
                closeTillModal.addEventListener('shown.bs.modal', function() {
                    validateCloseTillForm();
                });
            }
        });
    </script>
</body>
</html>

<?php
/**
 * Generate HTML content for drop slips
 */
function generateDropSlipsHTML($data) {
    $company_name = htmlspecialchars($data['company_name']);
    $drop_amount = number_format($data['drop_amount'], 2);
    $till_name = htmlspecialchars($data['till_name']);
    $till_number = $data['till_number'];
    $cashier_name = htmlspecialchars($data['cashier_name']);
    $dropper_name = htmlspecialchars($data['dropper_name']);
    $drop_date = date('d/m/Y H:i', strtotime($data['drop_date']));
    $drop_id = $data['drop_id'];
    $notes = htmlspecialchars($data['notes']);

    $html = "
        <div class='header'>
            <div style='font-size: 14px; font-weight: bold;'>{$company_name}</div>
            <div style='font-size: 12px;'>DROP SLIP</div>
        </div>

        <div class='content'>
            <div style='margin: 5px 0;'><span class='label'>Drop ID:</span> {$drop_id}</div>
            <div style='margin: 5px 0;'><span class='label'>Date/Time:</span> {$drop_date}</div>
            <div style='margin: 5px 0;'><span class='label'>Till:</span> {$till_name} (ID: {$till_number})</div>
            <div style='margin: 5px 0;'><span class='label'>Cashier:</span> {$cashier_name}</div>
            <div style='margin: 5px 0;'><span class='label'>Dropped By:</span> {$dropper_name}</div>
            <div style='margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; text-align: center;'>
                <div style='font-size: 16px; font-weight: bold;'>AMOUNT DROPPED</div>
                <div style='font-size: 18px; font-weight: bold;'>\${$drop_amount}</div>
            </div>";

    if (!empty($notes)) {
        $html .= "<div style='margin: 5px 0; font-size: 10px;'><span class='label'>Notes:</span> {$notes}</div>";
    }

    $html .= "
            <div style='margin: 10px 0; text-align: center; font-size: 10px;'>
                This drop has been recorded in the system.<br>
                Keep this slip for your records.
            </div>
        </div>

        <div class='footer'>
            <div>Generated by POS System</div>
            <div style='margin-top: 3px;'>Thank you!</div>
        </div>
    ";

    return $html;
}

/**
 * Generate HTML content for till closing slip
 */
function generateTillClosingSlipHTML($data) {
    $company_name = htmlspecialchars($data['company_name']);
    $company_address = htmlspecialchars($data['company_address']);
    $company_phone = htmlspecialchars($data['company_phone']);
    $till_name = htmlspecialchars($data['till_name']);
    $till_code = htmlspecialchars($data['till_code']);
    $cashier_name = htmlspecialchars($data['cashier_name']);
    $closing_user_name = htmlspecialchars($data['closing_user_name']);
    $closing_date = date('d/m/Y H:i', strtotime($data['closing_date']));
    $currency_symbol = $data['currency_symbol'];
    
    // Format amounts
    $opening_amount = number_format($data['opening_amount'], 2);
    $total_sales = number_format($data['total_sales'], 2);
    $total_drops = number_format($data['total_drops'], 2);
    $expected_balance = number_format($data['expected_balance'], 2);
    $actual_cash = number_format($data['actual_cash'], 2);
    $voucher_amount = number_format($data['voucher_amount'], 2);
    $loyalty_points = number_format($data['loyalty_points'], 2);
    $other_amount = number_format($data['other_amount'], 2);
    $total_closing = number_format($data['total_closing'], 2);
    $difference = number_format($data['difference'], 2);
    
    $closing_notes = htmlspecialchars($data['closing_notes']);
    $other_description = htmlspecialchars($data['other_description']);

    $html = "
        <div class='header'>
            <div style='font-size: 16px; font-weight: bold; text-align: center;'>{$company_name}</div>";
    
    if (!empty($company_address)) {
        $html .= "<div style='font-size: 12px; text-align: center;'>{$company_address}</div>";
    }
    
    if (!empty($company_phone)) {
        $html .= "<div style='font-size: 12px; text-align: center;'>{$company_phone}</div>";
    }
    
    $html .= "
            <div style='font-size: 14px; font-weight: bold; text-align: center; margin-top: 10px;'>TILL CLOSING SLIP</div>
        </div>

        <div class='content'>
            <div style='margin: 5px 0;'><span class='label'>Till:</span> {$till_name} ({$till_code})</div>
            <div style='margin: 5px 0;'><span class='label'>Closing Date:</span> {$closing_date}</div>
            <div style='margin: 5px 0;'><span class='label'>Cashier:</span> {$cashier_name}</div>
            <div style='margin: 5px 0;'><span class='label'>Closed By:</span> {$closing_user_name}</div>
            
            <div style='margin: 15px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0;'>
                <div style='font-size: 14px; font-weight: bold; text-align: center; margin-bottom: 10px;'>TILL CALCULATION</div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Opening Amount:</span><span>{$currency_symbol} {$opening_amount}</span></div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Total Sales:</span><span>{$currency_symbol} {$total_sales}</span></div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Total Drops:</span><span>{$currency_symbol} {$total_drops}</span></div>
                <div style='margin: 8px 0; padding-top: 5px; border-top: 1px solid #000; display: flex; justify-content: space-between; font-weight: bold;'><span>Expected Balance:</span><span>{$currency_symbol} {$expected_balance}</span></div>
            </div>
            
            <div style='margin: 15px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 10px 0;'>
                <div style='font-size: 14px; font-weight: bold; text-align: center; margin-bottom: 10px;'>ACTUAL COUNT</div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Cash Counted:</span><span>{$currency_symbol} {$actual_cash}</span></div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Voucher Amount:</span><span>{$currency_symbol} {$voucher_amount}</span></div>
                <div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Loyalty Points:</span><span>{$currency_symbol} {$loyalty_points}</span></div>";
    
    if ($data['other_amount'] > 0) {
        $html .= "<div style='margin: 3px 0; display: flex; justify-content: space-between;'><span>Other ({$other_description}):</span><span>{$currency_symbol} {$other_amount}</span></div>";
    }
    
    $html .= "
                <div style='margin: 8px 0; padding-top: 5px; border-top: 1px solid #000; display: flex; justify-content: space-between; font-weight: bold;'><span>Total Counted:</span><span>{$currency_symbol} {$total_closing}</span></div>
            </div>
            
            <div style='margin: 15px 0; text-align: center; padding: 10px; background-color: #f8f9fa; border: 1px solid #000;'>
                <div style='font-size: 14px; font-weight: bold; margin-bottom: 5px;'>DIFFERENCE</div>
                <div style='font-size: 18px; font-weight: bold; color: " . (abs($data['difference']) <= 0.01 ? '#28a745' : ($data['difference'] > 0 ? '#dc3545' : '#ffc107')) . ";'>{$currency_symbol} {$difference}</div>
            </div>";

    if (!empty($closing_notes)) {
        $html .= "<div style='margin: 10px 0; padding: 5px; background-color: #f8f9fa; border: 1px dashed #000;'><span class='label'>Notes:</span> {$closing_notes}</div>";
    }

    $html .= "
            <div style='margin: 15px 0; text-align: center; font-size: 10px;'>
                Till has been closed and reset to zero.<br>
                This slip serves as proof of till closing.
            </div>
        </div>

        <div class='footer'>
            <div style='text-align: center; font-size: 10px;'>Generated by POS System</div>
            <div style='text-align: center; font-size: 10px; margin-top: 3px;'>Thank you!</div>
        </div>
    ";

    return $html;
}
?>
