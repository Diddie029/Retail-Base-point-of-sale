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

// Check if user has permission to view sales
if (!hasPermission('view_sales', $permissions) && !hasPermission('manage_sales', $permissions)) {
    header('Location: ../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Handle form submissions
$success = '';
$error = '';

// Check for success message from redirect
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

// Handle AJAX requests for pagination and search
if (isset($_GET['ajax']) && $_GET['ajax'] == 'transactions') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(CONCAT(c.first_name, ' ', c.last_name) LIKE :search OR c.phone LIKE :search OR lp.description LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "lp.approval_status = :status";
        $params[':status'] = $status_filter;
    }
    
    if (!empty($type_filter)) {
        $where_conditions[] = "lp.transaction_type = :type";
        $params[':type'] = $type_filter;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "DATE(lp.created_at) >= :date_from";
        $params[':date_from'] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "DATE(lp.created_at) <= :date_to";
        $params[':date_to'] = $date_to;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $offset = ($page - 1) * $per_page;
    
    $sql = "
        SELECT 
            lp.*,
            CONCAT(c.first_name, ' ', c.last_name) as customer_name,
            c.phone,
            u.username as approved_by_username
        FROM loyalty_points lp
        JOIN customers c ON lp.customer_id = c.id
        LEFT JOIN users u ON lp.approved_by = u.id
        $where_clause
        ORDER BY lp.created_at DESC
        LIMIT $offset, $per_page
    ";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['transactions' => $transactions, 'page' => $page]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_points':
                try {
                    $customer_id = $_POST['customer_id'];
                    $points = $_POST['points'];
                    $description = $_POST['description'];
                    $source = $_POST['source'] ?? 'manual';
                    
                    // Get customer's current balance
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(points_earned - points_redeemed), 0) as current_balance 
                        FROM loyalty_points 
                        WHERE customer_id = ? AND approval_status = 'approved'
                    ");
                    $stmt->execute([$customer_id]);
                    $current_balance = $stmt->fetch(PDO::FETCH_ASSOC)['current_balance'];
                    
                    // Check maximum points limit if enabled
                    $enable_max = $settings['enable_points_maximum'] ?? '0';
                    $max_limit = $settings['maximum_points_limit'] ?? '10000';
                    
                    if ($enable_max == '1') {
                        $new_balance = $current_balance + $points;
                        if ($new_balance > $max_limit) {
                            throw new Exception("Adding {$points} points would exceed the maximum limit of {$max_limit} points. Current balance: {$current_balance}");
                        }
                    }
                    
                    // Determine approval status based on source
                    $approval_status = ($source === 'manual') ? 'pending' : 'approved';
                    $approved_by = ($source === 'manual') ? null : $user_id;
                    $approved_at = ($source === 'manual') ? null : date('Y-m-d H:i:s');
                    
                    // Add points
                    $stmt = $conn->prepare("
                        INSERT INTO loyalty_points (customer_id, points_earned, points_balance, transaction_type, description, source, approval_status, approved_by, approved_at)
                        VALUES (?, ?, ?, 'earned', ?, ?, ?, ?, ?)
                    ");
                    $new_balance = $current_balance + $points;
                    $stmt->execute([$customer_id, $points, $new_balance, $description, $source, $approval_status, $approved_by, $approved_at]);
                    
                    $success = "Added {$points} points to customer! " . ($approval_status === 'pending' ? 'Awaiting approval.' : '');
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error adding points: ' . $e->getMessage();
                }
                break;
                
            case 'redeem_points':
                try {
                    $customer_id = $_POST['customer_id'];
                    $points = $_POST['points'];
                    $description = $_POST['description'];
                    
                    // Get customer's current balance
                    $stmt = $conn->prepare("
                        SELECT COALESCE(SUM(points_earned - points_redeemed), 0) as current_balance 
                        FROM loyalty_points 
                        WHERE customer_id = ?
                    ");
                    $stmt->execute([$customer_id]);
                    $current_balance = $stmt->fetch(PDO::FETCH_ASSOC)['current_balance'];
                    
                    if ($current_balance < $points) {
                        throw new Exception('Insufficient points balance!');
                    }
                    
                    // Redeem points
                    $stmt = $conn->prepare("
                        INSERT INTO loyalty_points (customer_id, points_redeemed, points_balance, transaction_type, description)
                        VALUES (?, ?, ?, 'redeemed', ?)
                    ");
                    $new_balance = $current_balance - $points;
                    $stmt->execute([$customer_id, $points, $new_balance, $description]);
                    
                    $success = "Redeemed {$points} points for customer!";
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error redeeming points: ' . $e->getMessage();
                }
                break;
                
                
            case 'update_settings':
                try {
                    foreach ($_POST['settings'] as $key => $value) {
                        // Check if setting exists in main settings table
                        $check = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
                        $check->execute([$key]);
                        
                        if ($check->fetchColumn() > 0) {
                            // Update existing setting
                            $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                            $stmt->execute([$value, $key]);
                        } else {
                            // Insert new setting
                            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                            $stmt->execute([$key, $value]);
                        }
                    }
                    $success = 'Loyalty settings updated successfully!';
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error updating settings: ' . $e->getMessage();
                }
                break;
                
            case 'add_custom_points':
                try {
                    $customer_id = $_POST['customer_id'];
                    $points = $_POST['points'];
                    $description = $_POST['description'];
                    $points_type = $_POST['points_type']; // 'welcome', 'bonus', 'adjustment'
                    
                    // Validate customer exists
                    $customer = getCustomerById($conn, $customer_id);
                    if (!$customer) {
                        throw new Exception('Customer not found');
                    }
                    
                    // Generate transaction reference
                    $transactionRef = strtoupper($points_type) . '_' . date('YmdHis') . '_' . $customer_id;
                    
                    // Add custom points
                    $success = addLoyaltyPoints(
                        $conn, 
                        $customer_id, 
                        $points, 
                        $description ?: ucfirst($points_type) . " points by " . ($_SESSION['username'] ?? 'Admin'),
                        $transactionRef
                    );
                    
                    if ($success) {
                        $success_message = "Added {$points} {$points_type} points to customer!";
                        // Redirect to prevent form resubmission
                        header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success_message));
                        exit();
                    } else {
                        throw new Exception('Failed to add points');
                    }
                } catch (Exception $e) {
                    $error = 'Error adding custom points: ' . $e->getMessage();
                }
                break;
                
            case 'update_welcome_points':
                try {
                    $welcome_points = (int)$_POST['welcome_points'];
                    
                    if ($welcome_points < 0) {
                        throw new Exception('Welcome points cannot be negative');
                    }
                    
                    // Update welcome points setting
                    $stmt = $conn->prepare("
                        INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('welcome_points', ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?
                    ");
                    $stmt->execute([$welcome_points, $welcome_points]);
                    
                    $success = "Welcome points updated to {$welcome_points}!";
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error updating welcome points: ' . $e->getMessage();
                }
                break;
                
            case 'toggle_loyalty_program':
                try {
                    $enable = isset($_POST['enable_loyalty']) ? 1 : 0;
                    
                    // Update loyalty program setting
                    $stmt = $conn->prepare("
                        INSERT INTO settings (setting_key, setting_value) 
                        VALUES ('loyalty_program_enabled', ?) 
                        ON DUPLICATE KEY UPDATE setting_value = ?
                    ");
                    $stmt->execute([$enable, $enable]);
                    
                    $status = $enable ? 'enabled' : 'disabled';
                    $success = "Loyalty program {$status} successfully!";
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error toggling loyalty program: ' . $e->getMessage();
                }
                break;
                
            case 'approve_points':
                try {
                    $transaction_id = $_POST['transaction_id'];
                    
                    // Update transaction status
                    $stmt = $conn->prepare("
                        UPDATE loyalty_points 
                        SET approval_status = 'approved', approved_by = ?, approved_at = NOW()
                        WHERE id = ? AND approval_status = 'pending'
                    ");
                    $stmt->execute([$user_id, $transaction_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $success = "Points transaction approved successfully!";
                    } else {
                        $error = "Transaction not found or already processed.";
                    }
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error approving points: ' . $e->getMessage();
                }
                break;
                
            case 'reject_points':
                try {
                    $transaction_id = $_POST['transaction_id'];
                    $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
                    
                    // Update transaction status
                    $stmt = $conn->prepare("
                        UPDATE loyalty_points 
                        SET approval_status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ?
                        WHERE id = ? AND approval_status = 'pending'
                    ");
                    $stmt->execute([$user_id, $rejection_reason, $transaction_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $success = "Points transaction rejected successfully!";
                    } else {
                        $error = "Transaction not found or already processed.";
                    }
                    // Redirect to prevent form resubmission
                    header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($success));
                    exit();
                } catch (Exception $e) {
                    $error = 'Error rejecting points: ' . $e->getMessage();
                }
                break;
                
        }
    }
}

// Get system settings
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Define loyalty settings with their configurations
$loyalty_settings = [
    'loyalty_program_enabled' => [
        'label' => 'Enable Loyalty Program',
        'type' => 'boolean',
        'value' => $settings['loyalty_program_enabled'] ?? '1',
        'description' => 'Turn the loyalty points system on or off'
    ],
    'welcome_points' => [
        'label' => 'Welcome Points',
        'type' => 'number',
        'value' => $settings['welcome_points'] ?? '100',
        'description' => 'Points awarded to new customers upon registration'
    ],
    'enable_spending_points' => [
        'label' => 'Enable Spending-Based Points',
        'type' => 'boolean',
        'value' => $settings['enable_spending_points'] ?? '1',
        'description' => 'Enable points earning based on customer spending'
    ],
    'points_calculation_method' => [
        'label' => 'Points Calculation Method',
        'type' => 'select',
        'value' => $settings['points_calculation_method'] ?? 'threshold',
        'description' => 'Choose how points are calculated',
        'options' => [
            'threshold' => 'Threshold-based (spend X to get Y)',
            'percentage' => 'Percentage-based (% of spending)',
            'fixed_rate' => 'Fixed rate (X points per Y spent)'
        ]
    ],
    'spending_threshold' => [
        'label' => 'Spending Threshold (KES)',
        'type' => 'number',
        'value' => $settings['spending_threshold'] ?? '100',
        'description' => 'Amount customer must spend to earn threshold points'
    ],
    'points_per_threshold' => [
        'label' => 'Points per Threshold',
        'type' => 'number',
        'value' => $settings['points_per_threshold'] ?? '10',
        'description' => 'Points earned for each spending threshold reached'
    ],
    'points_percentage' => [
        'label' => 'Points Percentage (%)',
        'type' => 'number',
        'value' => $settings['points_percentage'] ?? '5',
        'description' => 'Percentage of spending amount to award as points'
    ],
    'fixed_points_amount' => [
        'label' => 'Fixed Points Amount',
        'type' => 'number',
        'value' => $settings['fixed_points_amount'] ?? '10',
        'description' => 'Fixed points to award per spending cycle'
    ],
    'fixed_spending_amount' => [
        'label' => 'Fixed Spending Amount (KES)',
        'type' => 'number',
        'value' => $settings['fixed_spending_amount'] ?? '100',
        'description' => 'Spending amount required for fixed points'
    ],
    'include_tax_in_calculation' => [
        'label' => 'Include Tax in Points Calculation',
        'type' => 'boolean',
        'value' => $settings['include_tax_in_calculation'] ?? '1',
        'description' => 'Calculate points based on tax-inclusive or tax-exclusive amounts'
    ],
    'points_per_currency' => [
        'label' => 'Points per Currency Unit',
        'type' => 'number',
        'value' => $settings['points_per_currency'] ?? '1',
        'description' => 'Additional points per currency unit spent (on top of main calculation)'
    ],
    'minimum_redemption_points' => [
        'label' => 'Minimum Points for Redemption',
        'type' => 'number',
        'value' => $settings['minimum_redemption_points'] ?? '100',
        'description' => 'Minimum points required before customers can redeem'
    ],
    'points_expiry_days' => [
        'label' => 'Points Expiry (Days)',
        'type' => 'number',
        'value' => $settings['points_expiry_days'] ?? '365',
        'description' => 'Number of days before points expire (0 = never expire)'
    ],
    'enable_welcome_bonus' => [
        'label' => 'Enable Welcome Bonus',
        'type' => 'boolean',
        'value' => $settings['enable_welcome_bonus'] ?? '1',
        'description' => 'Enable welcome bonus points for new customers'
    ],
    'welcome_bonus_points' => [
        'label' => 'Welcome Bonus Points',
        'type' => 'number',
        'value' => $settings['welcome_bonus_points'] ?? '100',
        'description' => 'Points given to new customers on first purchase'
    ],
    'enable_birthday_bonus' => [
        'label' => 'Enable Birthday Bonus',
        'type' => 'boolean',
        'value' => $settings['enable_birthday_bonus'] ?? '0',
        'description' => 'Enable birthday bonus points for customers'
    ],
    'birthday_bonus_points' => [
        'label' => 'Birthday Bonus Points',
        'type' => 'number',
        'value' => $settings['birthday_bonus_points'] ?? '50',
        'description' => 'Points given to customers on their birthday'
    ],
    'enable_referral_bonus' => [
        'label' => 'Enable Referral Bonus',
        'type' => 'boolean',
        'value' => $settings['enable_referral_bonus'] ?? '0',
        'description' => 'Enable referral bonus points for customer referrals'
    ],
    'referral_bonus_points' => [
        'label' => 'Referral Bonus Points',
        'type' => 'number',
        'value' => $settings['referral_bonus_points'] ?? '200',
        'description' => 'Points given for successful customer referrals'
    ],
    'tier_based_rewards' => [
        'label' => 'Enable Tier-Based Rewards',
        'type' => 'boolean',
        'value' => $settings['tier_based_rewards'] ?? '0',
        'description' => 'Enable different reward tiers based on customer spending'
    ],
    'auto_apply_points' => [
        'label' => 'Auto-Apply Points on Purchase',
        'type' => 'boolean',
        'value' => $settings['auto_apply_points'] ?? '1',
        'description' => 'Automatically award points when customers make purchases'
    ],
    'points_redemption_limit' => [
        'label' => 'Maximum Points Redemption (%)',
        'type' => 'number',
        'value' => $settings['points_redemption_limit'] ?? '50',
        'description' => 'Maximum percentage of purchase that can be paid with points'
    ],
    'enable_points_maximum' => [
        'label' => 'Enable Points Maximum Limit',
        'type' => 'boolean',
        'value' => $settings['enable_points_maximum'] ?? '0',
        'description' => 'Set a maximum limit on how many points a customer can accumulate'
    ],
    'maximum_points_limit' => [
        'label' => 'Maximum Points Limit',
        'type' => 'number',
        'value' => $settings['maximum_points_limit'] ?? '10000',
        'description' => 'Maximum number of points a customer can have (only applies if limit is enabled)'
    ]
];

// Function to calculate points based on spending
function calculateSpendingPoints($amount, $settings, $taxAmount = 0) {
    $enableSpending = $settings['enable_spending_points'] ?? '1';
    if ($enableSpending != '1') {
        return 0;
    }
    
    // Determine calculation amount based on tax inclusion setting
    $includeTax = $settings['include_tax_in_calculation'] ?? '1';
    $calculationAmount = $includeTax == '1' ? $amount : ($amount - $taxAmount);
    
    // Ensure calculation amount is not negative
    $calculationAmount = max(0, $calculationAmount);
    
    $points = 0;
    $method = $settings['points_calculation_method'] ?? 'threshold';
    
    switch ($method) {
        case 'threshold':
            $threshold = floatval($settings['spending_threshold'] ?? '100');
            $pointsPerThreshold = intval($settings['points_per_threshold'] ?? '10');
            
            // Calculate threshold-based points
            $thresholdsReached = floor($calculationAmount / $threshold);
            $points += $thresholdsReached * $pointsPerThreshold;
            break;
            
        case 'percentage':
            $percentage = floatval($settings['points_percentage'] ?? '5');
            $points += ($calculationAmount * $percentage) / 100;
            break;
            
        case 'fixed_rate':
            $fixedSpending = floatval($settings['fixed_spending_amount'] ?? '100');
            $fixedPoints = intval($settings['fixed_points_amount'] ?? '10');
            
            // Calculate fixed rate points
            $cycles = floor($calculationAmount / $fixedSpending);
            $points += $cycles * $fixedPoints;
            break;
    }
    
    // Add additional points per currency unit if enabled
    $pointsPerCurrency = floatval($settings['points_per_currency'] ?? '1');
    if ($pointsPerCurrency > 0) {
        $points += $calculationAmount * $pointsPerCurrency;
    }
    
    return floor($points);
}

// Get loyalty program statistics
$stmt = $conn->query("
    SELECT 
        COUNT(DISTINCT customer_id) as total_customers,
        SUM(points_earned) as total_points_earned,
        SUM(points_redeemed) as total_points_redeemed,
        SUM(points_earned - points_redeemed) as total_points_balance
    FROM loyalty_points
");
$loyalty_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get top customers by points
$stmt = $conn->query("
    SELECT 
        CONCAT(c.first_name, ' ', c.last_name) as name,
        c.phone,
        c.email,
        COALESCE(SUM(lp.points_earned - lp.points_redeemed), 0) as points_balance,
        COUNT(s.id) as total_orders,
        SUM(s.final_amount) as total_spent
    FROM customers c
    LEFT JOIN loyalty_points lp ON c.id = lp.customer_id
    LEFT JOIN sales s ON c.id = s.customer_id
    GROUP BY c.id
    HAVING points_balance > 0
    ORDER BY points_balance DESC
    LIMIT 10
");
$top_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent point transactions
$stmt = $conn->query("
    SELECT 
        lp.*,
        CONCAT(c.first_name, ' ', c.last_name) as customer_name,
        c.phone,
        u.username as approved_by_username
    FROM loyalty_points lp
    JOIN customers c ON lp.customer_id = c.id
    LEFT JOIN users u ON lp.approved_by = u.id
    ORDER BY lp.created_at DESC
    LIMIT 20
");
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Welcome points reward (simplified system)
$welcomePoints = $settings['welcome_points'] ?? '100';

// Get customers for dropdown
$stmt = $conn->query("
    SELECT id, CONCAT(first_name, ' ', last_name) as name, phone, email 
    FROM customers 
    WHERE customer_type != 'walk_in'
    ORDER BY first_name, last_name
");
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Points System - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Main Content Layout */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 30px;
        }
        
        /* Enhanced Card Styling */
        .points-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .points-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255,255,255,0.1) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .points-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.4);
        }
        
        .points-value {
            font-size: 2.5rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 0.5rem;
        }
        
        /* Quick Actions Styling */
        .quick-actions-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .action-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 15px;
            padding: 1rem 1.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .action-btn:hover::before {
            left: 100%;
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        .action-btn.btn-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3);
        }
        
        .action-btn.btn-warning:hover {
            box-shadow: 0 8px 25px rgba(240, 147, 251, 0.4);
        }
        
        .action-btn.btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }
        
        .action-btn.btn-danger:hover {
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.4);
        }
        
        .action-btn.btn-success {
            background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
            box-shadow: 0 4px 15px rgba(78, 205, 196, 0.3);
        }
        
        .action-btn.btn-success:hover {
            box-shadow: 0 8px 25px rgba(78, 205, 196, 0.4);
        }
        
        .action-btn.btn-info {
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            box-shadow: 0 4px 15px rgba(116, 185, 255, 0.3);
        }
        
        .action-btn.btn-info:hover {
            box-shadow: 0 8px 25px rgba(116, 185, 255, 0.4);
        }
        
        /* Card Enhancements */
        .card {
            border-radius: 20px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        /* Welcome Points Card */
        .welcome-points-card {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 30px rgba(255, 234, 167, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .welcome-points-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(255, 234, 167, 0.4);
        }
        
        /* Transaction Cards */
        .customer-card {
            border-left: 4px solid #20c997;
            background: linear-gradient(135deg, #d1f2eb 0%, #a7f3d0 100%);
        }
        
        .transaction-earned {
            border-left: 4px solid #28a745;
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        }
        
        .transaction-redeemed {
            border-left: 4px solid #dc3545;
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        }
        
        /* Modal Enhancements */
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 1.5rem;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        /* Form Enhancements */
        .form-control {
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-select {
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        /* Button Enhancements */
        .btn {
            border-radius: 12px;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .points-card {
                padding: 1.5rem;
            }
            
            .points-value {
                font-size: 2rem;
            }
            
            .action-btn {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
        }
        
        /* Animation Keyframes */
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
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Loading States */
        .btn.loading {
            position: relative;
            color: transparent;
        }
        
        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../include/navmenu.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="bi bi-star-fill text-warning"></i> Loyalty Points System</h2>
                        <p class="text-muted">Simple loyalty program with welcome points and purchase-based earning</p>
                    </div>
                    <a href="salesdashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Statistics Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card points-card text-center">
                            <div class="card-body">
                                <div class="points-value text-primary"><?php echo number_format($loyalty_stats['total_customers'] ?? 0); ?></div>
                                <div class="text-muted">Active Members</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card points-card text-center">
                            <div class="card-body">
                                <div class="points-value text-success"><?php echo number_format($loyalty_stats['total_points_earned'] ?? 0); ?></div>
                                <div class="text-muted">Points Earned</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card points-card text-center">
                            <div class="card-body">
                                <div class="points-value text-warning"><?php echo number_format($loyalty_stats['total_points_redeemed'] ?? 0); ?></div>
                                <div class="text-muted">Points Redeemed</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card points-card text-center">
                            <div class="card-body">
                                <div class="points-value text-info"><?php echo number_format($loyalty_stats['total_points_balance'] ?? 0); ?></div>
                                <div class="text-muted">Outstanding Points</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Points Calculation Demo -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-calculator"></i> Points Calculation Demo</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>How Points are Calculated:</h6>
                                        <div class="alert alert-info">
                                            <strong>Example:</strong> Customer spends KES 250 (with 16% tax = KES 40)<br>
                                            <?php 
                                            $demoAmount = 250;
                                            $demoTaxAmount = 40;
                                            $demoPoints = calculateSpendingPoints($demoAmount, $settings, $demoTaxAmount);
                                            $method = $settings['points_calculation_method'] ?? 'threshold';
                                            $includeTax = $settings['include_tax_in_calculation'] ?? '1';
                                            $calculationAmount = $includeTax == '1' ? $demoAmount : ($demoAmount - $demoTaxAmount);
                                            $pointsPerCurrency = floatval($settings['points_per_currency'] ?? '1');
                                            
                                            $currencyPoints = $calculationAmount * $pointsPerCurrency;
                                            ?>
                                            <ul class="mb-0 mt-2">
                                                <li>Calculation amount: KES <?php echo number_format($calculationAmount); ?> 
                                                    (<?php echo $includeTax == '1' ? 'tax-inclusive' : 'tax-exclusive'; ?>)
                                                </li>
                                                <?php if ($method == 'threshold'): ?>
                                                <?php 
                                                $threshold = floatval($settings['spending_threshold'] ?? '100');
                                                $pointsPerThreshold = intval($settings['points_per_threshold'] ?? '10');
                                                $thresholdsReached = floor($calculationAmount / $threshold);
                                                $thresholdPoints = $thresholdsReached * $pointsPerThreshold;
                                                ?>
                                                <li>Thresholds reached: <?php echo $thresholdsReached; ?> (KES <?php echo number_format($threshold); ?> each)</li>
                                                <li>Threshold points: <?php echo $thresholdPoints; ?> points</li>
                                                <?php elseif ($method == 'percentage'): ?>
                                                <?php 
                                                $percentage = floatval($settings['points_percentage'] ?? '5');
                                                $percentagePoints = ($calculationAmount * $percentage) / 100;
                                                ?>
                                                <li>Percentage points: <?php echo number_format($percentagePoints, 1); ?> points (<?php echo $percentage; ?>% of KES <?php echo number_format($calculationAmount); ?>)</li>
                                                <?php elseif ($method == 'fixed_rate'): ?>
                                                <?php 
                                                $fixedSpending = floatval($settings['fixed_spending_amount'] ?? '100');
                                                $fixedPoints = intval($settings['fixed_points_amount'] ?? '10');
                                                $cycles = floor($calculationAmount / $fixedSpending);
                                                $fixedRatePoints = $cycles * $fixedPoints;
                                                ?>
                                                <li>Fixed rate cycles: <?php echo $cycles; ?> (KES <?php echo number_format($fixedSpending); ?> each)</li>
                                                <li>Fixed rate points: <?php echo $fixedRatePoints; ?> points</li>
                                                <?php endif; ?>
                                                <li>Currency points: <?php echo number_format($currencyPoints, 1); ?> points (KES <?php echo number_format($calculationAmount); ?> × <?php echo $pointsPerCurrency; ?>)</li>
                                                <li><strong>Total: <?php echo $demoPoints; ?> points</strong></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Quick Calculator:</h6>
                                        <div class="input-group mb-3">
                                            <span class="input-group-text">KES</span>
                                            <input type="number" class="form-control" id="demoAmount" placeholder="Enter amount" value="250">
                                            <button class="btn btn-outline-primary" type="button" onclick="calculateDemoPoints()">Calculate</button>
                                        </div>
                                        <div id="demoResult" class="alert alert-success" style="display: none;">
                                            <strong>Points earned: <span id="demoPoints">0</span></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bonus Settings Overview -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-gift"></i> Current Bonus Settings</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="me-3">
                                                <i class="bi bi-person-plus-fill text-success fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Welcome Bonus</h6>
                                                <p class="text-muted mb-0">
                                                    <?php if (($settings['enable_welcome_bonus'] ?? '1') == '1'): ?>
                                                        <span class="text-success">✓ Enabled</span> - 
                                                        <?php echo number_format($settings['welcome_bonus_points'] ?? '100'); ?> points
                                                    <?php else: ?>
                                                        <span class="text-muted">✗ Disabled</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="me-3">
                                                <i class="bi bi-cake2-fill text-warning fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Birthday Bonus</h6>
                                                <p class="text-muted mb-0">
                                                    <?php if (($settings['enable_birthday_bonus'] ?? '0') == '1'): ?>
                                                        <span class="text-success">✓ Enabled</span> - 
                                                        <?php echo number_format($settings['birthday_bonus_points'] ?? '50'); ?> points
                                                    <?php else: ?>
                                                        <span class="text-muted">✗ Disabled</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="me-3">
                                                <i class="bi bi-people-fill text-info fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Referral Bonus</h6>
                                                <p class="text-muted mb-0">
                                                    <?php if (($settings['enable_referral_bonus'] ?? '0') == '1'): ?>
                                                        <span class="text-success">✓ Enabled</span> - 
                                                        <?php echo number_format($settings['referral_bonus_points'] ?? '200'); ?> points
                                                    <?php else: ?>
                                                        <span class="text-muted">✗ Disabled</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="me-3">
                                                <i class="bi bi-currency-exchange text-primary fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Spending-Based Points</h6>
                                                <p class="text-muted mb-0">
                                                    <?php if (($settings['enable_spending_points'] ?? '1') == '1'): ?>
                                                        <span class="text-success">✓ Enabled</span><br>
                                                        Spend KES <?php echo number_format($settings['spending_threshold'] ?? '100'); ?> → 
                                                        <?php echo number_format($settings['points_per_threshold'] ?? '10'); ?> points<br>
                                                        + <?php echo number_format($settings['points_per_currency'] ?? '1'); ?> point per KES
                                                    <?php else: ?>
                                                        <span class="text-muted">✗ Disabled</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="me-3">
                                                <i class="bi bi-shield-check text-danger fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-1">Maximum Points Limit</h6>
                                                <p class="text-muted mb-0">
                                                    <?php if (($settings['enable_points_maximum'] ?? '0') == '1'): ?>
                                                        <span class="text-warning">✓ Limited</span> - 
                                                        <?php echo number_format($settings['maximum_points_limit'] ?? '10000'); ?> points max
                                                    <?php else: ?>
                                                        <span class="text-success">✗ No limit</span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="quick-actions-card fade-in-up">
                            <div class="d-flex align-items-center mb-4">
                                <div class="me-3">
                                    <div class="bg-primary rounded-circle p-3 d-inline-flex align-items-center justify-content-center">
                                        <i class="bi bi-lightning-charge text-white fs-4"></i>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="mb-1 fw-bold">Quick Actions</h4>
                                    <p class="text-muted mb-0">Manage loyalty points and program settings</p>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addPointsModal">
                                        <i class="bi bi-plus-circle me-2"></i> Add Points
                                    </button>
                                </div>
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#redeemPointsModal">
                                        <i class="bi bi-dash-circle me-2"></i> Redeem Points
                                    </button>
                                </div>
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#settingsModal">
                                        <i class="bi bi-gear me-2"></i> Settings
                                    </button>
                                </div>
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#customPointsModal">
                                        <i class="bi bi-plus-circle me-2"></i> Custom Points
                                    </button>
                                </div>
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#welcomePointsModal">
                                        <i class="bi bi-star me-2"></i> Welcome Points
                                    </button>
                                </div>
                                <div class="col-md-4 col-lg-2">
                                    <button class="btn action-btn btn-<?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'danger' : 'success'; ?> w-100" data-bs-toggle="modal" data-bs-target="#toggleLoyaltyModal">
                                        <i class="bi bi-power me-2"></i> <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'Disable' : 'Enable'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="row">
                    <!-- Top Customers -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top Customers by Points</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_customers)): ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-people text-muted fs-1 mb-3"></i>
                                    <p class="text-muted">No customers with points yet</p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($top_customers as $customer): ?>
                                <div class="customer-card card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($customer['name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($customer['phone']); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div class="points-value text-success"><?php echo number_format($customer['points_balance']); ?></div>
                                                <small class="text-muted">points</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Welcome Points System -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-star-fill"></i> Welcome Points System</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center py-4">
                                    <i class="bi bi-gift text-primary fs-1 mb-3"></i>
                                    <h5 class="text-primary">Welcome Points Reward</h5>
                                    <p class="text-muted mb-3">New customers automatically receive welcome points upon registration</p>
                                    
                                    <div class="alert alert-info">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <div class="flex-grow-1">
                                                <strong>Welcome Points:</strong> <?php echo number_format($welcomePoints); ?> points
                                                <br>
                                                <small class="text-muted">Awarded automatically to new customers</small>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#welcomePointsModal">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="bi bi-lightbulb me-1"></i>
                                            This simple system focuses on earning points through purchases and redeeming them for discounts.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Point Transactions</h5>
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#transactionFilters" aria-expanded="false">
                                        <i class="bi bi-funnel"></i> Filters
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Transaction Filters -->
                                <div class="collapse mb-3" id="transactionFilters">
                                    <div class="card card-body">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Search</label>
                                                <input type="text" class="form-control" id="transactionSearch" placeholder="Customer, phone, description...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" id="statusFilter">
                                                    <option value="">All Status</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="approved">Approved</option>
                                                    <option value="rejected">Rejected</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Type</label>
                                                <select class="form-select" id="typeFilter">
                                                    <option value="">All Types</option>
                                                    <option value="earned">Earned</option>
                                                    <option value="redeemed">Redeemed</option>
                                                    <option value="adjusted">Adjusted</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">From Date</label>
                                                <input type="date" class="form-control" id="dateFrom">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">To Date</label>
                                                <input type="date" class="form-control" id="dateTo">
                                            </div>
                                            <div class="col-md-1">
                                                <label class="form-label">&nbsp;</label>
                                                <div class="d-flex gap-1">
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="loadTransactions()">
                                                        <i class="bi bi-search"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                                    <table class="table table-sm">
                                        <thead class="sticky-top bg-light">
                                            <tr>
                                                <th>Customer</th>
                                                <th>Type</th>
                                                <th>Points</th>
                                                <th>Description</th>
                                                <th>Status</th>
                                                <th>Source</th>
                                                <th>Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="transactionsTableBody">
                                            <?php foreach ($recent_transactions as $transaction): ?>
                                            <tr class="<?php echo $transaction['transaction_type'] == 'earned' ? 'transaction-earned' : 'transaction-redeemed'; ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($transaction['customer_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($transaction['phone']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $transaction['transaction_type'] == 'earned' ? 'success' : 'danger'; ?>">
                                                        <?php echo ucfirst($transaction['transaction_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong class="text-<?php echo $transaction['transaction_type'] == 'earned' ? 'success' : 'danger'; ?>">
                                                        <?php echo $transaction['transaction_type'] == 'earned' ? '+' : '-'; ?><?php echo number_format($transaction['points_earned'] + $transaction['points_redeemed']); ?>
                                                    </strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                                                <td>
                                                    <?php
                                                    $status_class = '';
                                                    $status_text = '';
                                                    switch($transaction['approval_status']) {
                                                        case 'pending':
                                                            $status_class = 'warning';
                                                            $status_text = 'Pending';
                                                            break;
                                                        case 'approved':
                                                            $status_class = 'success';
                                                            $status_text = 'Approved';
                                                            break;
                                                        case 'rejected':
                                                            $status_class = 'danger';
                                                            $status_text = 'Rejected';
                                                            break;
                                                    }
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                    <?php if ($transaction['approved_by_username']): ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($transaction['approved_by_username']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo ucfirst($transaction['source'] ?? 'manual'); ?></span>
                                                </td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($transaction['approval_status'] == 'pending'): ?>
                                                    <div class="btn-group btn-group-sm">
                                                        <button class="btn btn-success btn-sm" onclick="approveTransaction(<?php echo $transaction['id']; ?>)" title="Approve">
                                                            <i class="bi bi-check"></i>
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="rejectTransaction(<?php echo $transaction['id']; ?>)" title="Reject">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div id="loadingIndicator" class="text-center py-3" style="display: none;">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                                
                                <div id="loadMoreContainer" class="text-center mt-3">
                                    <button class="btn btn-outline-primary" onclick="loadMoreTransactions()" id="loadMoreBtn">
                                        <i class="bi bi-arrow-down"></i> Load More
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- Add Points Modal -->
    <div class="modal fade" id="addPointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_points">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Points to Customer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?> - <?php echo htmlspecialchars($customer['phone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points to Add</label>
                            <input type="number" class="form-control" name="points" min="1" required>
                            <div class="form-text">Maximum: <span id="maxPointsDisplay">No limit</span></div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" placeholder="e.g., Welcome bonus, Purchase reward">
                        </div>
                        <div class="mb-3">
                            <label for="source" class="form-label">Source</label>
                            <select class="form-select" name="source" required>
                                <option value="manual">Manual Entry</option>
                                <option value="welcome">Welcome Bonus</option>
                                <option value="bonus">Special Bonus</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                            <div class="form-text">Manual entries require approval, others are auto-approved</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" onclick="return validateAddPoints()">Add Points</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Redeem Points Modal -->
    <div class="modal fade" id="redeemPointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="redeem_points">
                    <div class="modal-header">
                        <h5 class="modal-title">Redeem Points from Customer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?> - <?php echo htmlspecialchars($customer['phone']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points to Redeem</label>
                            <input type="number" class="form-control" name="points" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" name="description" placeholder="e.g., Discount applied, Reward redeemed">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" onclick="return validateRedeemPoints()">Redeem Points</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-gear"></i> Loyalty Program Settings</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <?php foreach ($loyalty_settings as $key => $setting): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="setting_<?php echo $key; ?>">
                                    <strong><?php echo htmlspecialchars($setting['label']); ?></strong>
                                </label>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($setting['description']); ?></p>
                                
                                <?php if ($setting['type'] == 'boolean'): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="settings[<?php echo $key; ?>]" 
                                           id="setting_<?php echo $key; ?>"
                                           value="1" 
                                           <?php echo $setting['value'] == '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="setting_<?php echo $key; ?>">
                                        Enable
                                    </label>
                                </div>
                                <?php elseif ($setting['type'] == 'select'): ?>
                                <select class="form-select" name="settings[<?php echo $key; ?>]" id="setting_<?php echo $key; ?>">
                                    <?php foreach ($setting['options'] as $optionValue => $optionLabel): ?>
                                    <option value="<?php echo $optionValue; ?>" <?php echo $setting['value'] == $optionValue ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($optionLabel); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($setting['type'] == 'number'): ?>
                                <input type="number" class="form-control" 
                                       name="settings[<?php echo $key; ?>]" 
                                       id="setting_<?php echo $key; ?>"
                                       value="<?php echo htmlspecialchars($setting['value']); ?>"
                                       min="0" step="0.01">
                                <?php else: ?>
                                <input type="text" class="form-control" 
                                       name="settings[<?php echo $key; ?>]" 
                                       id="setting_<?php echo $key; ?>"
                                       value="<?php echo htmlspecialchars($setting['value']); ?>">
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Transaction management variables
        let currentPage = 1;
        let isLoading = false;
        let hasMoreData = true;
        
        // Load transactions with filters
        function loadTransactions(reset = true) {
            if (isLoading) return;
            
            if (reset) {
                currentPage = 1;
                hasMoreData = true;
                document.getElementById('transactionsTableBody').innerHTML = '';
            }
            
            isLoading = true;
            document.getElementById('loadingIndicator').style.display = 'block';
            
            const params = new URLSearchParams({
                ajax: 'transactions',
                page: currentPage,
                search: document.getElementById('transactionSearch').value,
                status: document.getElementById('statusFilter').value,
                type: document.getElementById('typeFilter').value,
                date_from: document.getElementById('dateFrom').value,
                date_to: document.getElementById('dateTo').value
            });
            
            fetch('?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    if (data.transactions.length === 0) {
                        hasMoreData = false;
                        if (currentPage === 1) {
                            document.getElementById('transactionsTableBody').innerHTML = 
                                '<tr><td colspan="8" class="text-center text-muted">No transactions found</td></tr>';
                        }
                    } else {
                        appendTransactions(data.transactions);
                        if (data.transactions.length < 20) {
                            hasMoreData = false;
                        }
                    }
                    currentPage++;
                    isLoading = false;
                    document.getElementById('loadingIndicator').style.display = 'none';
                    document.getElementById('loadMoreBtn').style.display = hasMoreData ? 'block' : 'none';
                })
                .catch(error => {
                    console.error('Error loading transactions:', error);
                    isLoading = false;
                    document.getElementById('loadingIndicator').style.display = 'none';
                });
        }
        
        // Append transactions to table
        function appendTransactions(transactions) {
            const tbody = document.getElementById('transactionsTableBody');
            
            transactions.forEach(transaction => {
                const row = document.createElement('tr');
                row.className = transaction.transaction_type === 'earned' ? 'transaction-earned' : 'transaction-redeemed';
                
                const statusClass = transaction.approval_status === 'pending' ? 'warning' : 
                                  transaction.approval_status === 'approved' ? 'success' : 'danger';
                const statusText = transaction.approval_status === 'pending' ? 'Pending' :
                                 transaction.approval_status === 'approved' ? 'Approved' : 'Rejected';
                
                const points = parseInt(transaction.points_earned) + parseInt(transaction.points_redeemed);
                const pointsText = transaction.transaction_type === 'earned' ? '+' + points : '-' + points;
                const pointsClass = transaction.transaction_type === 'earned' ? 'success' : 'danger';
                
                const actionButtons = transaction.approval_status === 'pending' ? 
                    `<div class="btn-group btn-group-sm">
                        <button class="btn btn-success btn-sm" onclick="approveTransaction(${transaction.id})" title="Approve">
                            <i class="bi bi-check"></i>
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="rejectTransaction(${transaction.id})" title="Reject">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>` : '<span class="text-muted">-</span>';
                
                row.innerHTML = `
                    <td>
                        <strong>${transaction.customer_name}</strong><br>
                        <small class="text-muted">${transaction.phone}</small>
                    </td>
                    <td>
                        <span class="badge bg-${transaction.transaction_type === 'earned' ? 'success' : 'danger'}">
                            ${transaction.transaction_type.charAt(0).toUpperCase() + transaction.transaction_type.slice(1)}
                        </span>
                    </td>
                    <td>
                        <strong class="text-${pointsClass}">${pointsText}</strong>
                    </td>
                    <td>${transaction.description || ''}</td>
                    <td>
                        <span class="badge bg-${statusClass}">${statusText}</span>
                        ${transaction.approved_by_username ? `<br><small class="text-muted">by ${transaction.approved_by_username}</small>` : ''}
                    </td>
                    <td>
                        <span class="badge bg-info">${(transaction.source || 'manual').charAt(0).toUpperCase() + (transaction.source || 'manual').slice(1)}</span>
                    </td>
                    <td>${new Date(transaction.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}</td>
                    <td>${actionButtons}</td>
                `;
                
                tbody.appendChild(row);
            });
        }
        
        // Load more transactions
        function loadMoreTransactions() {
            if (!isLoading && hasMoreData) {
                loadTransactions(false);
            }
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('transactionSearch').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('typeFilter').value = '';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            loadTransactions();
        }
        
        // Approve transaction
        function approveTransaction(transactionId) {
            if (confirm('Are you sure you want to approve this transaction?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="approve_points">
                    <input type="hidden" name="transaction_id" value="${transactionId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Reject transaction
        function rejectTransaction(transactionId) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason !== null) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reject_points">
                    <input type="hidden" name="transaction_id" value="${transactionId}">
                    <input type="hidden" name="rejection_reason" value="${reason}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listeners for filter changes
            document.getElementById('transactionSearch').addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    loadTransactions();
                }
            });
            
            // Auto-load more when scrolling near bottom
            const tableContainer = document.querySelector('.table-responsive');
            tableContainer.addEventListener('scroll', function() {
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 100) {
                    loadMoreTransactions();
                }
            });
        });
    </script>
    <script>
        // Show/hide fields based on enable checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const enableMaxCheckbox = document.getElementById('setting_enable_points_maximum');
            const maxLimitField = document.getElementById('setting_maximum_points_limit');
            const maxLimitContainer = maxLimitField.closest('.col-md-6');
            const maxPointsDisplay = document.getElementById('maxPointsDisplay');
            
            // Welcome bonus fields
            const enableWelcomeCheckbox = document.getElementById('setting_enable_welcome_bonus');
            const welcomePointsField = document.getElementById('setting_welcome_bonus_points');
            const welcomePointsContainer = welcomePointsField.closest('.col-md-6');
            
            // Birthday bonus fields
            const enableBirthdayCheckbox = document.getElementById('setting_enable_birthday_bonus');
            const birthdayPointsField = document.getElementById('setting_birthday_bonus_points');
            const birthdayPointsContainer = birthdayPointsField.closest('.col-md-6');
            
            // Referral bonus fields
            const enableReferralCheckbox = document.getElementById('setting_enable_referral_bonus');
            const referralPointsField = document.getElementById('setting_referral_bonus_points');
            const referralPointsContainer = referralPointsField.closest('.col-md-6');
            
            // Spending-based points fields
            const enableSpendingCheckbox = document.getElementById('setting_enable_spending_points');
            const calculationMethodSelect = document.getElementById('setting_points_calculation_method');
            const spendingThresholdField = document.getElementById('setting_spending_threshold');
            const spendingThresholdContainer = spendingThresholdField.closest('.col-md-6');
            const pointsPerThresholdField = document.getElementById('setting_points_per_threshold');
            const pointsPerThresholdContainer = pointsPerThresholdField.closest('.col-md-6');
            const pointsPercentageField = document.getElementById('setting_points_percentage');
            const pointsPercentageContainer = pointsPercentageField.closest('.col-md-6');
            const fixedPointsAmountField = document.getElementById('setting_fixed_points_amount');
            const fixedPointsAmountContainer = fixedPointsAmountField.closest('.col-md-6');
            const fixedSpendingAmountField = document.getElementById('setting_fixed_spending_amount');
            const fixedSpendingAmountContainer = fixedSpendingAmountField.closest('.col-md-6');
            const includeTaxCheckbox = document.getElementById('setting_include_tax_in_calculation');
            const includeTaxContainer = includeTaxCheckbox.closest('.col-md-6');
            const pointsPerCurrencyField = document.getElementById('setting_points_per_currency');
            const pointsPerCurrencyContainer = pointsPerCurrencyField.closest('.col-md-6');
            
            function toggleMaxLimitField() {
                if (enableMaxCheckbox.checked) {
                    maxLimitContainer.style.display = 'block';
                    updateMaxPointsDisplay();
                } else {
                    maxLimitContainer.style.display = 'none';
                    maxPointsDisplay.textContent = 'No limit';
                }
            }
            
            function toggleWelcomePointsField() {
                if (enableWelcomeCheckbox.checked) {
                    welcomePointsContainer.style.display = 'block';
                } else {
                    welcomePointsContainer.style.display = 'none';
                }
            }
            
            function toggleBirthdayPointsField() {
                if (enableBirthdayCheckbox.checked) {
                    birthdayPointsContainer.style.display = 'block';
                } else {
                    birthdayPointsContainer.style.display = 'none';
                }
            }
            
            function toggleReferralPointsField() {
                if (enableReferralCheckbox.checked) {
                    referralPointsContainer.style.display = 'block';
                } else {
                    referralPointsContainer.style.display = 'none';
                }
            }
            
            function toggleSpendingPointsFields() {
                if (enableSpendingCheckbox.checked) {
                    // Show calculation method and tax inclusion
                    calculationMethodSelect.closest('.col-md-6').style.display = 'block';
                    includeTaxContainer.style.display = 'block';
                    pointsPerCurrencyContainer.style.display = 'block';
                    
                    // Show method-specific fields
                    toggleCalculationMethodFields();
                } else {
                    // Hide all spending-related fields
                    calculationMethodSelect.closest('.col-md-6').style.display = 'none';
                    spendingThresholdContainer.style.display = 'none';
                    pointsPerThresholdContainer.style.display = 'none';
                    pointsPercentageContainer.style.display = 'none';
                    fixedPointsAmountContainer.style.display = 'none';
                    fixedSpendingAmountContainer.style.display = 'none';
                    includeTaxContainer.style.display = 'none';
                    pointsPerCurrencyContainer.style.display = 'none';
                }
            }
            
            function toggleCalculationMethodFields() {
                const method = calculationMethodSelect.value;
                
                // Hide all method-specific fields first
                spendingThresholdContainer.style.display = 'none';
                pointsPerThresholdContainer.style.display = 'none';
                pointsPercentageContainer.style.display = 'none';
                fixedPointsAmountContainer.style.display = 'none';
                fixedSpendingAmountContainer.style.display = 'none';
                
                // Show fields based on selected method
                switch (method) {
                    case 'threshold':
                        spendingThresholdContainer.style.display = 'block';
                        pointsPerThresholdContainer.style.display = 'block';
                        break;
                    case 'percentage':
                        pointsPercentageContainer.style.display = 'block';
                        break;
                    case 'fixed_rate':
                        fixedPointsAmountContainer.style.display = 'block';
                        fixedSpendingAmountContainer.style.display = 'block';
                        break;
                }
            }
            
            function updateMaxPointsDisplay() {
                if (enableMaxCheckbox.checked) {
                    const maxLimit = maxLimitField.value || '10000';
                    maxPointsDisplay.textContent = maxLimit + ' points';
                } else {
                    maxPointsDisplay.textContent = 'No limit';
                }
            }
            
            // Initial state
            toggleMaxLimitField();
            toggleWelcomePointsField();
            toggleBirthdayPointsField();
            toggleReferralPointsField();
            toggleSpendingPointsFields();
            
            // Toggle on change
            enableMaxCheckbox.addEventListener('change', toggleMaxLimitField);
            maxLimitField.addEventListener('input', updateMaxPointsDisplay);
            
            enableWelcomeCheckbox.addEventListener('change', toggleWelcomePointsField);
            enableBirthdayCheckbox.addEventListener('change', toggleBirthdayPointsField);
            enableReferralCheckbox.addEventListener('change', toggleReferralPointsField);
            enableSpendingCheckbox.addEventListener('change', toggleSpendingPointsFields);
            calculationMethodSelect.addEventListener('change', toggleCalculationMethodFields);
        });
        
        // Add points validation
        function validateAddPoints() {
            const points = parseInt(document.querySelector('#addPointsModal input[name="points"]').value);
            const enableMax = document.getElementById('setting_enable_points_maximum').checked;
            const maxLimit = parseInt(document.getElementById('setting_maximum_points_limit').value);
            
            if (enableMax && points > maxLimit) {
                alert('Points cannot exceed the maximum limit of ' + maxLimit + ' points.');
                return false;
            }
            
            return true;
        }
        
        // Redeem points validation
        function validateRedeemPoints() {
            const points = parseInt(document.querySelector('#redeemPointsModal input[name="points"]').value);
            const minRedemption = parseInt(document.getElementById('setting_minimum_redemption_points').value);
            
            if (points < minRedemption) {
                alert('Minimum redemption is ' + minRedemption + ' points.');
                return false;
            }
            
            return true;
        }
        
        // Demo points calculator
        function calculateDemoPoints() {
            const amount = parseFloat(document.getElementById('demoAmount').value) || 0;
            const enableSpending = document.getElementById('setting_enable_spending_points').checked;
            
            if (!enableSpending) {
                document.getElementById('demoPoints').textContent = '0 (spending points disabled)';
                document.getElementById('demoResult').style.display = 'block';
                return;
            }
            
            const method = document.getElementById('setting_points_calculation_method').value;
            const includeTax = document.getElementById('setting_include_tax_in_calculation').checked;
            const pointsPerCurrency = parseFloat(document.getElementById('setting_points_per_currency').value) || 1;
            
            // Simulate tax amount (assuming 16% tax)
            const taxAmount = includeTax ? 0 : (amount * 0.16);
            const calculationAmount = includeTax ? amount : (amount - taxAmount);
            
            let points = 0;
            
            switch (method) {
                case 'threshold':
                    const threshold = parseFloat(document.getElementById('setting_spending_threshold').value) || 100;
                    const pointsPerThreshold = parseInt(document.getElementById('setting_points_per_threshold').value) || 10;
                    const thresholdsReached = Math.floor(calculationAmount / threshold);
                    points = thresholdsReached * pointsPerThreshold;
                    break;
                    
                case 'percentage':
                    const percentage = parseFloat(document.getElementById('setting_points_percentage').value) || 5;
                    points = (calculationAmount * percentage) / 100;
                    break;
                    
                case 'fixed_rate':
                    const fixedSpending = parseFloat(document.getElementById('setting_fixed_spending_amount').value) || 100;
                    const fixedPoints = parseInt(document.getElementById('setting_fixed_points_amount').value) || 10;
                    const cycles = Math.floor(calculationAmount / fixedSpending);
                    points = cycles * fixedPoints;
                    break;
            }
            
            // Add currency points
            points += calculationAmount * pointsPerCurrency;
            
            document.getElementById('demoPoints').textContent = Math.floor(points);
            document.getElementById('demoResult').style.display = 'block';
        }
    </script>

    <!-- Custom Points Modal -->
    <div class="modal fade" id="customPointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_custom_points">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Custom Points</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer_id" class="form-label">Customer *</label>
                            <select class="form-select" name="customer_id" id="customer_id" required>
                                <option value="">Select a customer</option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?> 
                                    (<?php echo htmlspecialchars($customer['phone']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points_type" class="form-label">Points Type *</label>
                            <select class="form-select" name="points_type" id="points_type" required>
                                <option value="welcome">Welcome Points</option>
                                <option value="bonus">Bonus Points</option>
                                <option value="adjustment">Adjustment</option>
                                <option value="promotion">Promotion</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points Amount *</label>
                            <input type="number" class="form-control" name="points" id="points" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="description" rows="3" placeholder="e.g., Welcome bonus, Special promotion, Manual adjustment"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Points</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Welcome Points Modal -->
    <div class="modal fade" id="welcomePointsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_welcome_points">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-star"></i> Edit Welcome Points</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Welcome Points</strong> are automatically awarded to new customers when they register.
                        </div>
                        <div class="mb-3">
                            <label for="welcome_points" class="form-label">Welcome Points Amount *</label>
                            <input type="number" class="form-control" name="welcome_points" id="welcome_points" 
                                   value="<?php echo $welcomePoints; ?>" min="0" required>
                            <div class="form-text">Points awarded to new customers upon registration</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update Welcome Points</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toggle Loyalty Program Modal -->
    <div class="modal fade" id="toggleLoyaltyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="toggle_loyalty_program">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-power"></i> 
                            <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'Disable' : 'Enable'; ?> Loyalty Program
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-<?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'warning' : 'info'; ?>">
                            <i class="bi bi-<?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'exclamation-triangle' : 'info-circle'; ?> me-2"></i>
                            <strong>Current Status:</strong> 
                            <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'Enabled' : 'Disabled'; ?>
                        </div>
                        
                        <?php if (($settings['loyalty_program_enabled'] ?? '1') == '1'): ?>
                        <p>Disabling the loyalty program will:</p>
                        <ul>
                            <li>Stop awarding points for new purchases</li>
                            <li>Prevent customers from redeeming points</li>
                            <li>Keep existing points data intact</li>
                            <li>Allow re-enabling at any time</li>
                        </ul>
                        <?php else: ?>
                        <p>Enabling the loyalty program will:</p>
                        <ul>
                            <li>Start awarding points for new purchases</li>
                            <li>Allow customers to redeem existing points</li>
                            <li>Activate all loyalty features</li>
                        </ul>
                        <?php endif; ?>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="enable_loyalty" id="enable_loyalty" 
                                   <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? '' : 'checked'; ?>>
                            <label class="form-check-label" for="enable_loyalty">
                                <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'I understand and want to disable the loyalty program' : 'I want to enable the loyalty program'; ?>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-<?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'danger' : 'success'; ?>">
                            <?php echo ($settings['loyalty_program_enabled'] ?? '1') == '1' ? 'Disable Program' : 'Enable Program'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>
