<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Check if reception is authenticated for AJAX requests
    $reception_authenticated = $_SESSION['reception_authenticated'] ?? false;
    $is_admin = false;
    
    // Check if user is authenticated through main system (optional)
    if (isset($_SESSION['role_name'])) {
        $is_admin = strtolower($_SESSION['role_name']) === 'admin' || strtolower($_SESSION['role_name']) === 'administrator';
    }
    
    // Allow access if either reception authenticated OR admin through main system
    if (!$reception_authenticated && !$is_admin) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'error' => 'Reception authentication required',
            'message' => 'Please authenticate for reception access',
            'redirect' => 'reception.php'
        ]);
        exit();
    }
    
    // Update activity time for AJAX requests
    if ($reception_authenticated) {
        $_SESSION['reception_last_activity'] = time();
    } else {
        $_SESSION['last_activity'] = time();
    }
    
    // Catch any PHP errors and return them as JSON
    try {
    
    if ($_POST['action'] === 'add_customer') {
        try {
            // Sanitize and validate input data
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $date_of_birth = trim($_POST['date_of_birth'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $customer_type = trim($_POST['customer_type'] ?? 'individual');
            $company_name = trim($_POST['company_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $zip_code = trim($_POST['zip_code'] ?? '');
            $country = trim($_POST['country'] ?? 'USA');
            $membership_level = trim($_POST['membership_level'] ?? 'Bronze');
            $notes = trim($_POST['notes'] ?? '');
            
            // Validation
            if (empty($first_name) || empty($last_name)) {
                echo json_encode(['success' => false, 'message' => 'First name and last name are required']);
                exit();
            }
            
            // Validate email if provided
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address']);
                exit();
            }
            
            // Check if email already exists
            if (!empty($email)) {
                $email_check = $conn->prepare("SELECT id FROM customers WHERE email = ? AND customer_type != 'walk_in'");
                $email_check->execute([$email]);
                if ($email_check->rowCount() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Email address already exists']);
                    exit();
                }
            }
            
            // Generate unique customer number
            $customer_number = generateCustomerNumber($conn);
            
            // Insert customer
            $stmt = $conn->prepare("
                INSERT INTO customers (
                    customer_number, first_name, last_name, phone, email, 
                    date_of_birth, gender, customer_type, company_name, 
                    address, city, state, zip_code, country, 
                    membership_level, notes, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            
            $result = $stmt->execute([
                $customer_number, $first_name, $last_name, $phone, $email,
                $date_of_birth ?: null, $gender ?: null, $customer_type, $company_name,
                $address, $city, $state, $zip_code, $country,
                $membership_level, $notes, $_SESSION['user_id']
            ]);
            
            if ($result) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Customer added successfully',
                    'customer_id' => $conn->lastInsertId(),
                    'customer_number' => $customer_number
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add customer']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'search_customer') {
        try {
            $search_term = trim($_POST['search_term'] ?? '');
            
            if (empty($search_term)) {
                echo json_encode(['success' => false, 'message' => 'Search term is required']);
                exit();
            }
            
            // Clean search term for phone number search
            $clean_phone = preg_replace('/[^0-9]/', '', $search_term); // Remove non-numeric characters
            
            // Search customers by name, phone, email, or customer number
            $stmt = $conn->prepare("
                SELECT id, customer_number, first_name, last_name, phone, mobile, email, 
                       customer_type, membership_level, loyalty_points, created_at
                FROM customers 
                WHERE customer_type != 'walk_in' 
                AND (
                    CONCAT(first_name, ' ', last_name) LIKE ? OR
                    first_name LIKE ? OR
                    last_name LIKE ? OR
                    phone LIKE ? OR
                    mobile LIKE ? OR
                    REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ? OR
                    REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ? OR
                    email LIKE ? OR
                    customer_number LIKE ? OR
                    REPLACE(customer_number, '-', '') LIKE ? OR
                    company_name LIKE ?
                )
                ORDER BY 
                    CASE 
                        WHEN customer_number = ? THEN 1
                        WHEN phone = ? OR mobile = ? THEN 2
                        WHEN email = ? THEN 3
                        WHEN CONCAT(first_name, ' ', last_name) = ? THEN 4
                        ELSE 5
                    END,
                    first_name, last_name
                LIMIT 15
            ");
            
            $search_pattern = '%' . $search_term . '%';
            $clean_phone_pattern = '%' . $clean_phone . '%';
            $clean_customer_number = str_replace('-', '', $search_term);
            
            $stmt->execute([
                $search_pattern, $search_pattern, $search_pattern, // name searches
                $search_pattern, $search_pattern, // phone/mobile with formatting
                $clean_phone_pattern, $clean_phone_pattern, // cleaned phone numbers
                $search_pattern, // email
                $search_pattern, // customer number
                $clean_customer_number, // customer number without dashes
                $search_pattern, // company name
                // Exact match priorities for ordering
                $search_term, $search_term, $search_term, $search_term, $search_term
            ]);
            
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'customers' => $customers,
                'count' => count($customers)
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_customer_loyalty') {
        try {
            $customer_id = intval($_POST['customer_id'] ?? 0);
            
            if ($customer_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                exit();
            }
            
            // Get customer details with loyalty information
            $stmt = $conn->prepare("
                SELECT c.*, 
                       COALESCE(c.loyalty_points, 0) as current_points,
                       COALESCE(loyalty_earned.total_earned, 0) as total_earned,
                       COALESCE(loyalty_redeemed.total_redeemed, 0) as total_redeemed
                FROM customers c
                LEFT JOIN (
                    SELECT customer_id, SUM(points_earned) as total_earned
                    FROM loyalty_transactions 
                    WHERE transaction_type = 'earned'
                    GROUP BY customer_id
                ) loyalty_earned ON c.id = loyalty_earned.customer_id
                LEFT JOIN (
                    SELECT customer_id, SUM(points_redeemed) as total_redeemed
                    FROM loyalty_transactions 
                    WHERE transaction_type = 'redeemed'
                    GROUP BY customer_id
                ) loyalty_redeemed ON c.id = loyalty_redeemed.customer_id
                WHERE c.id = ? AND c.customer_type != 'walk_in'
            ");
            
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
                exit();
            }
            
            echo json_encode([
                'success' => true,
                'customer' => $customer
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'search_receipts_by_customer') {
        try {
            $search_term = trim($_POST['search_term'] ?? '');
            
            if (empty($search_term)) {
                echo json_encode(['success' => false, 'message' => 'Search term is required']);
                exit();
            }
            
            // Search receipts by customer information
            $stmt = $conn->prepare("
                SELECT s.id, s.created_at, s.customer_name, s.customer_phone, s.customer_email,
                       s.final_amount, s.payment_method, s.total_paid, s.change_due,
                       COUNT(si.id) as item_count,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                WHERE (
                    s.customer_name LIKE ? OR
                    s.customer_phone LIKE ? OR
                    s.customer_email LIKE ?
                )
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT 20
            ");
            
            $search_pattern = '%' . $search_term . '%';
            $stmt->execute([$search_pattern, $search_pattern, $search_pattern]);
            
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'receipts' => $receipts,
                'count' => count($receipts)
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'search_receipts_by_date') {
        try {
            $date = trim($_POST['date'] ?? '');
            $receipt_number = trim($_POST['receipt_number'] ?? '');
            
            if (empty($date)) {
                echo json_encode(['success' => false, 'message' => 'Date is required']);
                exit();
            }
            
            $where_conditions = ["DATE(s.created_at) = ?"];
            $params = [$date];
            
            if (!empty($receipt_number)) {
                // Extract numeric part from receipt number
                $numeric_part = preg_replace('/[^0-9]/', '', $receipt_number);
                if (!empty($numeric_part)) {
                    $where_conditions[] = "s.id = ?";
                    $params[] = intval($numeric_part);
                }
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $stmt = $conn->prepare("
                SELECT s.id, s.created_at, s.customer_name, s.customer_phone, s.customer_email,
                       s.final_amount, s.payment_method, s.total_paid, s.change_due,
                       COUNT(si.id) as item_count,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                WHERE {$where_clause}
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT 50
            ");
            
            $stmt->execute($params);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'receipts' => $receipts,
                'count' => count($receipts)
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Search error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_receipt_details') {
        try {
            $sale_id = intval($_POST['sale_id'] ?? 0);
            
            if ($sale_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
                exit();
            }
            
            // Get sale details
            $stmt = $conn->prepare("
                SELECT s.*, u.username as cashier_name,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.id = ?
            ");
            $stmt->execute([$sale_id]);
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sale) {
                echo json_encode(['success' => false, 'message' => 'Receipt not found']);
                exit();
            }
            
            // Get sale items
            $stmt = $conn->prepare("
                SELECT si.*, p.name as product_name, p.sku
                FROM sale_items si
                LEFT JOIN products p ON si.product_id = p.id
                WHERE si.sale_id = ?
                ORDER BY si.id
            ");
            $stmt->execute([$sale_id]);
            $sale_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'sale' => $sale,
                'items' => $sale_items
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    if ($_POST['action'] === 'get_sales_today') {
        try {
            // Get today's sales with privacy controls
            $stmt = $conn->prepare("
                SELECT s.id, s.created_at, s.customer_name, s.customer_phone, s.customer_email,
                       s.final_amount, s.payment_method, s.total_paid, s.change_due,
                       COUNT(si.id) as item_count, s.user_id, u.username as cashier_name,
                       CONCAT('RCP-', LPAD(s.id, 6, '0')) as receipt_number
                FROM sales s
                LEFT JOIN sale_items si ON s.id = si.sale_id
                LEFT JOIN users u ON s.user_id = u.id
                WHERE DATE(s.created_at) = CURDATE()
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Privacy controls - mask sensitive customer data for non-admin users
            $user_role = $_SESSION['role'] ?? 'user';
            if ($user_role !== 'admin' && $user_role !== 'manager') {
                foreach ($sales as &$sale) {
                    if (!empty($sale['customer_phone'])) {
                        $sale['customer_phone'] = substr($sale['customer_phone'], 0, 3) . '****' . substr($sale['customer_phone'], -2);
                    }
                    if (!empty($sale['customer_email'])) {
                        $email_parts = explode('@', $sale['customer_email']);
                        if (count($email_parts) === 2) {
                            $sale['customer_email'] = substr($email_parts[0], 0, 2) . '****@' . $email_parts[1];
                        }
                    }
                }
            }
            
            // Calculate summary
            $total_amount = array_sum(array_column($sales, 'final_amount'));
            $total_transactions = count($sales);
            $average_sale = $total_transactions > 0 ? $total_amount / $total_transactions : 0;
            
            $summary = [
                'total_transactions' => $total_transactions,
                'total_amount' => $total_amount,
                'average_sale' => $average_sale,
                'currency_symbol' => $settings['currency_symbol'] ?? 'KES',
                'currency_position' => $settings['currency_position'] ?? 'before'
            ];
            
            echo json_encode([
                'success' => true,
                'sales' => $sales,
                'summary' => $summary
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error loading sales data: ' . $e->getMessage()]);
        }
        exit();
    }
    
    // If we get here, unknown action
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . ($_POST['action'] ?? 'none')]);
    exit();
    
    } catch (Exception $e) {
        // Catch any PHP errors and return them as JSON
        error_log("Error in reception.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Server error: ' . $e->getMessage(),
            'error_type' => 'php_exception'
        ]);
        exit();
    } catch (Error $e) {
        // Catch fatal errors
        error_log("Fatal error in reception.php: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal error: ' . $e->getMessage(),
            'error_type' => 'php_fatal'
        ]);
        exit();
    }
}

// Function to generate unique customer number
function generateCustomerNumber($conn) {
    do {
        $customer_number = 'CUST-' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $check = $conn->prepare("SELECT id FROM customers WHERE customer_number = ?");
        $check->execute([$customer_number]);
    } while ($check->rowCount() > 0);
    
    return $customer_number;
}

// Reception can be accessed independently - no main login required

// Handle reception sign out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reception_auth_action']) && $_POST['reception_auth_action'] === 'sign_out') {
    // Clear reception authentication
    unset($_SESSION['reception_authenticated']);
    unset($_SESSION['reception_auth_user_id']);
    unset($_SESSION['reception_auth_username']);
    unset($_SESSION['reception_auth_time']);
    
    $_SESSION['info_message'] = "You have been signed out from Reception. Please authenticate again to access the system.";
    
    // Log sign out
    error_log("Reception sign out: User ID {$_SESSION['user_id']} ({$_SESSION['username']})");
    
    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle reception authentication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reception_auth_action']) && $_POST['reception_auth_action'] === 'authenticate') {
    $auth_user_id = $_POST['reception_auth_user_id'] ?? '';
    $auth_password = $_POST['reception_auth_password'] ?? '';

    if ($auth_user_id && $auth_password) {
        try {
            // Validate input
            $auth_user_id = trim($auth_user_id);
            $auth_password = trim($auth_password);
            
            if (empty($auth_user_id) || empty($auth_password)) {
                throw new Exception("Please enter both User ID and password.");
            }

            // Verify user credentials - check User ID only
            $stmt = $conn->prepare("
                SELECT id, username, password, role, employment_id, user_id, employee_id
                FROM users
                WHERE user_id = ?
                AND status = 'active'
            ");
            $stmt->execute([$auth_user_id]);
            $auth_user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auth_user) {
                throw new Exception("User not found or account is inactive. Please check your User ID.");
            }

            if (!password_verify($auth_password, $auth_user['password'])) {
                throw new Exception("Invalid password. Please check your password and try again.");
            }

            // Check if user has reception permissions (admin or specific reception permissions)
            $has_permission = false;
            if (strtolower($auth_user['role']) === 'admin') {
                $has_permission = true;
            } else {
                // Check for reception permissions
                $perm_stmt = $conn->prepare("
                    SELECT COUNT(*) as count
                    FROM role_permissions rp
                    JOIN permissions p ON rp.permission_id = p.id
                    JOIN users u ON u.role_id = rp.role_id
                    WHERE u.id = ? AND p.name IN ('manage_reception', 'process_returns', 'manage_customer_service')
                ");
                $perm_stmt->execute([$auth_user['id']]);
                $perm_result = $perm_stmt->fetch(PDO::FETCH_ASSOC);
                $has_permission = $perm_result['count'] > 0;
            }

            if ($has_permission) {
                $_SESSION['reception_authenticated'] = true;
                $_SESSION['reception_auth_user_id'] = $auth_user['id'];
                $_SESSION['reception_auth_username'] = $auth_user['username'];
                $_SESSION['reception_auth_time'] = time();
                
                $_SESSION['success_message'] = "Reception authentication successful. Welcome to the Reception Dashboard!";
                
                // Log successful authentication
                error_log("Reception authentication successful: User ID {$auth_user['id']} ({$auth_user['username']})");
            } else {
                throw new Exception("You don't have permission to access the Reception system. Please contact your administrator.");
            }
        } catch (Exception $e) {
            // Log failed authentication attempts
            error_log("Reception authentication failed: " . $e->getMessage() . " | Attempted ID: " . $auth_user_id);
            $_SESSION['error_message'] = $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Please enter both User ID/Username and password.";
    }

    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Initialize user variables - can be from main system or reception-only
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? ($_SESSION['reception_auth_username'] ?? 'Reception User');
$role_name = $_SESSION['role_name'] ?? 'Reception';
$role_id = $_SESSION['role_id'] ?? 0;

// Check if user is admin (from main system)
$is_admin = isset($_SESSION['role_name']) && (strtolower($_SESSION['role_name']) === 'admin' || strtolower($_SESSION['role_name']) === 'administrator');

// Check reception authentication status
$reception_authenticated = $_SESSION['reception_authenticated'] ?? false;

// Check if reception authentication has expired (2 hours)
if ($reception_authenticated && isset($_SESSION['reception_auth_time'])) {
    $auth_time = $_SESSION['reception_auth_time'];
    $current_time = time();
    $time_diff = $current_time - $auth_time;
    
    // 2 hours = 7200 seconds
    if ($time_diff > 7200) {
        // Clear expired authentication
        unset($_SESSION['reception_authenticated']);
        unset($_SESSION['reception_auth_user_id']);
        unset($_SESSION['reception_auth_username']);
        unset($_SESSION['reception_auth_time']);
        unset($_SESSION['reception_last_activity']);
        $reception_authenticated = false;
        $_SESSION['info_message'] = "Reception authentication has expired. Please authenticate again.";
    }
}

// Check reception activity timeout (separate from main system)
if ($reception_authenticated && isset($_SESSION['reception_last_activity'])) {
    $inactive_time = time() - $_SESSION['reception_last_activity'];
    $reception_timeout = 7200; // 2 hours
    
    if ($inactive_time > $reception_timeout) {
        // Reception session has expired due to inactivity
        unset($_SESSION['reception_authenticated']);
        unset($_SESSION['reception_auth_user_id']);
        unset($_SESSION['reception_auth_username']);
        unset($_SESSION['reception_auth_time']);
        unset($_SESSION['reception_last_activity']);
        $reception_authenticated = false;
        $_SESSION['info_message'] = "Reception session has expired due to inactivity. Please authenticate again.";
    }
}

// Update activity time
if ($reception_authenticated) {
    $_SESSION['reception_last_activity'] = time();
} elseif (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Get user permissions (if from main system)
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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get basic statistics for reception
$stats = [
    'total_customers' => 0,
    'new_today' => 0,
    'total_sales_today' => 0,
    'sales_amount_today' => 0,
    'sales_count_today' => 0
];

// Get total customers count
$total_customers_stmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE customer_type != 'walk_in'");
$total_customers_stmt->execute();
$total_customers_result = $total_customers_stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_customers'] = $total_customers_result['count'] ?? 0;

// Get customers added today
$new_today_stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM customers 
    WHERE DATE(created_at) = CURDATE() AND customer_type != 'walk_in'
");
$new_today_stmt->execute();
$new_today_result = $new_today_stmt->fetch(PDO::FETCH_ASSOC);
$stats['new_today'] = $new_today_result['count'] ?? 0;

// Get sales statistics for today
$sales_today_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as sales_count,
        COALESCE(SUM(final_amount), 0) as total_amount
    FROM sales 
    WHERE DATE(created_at) = CURDATE()
");
$sales_today_stmt->execute();
$sales_today_result = $sales_today_stmt->fetch(PDO::FETCH_ASSOC);
$stats['sales_count_today'] = $sales_today_result['sales_count'] ?? 0;
$stats['sales_amount_today'] = $sales_today_result['total_amount'] ?? 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reception - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --primary-dark: #4f46e5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --dark-color: #1e293b;
            --light-bg: #f8fafc;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--light-bg) 0%, #e2e8f0 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Full Page Layout */
        .reception-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 0;
            margin: 0;
        }

        /* Header */
        .reception-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .reception-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.3;
        }

        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }

        .header-title .subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin-left: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .current-time {
            font-size: 1.1rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.15);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        /* Main Content */
        .reception-main {
            flex: 1;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            height: calc(100vh - 120px);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.5fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Medium screens - stack in 2x2 grid */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: 1fr 1.2fr;
                max-width: 600px;
            }
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: white;
        }

        .stat-primary { background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); }
        .stat-success { background: linear-gradient(135deg, var(--success-color), #059669); }
        .stat-warning { background: linear-gradient(135deg, var(--warning-color), #d97706); }
        .stat-info { background: linear-gradient(135deg, var(--info-color), #1d4ed8); }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark-color);
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
            min-height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        
        /* Responsive font sizes for stat values */
        @media (max-width: 1200px) {
            .stat-value {
                font-size: 1.75rem;
            }
        }
        
        @media (max-width: 992px) {
            .stat-value {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.25rem;
            }
        }

        .stat-label {
            color: #64748b;
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Action Cards */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .action-card {
            background: white;
            border-radius: 20px;
            padding: 3rem 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            min-height: 250px;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(99, 102, 241, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }

        .action-card:hover::before {
            opacity: 1;
        }

        .action-icon {
            width: 100px;
            height: 100px;
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: white;
            margin-bottom: 2rem;
        }

        .action-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }

        .action-description {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.6;
        }


        /* Responsive Design */
        @media (max-width: 768px) {
            .reception-header {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .reception-main {
                padding: 1rem;
                height: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                max-width: 400px;
                gap: 1rem;
            }
            
            /* Stack cards vertically on very small screens */
            @media (max-width: 480px) {
                .stats-grid {
                    grid-template-columns: 1fr;
                    max-width: 300px;
                }
            }
            
            .stat-card {
                padding: 1rem;
                min-height: 120px;
            }

            .action-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                gap: 1.5rem;
            }


            .action-card {
                min-height: 200px;
                padding: 2rem 1.5rem;
            }

            .action-icon {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }

            .action-title {
                font-size: 1.3rem;
            }
        }

        /* Animations */
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

        .stat-card, .action-card {
            animation: fadeInUp 0.6s ease forwards;
        }

        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .action-card:nth-child(1) { animation-delay: 0.2s; }
        .action-card:nth-child(2) { animation-delay: 0.3s; }
        .action-card:nth-child(3) { animation-delay: 0.4s; }
        .action-card:nth-child(4) { animation-delay: 0.5s; }

        /* Loyalty Points Modal Styles */
        .customer-avatar-large {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--warning-color), #d97706);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .list-group-item-action {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .list-group-item-action:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }

        .customer-search-item {
            display: flex;
            align-items: center;
            padding: 1rem;
        }

        .customer-search-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            margin-right: 1rem;
        }

        .customer-search-info {
            flex: 1;
        }

        .customer-search-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .customer-search-details {
            font-size: 0.85rem;
            color: #64748b;
        }

        .points-badge {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

    </style>
</head>
<body>
    <?php if (!$is_admin && !$reception_authenticated): ?>
    <!-- Reception Authentication Modal -->
    <div class="modal fade" id="receptionAuthModal" tabindex="-1" aria-labelledby="receptionAuthModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="receptionAuthModalLabel">
                        <i class="bi bi-shield-lock me-2"></i>Reception Authentication Required
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-workspace text-primary" style="font-size: 3rem;"></i>
                        <h6 class="mt-3">Reception System Access</h6>
                        <p class="text-muted">Enter your User ID and Password to continue</p>
                    </div>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['info_message'])): ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['info_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['info_message']); ?>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="reception_auth_action" value="authenticate">
                        
                        <div class="mb-3">
                            <label for="reception_auth_user_id" class="form-label">
                                <i class="bi bi-person me-1"></i>User ID
                            </label>
                            <input type="text" class="form-control" id="reception_auth_user_id" name="reception_auth_user_id" 
                                   placeholder="Enter your User ID" required autofocus
                                   tabindex="1" style="z-index: 9999; position: relative;">
                        </div>
                        
                        <div class="mb-4">
                            <label for="reception_auth_password" class="form-label">
                                <i class="bi bi-lock me-1"></i>Password
                            </label>
                            <input type="password" class="form-control" id="reception_auth_password" name="reception_auth_password" 
                                   placeholder="Enter your password" required
                                   tabindex="2" style="z-index: 9999; position: relative;">
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="receptionAuthSubmitBtn">
                                <i class="bi bi-unlock me-2"></i>Authenticate for Reception
                            </button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Only users with reception permissions can access this system
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Authentication Overlay -->
    <div class="auth-overlay">
        <div class="auth-content">
            <div class="text-center">
                <i class="bi bi-shield-lock text-primary mb-3" style="font-size: 4rem;"></i>
                <h2>Reception Authentication Required</h2>
                <p class="text-muted mb-4">Please authenticate to access the reception dashboard</p>
                <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#receptionAuthModal">
                    <i class="bi bi-unlock me-2"></i>Authenticate Now
                </button>
            </div>
        </div>
    </div>

    <style>
        .auth-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(79, 70, 229, 0.1) 100%);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1040; /* Lower than modal z-index */
        }
        
        .auth-content {
            background: white;
            padding: 3rem;
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
            text-align: center;
        }
        
        .reception-container {
            filter: blur(5px);
            pointer-events: none;
        }
        
        /* Ensure modal appears above overlay */
        .modal {
            z-index: 1050 !important;
        }
        
        .modal-backdrop {
            z-index: 1049 !important;
        }
    </style>
    <?php endif; ?>

    <div class="reception-container"<?php echo (!$is_admin && !$reception_authenticated) ? ' style="filter: blur(5px); pointer-events: none;"' : ''; ?>>
        <!-- Header -->
        <header class="reception-header">
            <div class="header-content">
                <div class="header-title">
                    <i class="bi bi-door-open" style="font-size: 2rem;"></i>
                    <div>
                        <h1>Reception Desk</h1>
                        <span class="subtitle">Customer Service & Queue Management</span>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="current-time" id="currentTime">
                        <i class="bi bi-clock me-2"></i>
                        <span id="timeDisplay"></span>
                    </div>
                    <div class="user-info">
                        <i class="bi bi-person-circle me-2"></i>
                        <span><?php echo htmlspecialchars($username); ?></span>
                        <span class="ms-2 badge bg-light text-dark"><?php echo htmlspecialchars($role_name); ?></span>
                        <?php if ($reception_authenticated && !$is_admin): ?>
                            <span class="ms-2 badge bg-success">
                                <i class="bi bi-shield-check me-1"></i>Reception Authenticated
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($reception_authenticated && !$is_admin): ?>
                    <div class="reception-actions">
                        <button type="button" class="btn btn-outline-light btn-sm" onclick="signOutReception()" title="Sign out from Reception">
                            <i class="bi bi-box-arrow-right me-1"></i>Sign Out Reception
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="reception-main">
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-primary">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-success">
                            <i class="bi bi-person-plus"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['new_today']); ?></div>
                    <div class="stat-label">New Today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-warning">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="stat-value">
                        <?php 
                        $currency_symbol = $settings['currency_symbol'] ?? 'KES';
                        $currency_position = $settings['currency_position'] ?? 'before';
                        $raw_amount = $stats['sales_amount_today'];
                        
                        // Format large numbers with K for thousands
                        if ($raw_amount >= 10000) {
                            $formatted_amount = number_format($raw_amount / 1000, 1) . 'K';
                        } else {
                            $formatted_amount = number_format($raw_amount, 2);
                        }
                        
                        if ($currency_position == 'after') {
                            echo $formatted_amount . ' ' . $currency_symbol;
                        } else {
                            echo $currency_symbol . ' ' . $formatted_amount;
                        }
                        ?>
                    </div>
                    <div class="stat-label">Sales Today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon stat-info">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['sales_count_today']); ?></div>
                    <div class="stat-label">Transactions Today</div>
                </div>
            </div>

            <!-- Action Cards -->
            <div class="action-grid">
                <div class="action-card" onclick="showAddCustomer()">
                    <div class="action-icon stat-primary">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <div class="action-title">Add New Customer</div>
                    <div class="action-description">Register a new customer to the system</div>
                </div>

                <div class="action-card" onclick="showLoyaltyPoints()">
                    <div class="action-icon stat-warning">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <div class="action-title">View Loyalty Points</div>
                    <div class="action-description">Check customer loyalty points and rewards</div>
                </div>

                <div class="action-card" onclick="showTransactionCart()">
                    <div class="action-icon stat-info">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div class="action-title">Transaction Search</div>
                    <div class="action-description">Search transactions and reprint receipts</div>
                </div>

                <div class="action-card" onclick="showReturnsRefunds()">
                    <div class="action-icon" style="background: linear-gradient(135deg, #dc2626, #b91c1c);">
                        <i class="bi bi-arrow-return-left"></i>
                    </div>
                    <div class="action-title">Returns & Refunds</div>
                    <div class="action-description">Process product returns and issue refunds</div>
                </div>

                <div class="action-card" onclick="showSalesToday()">
                    <div class="action-icon" style="background: linear-gradient(135deg, #059669, #047857);">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="action-title">View Sales Today</div>
                    <div class="action-description">View today's sales transactions with privacy controls</div>
                </div>

            </div>
        </main>
    </div>

    <!-- Add Customer Modal -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); color: white;">
                    <h5 class="modal-title" id="addCustomerModalLabel">
                        <i class="bi bi-person-plus me-2"></i>Add New Customer
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addCustomerForm" method="POST">
                        <div class="row">
                            <!-- Personal Information -->
                            <div class="col-md-6">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-person me-2"></i>Personal Information
                                </h6>
                                
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required maxlength="50" placeholder="Enter first name">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required maxlength="50" placeholder="Enter last name">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" maxlength="20" placeholder="e.g., +1 (555) 123-4567">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" maxlength="100" placeholder="customer@example.com">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" placeholder="Select date of birth">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Business & Address Information -->
                            <div class="col-md-6">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-geo-alt me-2"></i>Contact & Business Information
                                </h6>
                                
                                <div class="mb-3">
                                    <label for="customer_type" class="form-label">Customer Type</label>
                                    <select class="form-select" id="customer_type" name="customer_type">
                                        <option value="individual">Individual</option>
                                        <option value="business">Business</option>
                                        <option value="vip">VIP</option>
                                        <option value="wholesale">Wholesale</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3" id="company_name_group" style="display: none;">
                                    <label for="company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" maxlength="255" placeholder="Enter company name">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="2" maxlength="500" placeholder="Enter street address"></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="city" class="form-label">City</label>
                                            <input type="text" class="form-control" id="city" name="city" maxlength="100" placeholder="Enter city">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="state" class="form-label">State</label>
                                            <input type="text" class="form-control" id="state" name="state" maxlength="100" placeholder="Enter state/province">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="zip_code" class="form-label">ZIP Code</label>
                                            <input type="text" class="form-control" id="zip_code" name="zip_code" maxlength="20" placeholder="Enter ZIP/postal code">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="country" class="form-label">Country</label>
                                            <input type="text" class="form-control" id="country" name="country" value="USA" maxlength="100" placeholder="Enter country">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="membership_level" class="form-label">Membership Level</label>
                                    <select class="form-select" id="membership_level" name="membership_level">
                                        <option value="Bronze">Bronze</option>
                                        <option value="Silver">Silver</option>
                                        <option value="Gold">Gold</option>
                                        <option value="Platinum">Platinum</option>
                                        <option value="Diamond">Diamond</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-chat-text me-2"></i>Additional Information
                                </h6>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="500" placeholder="Any additional notes about the customer..."></textarea>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" onclick="submitCustomerForm()">
                        <i class="bi bi-check-circle me-1"></i>Add Customer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loyalty Points Modal -->
    <div class="modal fade" id="loyaltyPointsModal" tabindex="-1" aria-labelledby="loyaltyPointsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                    <h5 class="modal-title" id="loyaltyPointsModalLabel">
                        <i class="bi bi-star-fill me-2"></i>Customer Loyalty Points
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Search Section -->
                    <div class="mb-4">
                        <h6 class="text-warning mb-3">
                            <i class="bi bi-search me-2"></i>Search Customer
                        </h6>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control" id="customerSearch" 
                                           placeholder="Search by name, phone, email, customer number, or company..." 
                                           autocomplete="off">
                                </div>
                                <small class="text-muted mt-1">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Examples: John Smith, 555-123-4567, john@email.com, CUST-123456
                                </small>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-warning w-100" onclick="searchCustomer()">
                                    <i class="bi bi-search me-1"></i>Search
                                </button>
                            </div>
                        </div>
                        
                        <!-- Search Results -->
                        <div id="searchResults" class="mt-3" style="display: none;">
                            <h6 class="text-muted mb-2">Search Results:</h6>
                            <div id="customerList" class="list-group">
                                <!-- Search results will be populated here -->
                            </div>
                        </div>
                    </div>

                    <!-- Customer Details Section -->
                    <div id="customerDetails" style="display: none;">
                        <hr>
                        <h6 class="text-warning mb-3">
                            <i class="bi bi-person-circle me-2"></i>Customer Information
                        </h6>
                        
                        <div class="row">
                            <!-- Customer Info -->
                            <div class="col-md-6">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="customer-avatar-large me-3" id="customerAvatar">
                                                <!-- Avatar will be populated -->
                                            </div>
                                            <div>
                                                <h5 class="mb-1" id="customerName">Customer Name</h5>
                                                <p class="text-muted mb-0" id="customerNumber">CUST-000000</p>
                                            </div>
                                        </div>
                                        
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="border-end">
                                                    <h6 class="text-primary mb-1" id="customerType">Individual</h6>
                                                    <small class="text-muted">Customer Type</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <h6 class="text-success mb-1" id="membershipLevel">Bronze</h6>
                                                <small class="text-muted">Membership</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Loyalty Points -->
                            <div class="col-md-6">
                                <div class="card border-0" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                                    <div class="card-body text-center">
                                        <i class="bi bi-star-fill" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                        <h2 class="mb-2" id="loyaltyPoints">0</h2>
                                        <p class="mb-0">Loyalty Points</p>
                                        <hr class="my-3" style="border-color: rgba(255,255,255,0.3);">
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="border-end" style="border-color: rgba(255,255,255,0.3) !important;">
                                                    <h6 class="mb-1" id="totalEarned">0</h6>
                                                    <small>Total Earned</small>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <h6 class="mb-1" id="totalRedeemed">0</h6>
                                                <small>Redeemed</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-telephone me-2"></i>Contact Information
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <strong>Phone:</strong>
                                                <p class="mb-0" id="customerPhone">Not provided</p>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Email:</strong>
                                                <p class="mb-0" id="customerEmail">Not provided</p>
                                            </div>
                                            <div class="col-md-4">
                                                <strong>Member Since:</strong>
                                                <p class="mb-0" id="memberSince">-</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="noResults" style="display: none;" class="text-center py-4">
                        <i class="bi bi-search" style="font-size: 3rem; color: #ccc;"></i>
                        <h5 class="text-muted mt-3">No customers found</h5>
                        <p class="text-muted">Try searching with a different term</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-warning" id="addPointsBtn" style="display: none;" onclick="showAddPointsForm()">
                        <i class="bi bi-plus-circle me-1"></i>Add Points
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Performance Optimization Utilities -->
    <script src="performance_optimization.js"></script>
    
    <script>
        
        // Update time display with performance optimization
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            // Use requestAnimationFrame to prevent forced reflow
            requestAnimationFrame(() => {
                const timeElement = document.getElementById('timeDisplay');
                if (timeElement && timeElement.textContent !== timeString) {
                    timeElement.textContent = timeString;
                }
            });
        }

        // Update time every second with performance optimization
        setInterval(updateTime, 1000);
        updateTime(); // Initial call

        // Action card functions
        function showAddCustomer() {
            // Show the add customer modal
            const modal = new bootstrap.Modal(document.getElementById('addCustomerModal'));
            modal.show();
        }

        function showLoyaltyPoints() {
            // Show the loyalty points modal
            const modal = new bootstrap.Modal(document.getElementById('loyaltyPointsModal'));
            modal.show();
            // Focus on search input when modal opens
            setTimeout(() => {
                document.getElementById('customerSearch').focus();
            }, 300);
        }

        function showTransactionCart() {
            // Redirect to transaction cart page for transaction search
            window.location.href = 'transaction_cart.php';
        }

        function showReturnsRefunds() {
            // Show the returns and refunds modal
            const modal = new bootstrap.Modal(document.getElementById('returnsRefundsModal'));
            modal.show();
        }

        function showSalesToday() {
            const modal = new bootstrap.Modal(document.getElementById('salesTodayModal'));
            modal.show();
            loadSalesToday();
        }

        function loadSalesToday() {
            const loadingDiv = document.getElementById('salesTodayLoading');
            const contentDiv = document.getElementById('salesTodayContent');
            const errorDiv = document.getElementById('salesTodayError');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            errorDiv.style.display = 'none';
            
            const formData = new FormData();
            formData.append('action', 'get_sales_today');
            
            fetch('reception.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loadingDiv.style.display = 'none';
                
                if (data.success) {
                    displaySalesToday(data.sales, data.summary);
                    contentDiv.style.display = 'block';
                } else {
                    errorDiv.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Failed to load sales data') + '</div>';
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                loadingDiv.style.display = 'none';
                errorDiv.innerHTML = '<div class="alert alert-danger">Error loading sales data</div>';
                errorDiv.style.display = 'block';
            });
        }

        function displaySalesToday(sales, summary) {
            const summaryDiv = document.getElementById('salesTodaySummary');
            const listDiv = document.getElementById('salesTodayList');
            
            // Function to format large numbers
            function formatAmount(amount) {
                if (amount >= 10000) {
                    return (amount / 1000).toFixed(1) + 'K';
                } else {
                    return parseFloat(amount).toFixed(2);
                }
            }
            
            // Function to format currency with position
            function formatCurrency(amount, symbol, position) {
                const formattedAmount = formatAmount(amount);
                return position === 'after' ? formattedAmount + ' ' + symbol : symbol + ' ' + formattedAmount;
            }
            
            // Display summary
            summaryDiv.innerHTML = `
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h5 class="card-title">${summary.total_transactions}</h5>
                                <p class="card-text">Total Transactions</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h5 class="card-title">${formatCurrency(summary.total_amount, summary.currency_symbol, summary.currency_position)}</h5>
                                <p class="card-text">Total Sales</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h5 class="card-title">${formatCurrency(summary.average_sale, summary.currency_symbol, summary.currency_position)}</h5>
                                <p class="card-text">Average Sale</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Display sales list with privacy controls
            if (sales.length === 0) {
                listDiv.innerHTML = '<div class="text-center py-4"><i class="bi bi-receipt" style="font-size: 3rem; color: #ccc;"></i><h5 class="text-muted mt-3">No sales today</h5></div>';
                return;
            }
            
            let salesHtml = '<div class="list-group">';
            sales.forEach(sale => {
                const saleDate = new Date(sale.created_at);
                const timeString = saleDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                salesHtml += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">Transaction #${sale.id}</h6>
                                <p class="mb-1">
                                    <small class="text-muted">
                                        <i class="bi bi-clock me-1"></i>${timeString}
                                        <i class="bi bi-person ms-3 me-1"></i>${sale.customer_name || 'Walk-in Customer'}
                                        <i class="bi bi-credit-card ms-3 me-1"></i>${sale.payment_method || 'Cash'}
                                    </small>
                                </p>
                                <small class="text-muted">Cashier: ${sale.cashier_name || 'Unknown'}</small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-success fs-6">${formatCurrency(sale.final_amount, summary.currency_symbol, summary.currency_position)}</span>
                                <br>
                                <small class="text-muted">${sale.item_count} items</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            salesHtml += '</div>';
            
            listDiv.innerHTML = salesHtml;
        }


        // Ensure functions are available globally for onclick handlers
        window.showAddCustomer = showAddCustomer;
        window.showLoyaltyPoints = showLoyaltyPoints;
        window.showTransactionCart = showTransactionCart;
        window.showReturnsRefunds = showReturnsRefunds;
        window.showSalesToday = showSalesToday;
        window.submitCustomerForm = submitCustomerForm;
        

        // This will be handled in DOMContentLoaded below

        // Submit customer form
        function submitCustomerForm() {
            const form = document.getElementById('addCustomerForm');
            const formData = new FormData(form);
            
            // Add action to form data
            formData.append('action', 'add_customer');
            
            // Show loading state
            const submitBtn = document.querySelector('[onclick="submitCustomerForm()"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Adding...';
            submitBtn.disabled = true;
            
            // Submit form via AJAX
            fetch('reception.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    showAlert('Customer added successfully!', 'success');
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addCustomerModal'));
                    modal.hide();
                    
                    // Reset form
                    form.reset();
                    
                    // Update stats (refresh page or update via AJAX)
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(data.message || 'Error adding customer', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Network error occurred', 'danger');
            })
            .finally(() => {
                // Restore button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        // Show alert function
        function showAlert(message, type) {
            // Create alert element
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Add to page
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // This will be handled in DOMContentLoaded below

        // Customer search functionality
        function searchCustomer() {
            const searchTerm = document.getElementById('customerSearch').value.trim();
            
            if (searchTerm.length < 2) {
                showAlert('Please enter at least 2 characters to search', 'warning');
                return;
            }
            
            // Show search examples for better user guidance
            if (searchTerm.length === 2) {
                console.log('Search examples: John Doe, 555-123-4567, john@email.com, CUST-123456, ABC Company');
            }
            
            // Show loading state
            const searchBtn = document.querySelector('[onclick="searchCustomer()"]');
            const originalText = searchBtn.innerHTML;
            searchBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Searching...';
            searchBtn.disabled = true;
            
            // Hide previous results
            document.getElementById('searchResults').style.display = 'none';
            document.getElementById('customerDetails').style.display = 'none';
            document.getElementById('noResults').style.display = 'none';
            
            // Search via AJAX
            const formData = new FormData();
            formData.append('action', 'search_customer');
            formData.append('search_term', searchTerm);
            
            fetch('reception.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.customers.length > 0) {
                    displaySearchResults(data.customers);
                } else {
                    document.getElementById('noResults').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Search failed. Please try again.', 'danger');
            })
            .finally(() => {
                // Restore button state
                searchBtn.innerHTML = originalText;
                searchBtn.disabled = false;
            });
        }
        
        // Display search results
        function displaySearchResults(customers) {
            const customerList = document.getElementById('customerList');
            customerList.innerHTML = '';
            
            customers.forEach(customer => {
                const initials = (customer.first_name.charAt(0) + customer.last_name.charAt(0)).toUpperCase();
                const fullName = customer.first_name + ' ' + customer.last_name;
                
                const listItem = document.createElement('div');
                listItem.className = 'list-group-item list-group-item-action';
                listItem.onclick = () => selectCustomer(customer.id);
                
                listItem.innerHTML = `
                    <div class="customer-search-item">
                        <div class="customer-search-avatar">${initials}</div>
                        <div class="customer-search-info">
                            <div class="customer-search-name">${fullName}</div>
                            <div class="customer-search-details">
                                ${customer.customer_number} • 
                                ${customer.phone || customer.mobile || 'No phone'} • 
                                ${customer.email || 'No email'} •
                                ${customer.customer_type.charAt(0).toUpperCase() + customer.customer_type.slice(1)}
                            </div>
                        </div>
                        <div class="points-badge">
                            ${customer.loyalty_points || 0} pts
                        </div>
                    </div>
                `;
                
                customerList.appendChild(listItem);
            });
            
            document.getElementById('searchResults').style.display = 'block';
        }
        
        // Select customer and show loyalty details
        function selectCustomer(customerId) {
            // Show loading
            document.getElementById('customerDetails').style.display = 'none';
            
            const formData = new FormData();
            formData.append('action', 'get_customer_loyalty');
            formData.append('customer_id', customerId);
            
            fetch('reception.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayCustomerDetails(data.customer);
                } else {
                    showAlert(data.message || 'Failed to load customer details', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Failed to load customer details', 'danger');
            });
        }
        
        // Display customer loyalty details
        function displayCustomerDetails(customer) {
            const initials = (customer.first_name.charAt(0) + customer.last_name.charAt(0)).toUpperCase();
            const fullName = customer.first_name + ' ' + customer.last_name;
            const memberSince = new Date(customer.created_at).toLocaleDateString();
            
            // Update customer info
            document.getElementById('customerAvatar').textContent = initials;
            document.getElementById('customerName').textContent = fullName;
            document.getElementById('customerNumber').textContent = customer.customer_number;
            document.getElementById('customerType').textContent = customer.customer_type.charAt(0).toUpperCase() + customer.customer_type.slice(1);
            document.getElementById('membershipLevel').textContent = customer.membership_level;
            
            // Update loyalty points
            document.getElementById('loyaltyPoints').textContent = customer.current_points || 0;
            document.getElementById('totalEarned').textContent = customer.total_earned || 0;
            document.getElementById('totalRedeemed').textContent = customer.total_redeemed || 0;
            
            // Update contact info
            const phoneNumber = customer.phone || customer.mobile || 'Not provided';
            document.getElementById('customerPhone').textContent = phoneNumber;
            document.getElementById('customerEmail').textContent = customer.email || 'Not provided';
            document.getElementById('memberSince').textContent = memberSince;
            
            // Show customer details section
            document.getElementById('customerDetails').style.display = 'block';
            document.getElementById('addPointsBtn').style.display = 'inline-block';
            
            // Hide search results
            document.getElementById('searchResults').style.display = 'none';
        }
        
        // Enable search on Enter key
        // This will be handled in DOMContentLoaded below
        
        // Placeholder for add points functionality
        function showAddPointsForm() {
            showAlert('Add Points feature will be implemented in the next update', 'info');
        }

        
        // Setup all event listeners when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Add click animations to action cards
            document.querySelectorAll('.action-card').forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'translateY(-8px) scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(-8px) scale(1)';
                    }, 150);
                });
            });

            // Handle customer type change to show/hide company name field
            const customerType = document.getElementById('customer_type');
            if (customerType) {
                customerType.addEventListener('change', function() {
                    const companyGroup = document.getElementById('company_name_group');
                    if (this.value === 'business') {
                        companyGroup.style.display = 'block';
                        document.getElementById('company_name').required = true;
                    } else {
                        companyGroup.style.display = 'none';
                        document.getElementById('company_name').required = false;
                    }
                });
            }

            // Enable Enter key for customer search
            const customerSearch = document.getElementById('customerSearch');
            if (customerSearch) {
                customerSearch.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        searchCustomer();
                    }
                });
            }

        });

        // Auto-refresh stats every 5 minutes with performance optimization
        setInterval(() => {
            // Use requestIdleCallback for non-critical updates
            if (window.requestIdleCallback) {
                requestIdleCallback(() => {
                    location.reload();
                });
            } else {
                // Fallback for browsers without requestIdleCallback
                setTimeout(() => {
                    location.reload();
                }, 100);
            }
         }, 300000); // Refresh every 5 minutes

        // Global function to handle reception authentication errors
        function handleReceptionAuthError(response) {
            if (response.status === 401) {
                try {
                    const data = response.json ? response.json() : JSON.parse(response.responseText || '{}');
                    if (data.error === 'Reception authentication required') {
                        alert(data.message || 'Please authenticate for reception access.');
                        // Reload the page to show authentication modal
                        window.location.reload();
                        return true;
                    }
                } catch (e) {
                    // If response is not JSON, still handle 401
                    alert('Reception authentication required.');
                    window.location.reload();
                    return true;
                }
            } else if (response.status === 403) {
                try {
                    const data = response.json ? response.json() : JSON.parse(response.responseText || '{}');
                    alert(data.message || 'You do not have permission to perform this action.');
                    return true;
                } catch (e) {
                    alert('Access denied. You do not have permission to perform this action.');
                    return true;
                }
            }
            return false;
        }

        // Enhanced fetch function with reception authentication handling
        function makeReceptionRequest(url, data, options = {}) {
            const formData = new FormData();
            for (const key in data) {
                formData.append(key, data[key]);
            }

            return fetch(url, {
                method: 'POST',
                body: formData,
                ...options
            })
            .then(response => {
                // Check for authentication errors
                if (response.status === 401) {
                    return response.json().then(data => {
                        if (data.error === 'Reception authentication required') {
                            alert(data.message || 'Please authenticate for reception access.');
                            window.location.reload();
                        }
                        throw new Error(data.error || 'Authentication failed');
                    });
                } else if (response.status === 403) {
                    return response.json().then(data => {
                        alert(data.message || 'Access denied.');
                        throw new Error(data.error || 'Access denied');
                    });
                }
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                return response.json();
            })
            .then(data => {
                // Check for authentication errors in the response data
                if (data.success === false && data.error === 'Reception authentication required') {
                    alert(data.message || 'Please authenticate for reception access.');
                    window.location.reload();
                    throw new Error('Authentication required');
                }
                
                return data;
            });
        }

        // Override existing fetch calls for reception requests
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            return originalFetch(url, options).then(response => {
                if (url.includes('reception.php') && response.status === 401) {
                    handleReceptionAuthError(response);
                }
                return response;
            });
        };

        <?php if (!$is_admin && !$reception_authenticated): ?>
        // Reception Authentication Handling
        document.addEventListener('DOMContentLoaded', function() {
            // Hide the overlay when modal is shown
            const authOverlay = document.querySelector('.auth-overlay');
            
            // Show authentication modal immediately when page loads
            const authModal = new bootstrap.Modal(document.getElementById('receptionAuthModal'), {
                backdrop: 'static',
                keyboard: false
            });
            
            // Handle modal events
            document.getElementById('receptionAuthModal').addEventListener('shown.bs.modal', function() {
                // Hide overlay when modal is shown
                if (authOverlay) {
                    authOverlay.style.display = 'none';
                }
                
                // Ensure input field gets focus when modal is shown
                const userIdInput = document.getElementById('reception_auth_user_id');
                if (userIdInput) {
                    setTimeout(() => {
                        userIdInput.focus();
                        userIdInput.click();
                    }, 100);
                }
            });
            
            // Show overlay when modal is hidden (if still not authenticated)
            document.getElementById('receptionAuthModal').addEventListener('hidden.bs.modal', function() {
                if (authOverlay && !<?php echo $reception_authenticated ? 'true' : 'false'; ?>) {
                    authOverlay.style.display = 'flex';
                }
            });
            
            authModal.show();
            
            // Handle authentication form submission
            const authForm = document.querySelector('form[action=""]');
            if (authForm) {
                authForm.addEventListener('submit', function(e) {
                    const submitBtn = document.getElementById('receptionAuthSubmitBtn');
                    const userIdInput = document.getElementById('reception_auth_user_id');
                    const passwordInput = document.getElementById('reception_auth_password');
                    
                    // Validate inputs
                    if (!userIdInput.value.trim()) {
                        e.preventDefault();
                        alert('Please enter your User ID.');
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
            
            // Handle "Authenticate Now" button from overlay
            const authenticateBtn = document.querySelector('button[data-bs-target="#receptionAuthModal"]');
            if (authenticateBtn) {
                authenticateBtn.addEventListener('click', function() {
                    if (authOverlay) {
                        authOverlay.style.display = 'none';
                    }
                });
            }
        });
        <?php endif; ?>

        <?php if ($reception_authenticated): ?>
        // Reception sign out function
        function signOutReception() {
            if (confirm('Are you sure you want to sign out from Reception? You will need to authenticate again to access the system.')) {
                // Create a form to submit the sign out request
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'reception_auth_action';
                actionInput.value = 'sign_out';
                
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Show success message if just authenticated
        <?php if (isset($_SESSION['success_message'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Create success alert
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                <?php echo addslashes($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        });
        <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php endif; ?>
    </script>

    <!-- Sales Today Modal -->
    <div class="modal fade" id="salesTodayModal" tabindex="-1" aria-labelledby="salesTodayModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #059669, #047857); color: white;">
                    <h5 class="modal-title" id="salesTodayModalLabel">
                        <i class="bi bi-graph-up-arrow me-2"></i>Sales Today
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Loading State -->
                    <div id="salesTodayLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading today's sales data...</p>
                    </div>

                    <!-- Error State -->
                    <div id="salesTodayError" style="display: none;"></div>

                    <!-- Content -->
                    <div id="salesTodayContent" style="display: none;">
                        <!-- Summary Cards -->
                        <div id="salesTodaySummary"></div>

                        <!-- Privacy Notice -->
                        <div class="alert alert-info d-flex align-items-center mb-4">
                            <i class="bi bi-shield-check me-2"></i>
                            <div>
                                <strong>Privacy Protection:</strong> 
                                Customer phone numbers and email addresses are partially masked for privacy. 
                                Only managers and administrators can view complete customer information.
                            </div>
                        </div>

                        <!-- Sales List -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-list-ul me-2"></i>Today's Transactions
                                </h6>
                            </div>
                            <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                                <div id="salesTodayList"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-primary" onclick="loadSalesToday()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Include Transaction Cart Modal -->
    <?php include __DIR__ . '/transaction_cart.php'; ?>

    <!-- Include Returns and Refunds Modal -->
    <?php include __DIR__ . '/returns_refunds.php'; ?>
</body>
</html>
