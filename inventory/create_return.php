<?php
session_start();

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        // User doesn't exist, log them out
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
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

// Check if user has permission to create returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Error loading settings: " . $e->getMessage());
}

// Check if required tables exist
try {
    $tables_check = $conn->query("SHOW TABLES LIKE 'returns'");
    if ($tables_check->rowCount() == 0) {
        error_log("ERROR: 'returns' table does not exist");
        $message = "Database tables are not set up. Please run the database setup first.";
        $message_type = 'danger';
    } else {
        // Check if status column exists in returns table
        $stmt = $conn->query("DESCRIBE returns");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('status', $columns)) {
            error_log("ERROR: 'status' column missing from returns table");
            $message = "Database schema is incomplete. Please run the database fix script: <a href='fix_returns_table.php'>Fix Database Schema</a>";
            $message_type = 'danger';
        }
    }
    
    $tables_check = $conn->query("SHOW TABLES LIKE 'return_items'");
    if ($tables_check->rowCount() == 0) {
        error_log("ERROR: 'return_items' table does not exist");
        if (empty($message)) {
            $message = "Database tables are not set up. Please run the database setup first.";
            $message_type = 'danger';
        }
    }
} catch (PDOException $e) {
    error_log("Error checking tables: " . $e->getMessage());
    $message = "Database error. Please check your database connection.";
    $message_type = 'danger';
}

// Function to generate return number
function generateReturnNumber($settings) {
    $prefix = $settings['return_number_prefix'] ?? 'RTN';
    $length = intval($settings['return_number_length'] ?? 6);
    $separator = $settings['return_number_separator'] ?? '-';
    $format = $settings['return_number_format'] ?? 'prefix-date-number';

    $sequentialNumber = getNextReturnNumber($conn, $length, $settings);
    $currentDate = date('Ymd');

    switch ($format) {
        case 'prefix-date-number':
            return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
        case 'prefix-number':
            return $prefix . $separator . $sequentialNumber;
        case 'date-prefix-number':
            return $currentDate . $separator . $prefix . $separator . $sequentialNumber;
        case 'number-only':
            return $sequentialNumber;
        default:
            return $prefix . $separator . $currentDate . $separator . $sequentialNumber;
    }
}

// Function to get next return number
function getNextReturnNumber($conn, $length, $settings) {
    try {
        $prefix = $settings['return_number_prefix'] ?? 'RTN';
        $format = $settings['return_number_format'] ?? 'prefix-date-number';
        $separator = $settings['return_number_separator'] ?? '-';

        // Build the pattern based on format
        $today = date('Ymd');
        $pattern = '';

        switch ($format) {
            case 'prefix-date-number':
                $pattern = $prefix . $separator . $today . $separator . '%';
                break;
            case 'prefix-number':
                $pattern = $prefix . $separator . '%';
                break;
            case 'date-prefix-number':
                $pattern = $today . $separator . $prefix . $separator . '%';
                break;
            case 'number-only':
                $pattern = '%';
                break;
            default:
                $pattern = $prefix . $separator . $today . $separator . '%';
        }

        // Get the highest return number matching the pattern
        $stmt = $conn->prepare("
            SELECT return_number
            FROM returns
            WHERE return_number LIKE ?
            ORDER BY return_number DESC
            LIMIT 1
        ");
        $stmt->execute([$pattern]);
        $lastReturn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lastReturn) {
            // Extract the sequential number and increment
            $parts = explode($separator, $lastReturn['return_number']);
            $lastNumber = intval(end($parts));
            $nextNumber = $lastNumber + 1;
        } else {
            // First return
            $nextNumber = 1;
        }

        return str_pad($nextNumber, $length, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        error_log("Error getting next return number: " . $e->getMessage());
        // Fallback to random number
        return str_pad(mt_rand(1, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

// Get suppliers for dropdown
$suppliers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading suppliers: " . $e->getMessage());
}

// Handle form submission
$message = '';
$message_type = '';
$return_data = [];

// Handle AJAX requests for order data
if (isset($_GET['action']) && $_GET['action'] === 'get_order_data') {
    header('Content-Type: application/json');

    $order_id = $_GET['order_id'] ?? '';
    if (empty($order_id)) {
        echo json_encode(['success' => false, 'message' => 'Order ID is required']);
        exit();
    }

    try {
        // Get order details with items that have been received
        $stmt = $conn->prepare("
            SELECT io.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name
            FROM inventory_orders io
            LEFT JOIN suppliers s ON io.supplier_id = s.id
            LEFT JOIN users u ON io.user_id = u.id
            WHERE (io.id = :order_id OR io.order_number = :order_number)
            AND io.status = 'received'
        ");
        $stmt->execute([
            ':order_id' => is_numeric($order_id) ? $order_id : 0,
            ':order_number' => $order_id
        ]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found or not yet received']);
            exit();
        }

        // Get order items that have been received
        $stmt = $conn->prepare("
            SELECT ioi.*,
                   p.name as product_name, p.sku, p.description, p.image_url,
                   c.name as category_name, b.name as brand_name,
                   p.quantity as current_stock
            FROM inventory_order_items ioi
            LEFT JOIN products p ON ioi.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ioi.order_id = :order_id
            AND ioi.received_quantity > 0
            ORDER BY ioi.id ASC
        ");
        $stmt->bindParam(':order_id', $order['id']);
        $stmt->execute();
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'order' => $order]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle AJAX requests for product search
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');

    $query = $_GET['q'] ?? '';
    $supplier_id = $_GET['supplier_id'] ?? '';

    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Search query is required']);
        exit();
    }

    if (empty($supplier_id)) {
        echo json_encode(['success' => false, 'message' => 'Supplier must be selected to search for products']);
        exit();
    }

    try {
        
        // Search products directly by supplier_id from products table
        $sql = "
            SELECT DISTINCT p.id, p.name, p.sku, p.barcode, p.description, p.image_url,
                   p.quantity as current_stock, p.cost_price,
                   c.name as category_name, b.name as brand_name,
                   p.quantity as max_return_qty,
                   p.cost_price as order_cost_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE p.supplier_id = :supplier_id
            AND p.status = 'active'
            AND (LOWER(p.name) LIKE LOWER(:query) 
                 OR LOWER(p.sku) LIKE LOWER(:query) 
                 OR LOWER(p.barcode) LIKE LOWER(:query) 
                 OR LOWER(p.description) LIKE LOWER(:query)
                 OR LOWER(c.name) LIKE LOWER(:query)
                 OR LOWER(b.name) LIKE LOWER(:query)
                 OR LOWER(CONCAT(p.name, ' ', p.sku, ' ', COALESCE(p.barcode, ''), ' ', COALESCE(p.description, ''))) LIKE LOWER(:query))
            ORDER BY 
                CASE 
                    WHEN LOWER(p.name) LIKE LOWER(:query) THEN 1
                    WHEN LOWER(p.sku) LIKE LOWER(:query) THEN 2
                    WHEN LOWER(p.barcode) LIKE LOWER(:query) THEN 3
                    ELSE 4
                END,
                p.name ASC
            LIMIT 20
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':supplier_id' => $supplier_id,
            ':query' => '%' . $query . '%'
        ]);

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'products' => $products]);
    } catch (PDOException $e) {
        error_log("Search error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle draft saving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    header('Content-Type: application/json');
    
    $supplier_id = $_POST['supplier_id'] ?? '';
    $return_reason = $_POST['return_reason'] ?? '';
    $return_notes = $_POST['return_notes'] ?? '';
    $return_items = json_decode($_POST['return_items'] ?? '[]', true);
    
    if (empty($supplier_id) || empty($return_reason)) {
        echo json_encode(['success' => false, 'message' => 'Supplier and reason are required']);
        exit();
    }
    
    try {
        $conn->beginTransaction();
        
        // Generate return number for draft
        $return_number = '';
        if (isset($settings['auto_generate_return_number']) && $settings['auto_generate_return_number'] == '1') {
            $return_number = generateReturnNumber($settings);
        } else {
            // Use admin settings even when auto-generation is disabled
            $prefix = $settings['return_number_prefix'] ?? 'RTN';
            $separator = $settings['return_number_separator'] ?? '-';
            $return_number = $prefix . $separator . date('Ymd') . $separator . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        }
        
        // Ensure return number is unique
        $attempts = 0;
        do {
            $stmt = $conn->prepare("SELECT id FROM returns WHERE return_number = ?");
            $stmt->execute([$return_number]);
            if ($stmt->rowCount() > 0) {
                // Generate a new number if duplicate found using admin settings
                $prefix = $settings['return_number_prefix'] ?? 'RTN';
                $separator = $settings['return_number_separator'] ?? '-';
                $return_number = $prefix . $separator . date('Ymd') . $separator . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                $attempts++;
            } else {
                break;
            }
        } while ($attempts < 10);
        
        // Calculate totals
        $total_items = 0;
        $total_amount = 0;
        foreach ($return_items as $item) {
            $total_items += $item['quantity'];
            $total_amount += ($item['quantity'] * $item['cost_price']);
        }
        
        // Insert draft return
        $stmt = $conn->prepare("
            INSERT INTO returns (
                return_number, supplier_id, user_id, return_reason,
                return_notes, total_items, total_amount,
                status, created_at, updated_at
            ) VALUES (
                :return_number, :supplier_id, :user_id, :return_reason,
                :return_notes, :total_items, :total_amount,
                'draft', NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':return_number' => $return_number,
            ':supplier_id' => $supplier_id,
            ':user_id' => $user_id,
            ':return_reason' => $return_reason,
            ':return_notes' => $return_notes,
            ':total_items' => $total_items,
            ':total_amount' => $total_amount
        ]);
        
        $return_id = $conn->lastInsertId();
        
        // Insert return items if any
        if (!empty($return_items)) {
            $stmt = $conn->prepare("
                INSERT INTO return_items (
                    return_id, product_id, quantity, cost_price,
                    return_reason, notes, action_taken
                ) VALUES (
                    :return_id, :product_id, :quantity, :cost_price,
                    :return_reason, :notes, 'draft'
                )
            ");
            
            foreach ($return_items as $item) {
                $stmt->execute([
                    ':return_id' => $return_id,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':cost_price' => $item['cost_price'],
                    ':return_reason' => $item['return_reason'] ?? '',
                    ':notes' => $item['notes'] ?? ''
                ]);
            }
        }
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'draft_id' => $return_id, 'message' => 'Draft saved successfully']);
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error saving draft: ' . $e->getMessage()]);
        exit();
    }
}

// Handle loading existing draft
$draft_id = $_GET['draft_id'] ?? '';
$draft_data = null;
if ($draft_id) {
    try {
        $stmt = $conn->prepare("
            SELECT r.*, s.name as supplier_name
            FROM returns r
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            WHERE r.id = :draft_id AND r.status = 'draft' AND r.user_id = :user_id
        ");
        $stmt->execute([':draft_id' => $draft_id, ':user_id' => $user_id]);
        $draft_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($draft_data) {
            // Load draft items
            $stmt = $conn->prepare("
                SELECT ri.*, p.name as product_name, p.sku, p.barcode, p.cost_price
                FROM return_items ri
                LEFT JOIN products p ON ri.product_id = p.id
                WHERE ri.return_id = :draft_id
            ");
            $stmt->execute([':draft_id' => $draft_id]);
            $draft_data['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error loading draft: " . $e->getMessage());
    }
}

// Handle session state persistence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_session_state') {
    header('Content-Type: application/json');
    
    $form_state = [
        'supplier_id' => $_POST['supplier_id'] ?? '',
        'return_reason' => $_POST['return_reason'] ?? '',
        'return_notes' => $_POST['return_notes'] ?? '',
        'return_items' => json_decode($_POST['return_items'] ?? '[]', true),
        'current_step' => intval($_POST['current_step'] ?? 1),
        'product_search_query' => $_POST['product_search_query'] ?? '',
        'return_cart_visible' => $_POST['return_cart_visible'] === 'true',
        'selected_supplier' => $_POST['selected_supplier'] ?? ''
    ];
    
    $_SESSION['return_form_state'] = $form_state;
    
    echo json_encode(['success' => true]);
    exit();
}

// Handle clearing session state
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_session_state') {
    header('Content-Type: application/json');
    
    unset($_SESSION['return_form_state']);
    
    echo json_encode(['success' => true]);
    exit();
}

// Get saved form state from session
$saved_form_state = $_SESSION['return_form_state'] ?? null;

// Handle return creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_return') {
    // Check if database is properly set up
    if (!empty($message) && $message_type === 'danger') {
        // Don't process the form if there are database issues
    } else {
        $supplier_id = $_POST['supplier_id'] ?? '';
        $return_reason = $_POST['return_reason'] ?? '';
        $return_notes = $_POST['return_notes'] ?? '';
        $return_items = json_decode($_POST['return_items'] ?? '[]', true);

    // Validation
    if (empty($supplier_id)) {
        $error_message = "Please select a supplier";
        
        // Check if this is an AJAX request
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit();
        } else {
            $message = $error_message;
            $message_type = 'danger';
        }
    } elseif (empty($return_reason)) {
        $error_message = "Please select a return reason";
        
        // Check if this is an AJAX request
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit();
        } else {
            $message = $error_message;
            $message_type = 'danger';
        }
    } elseif (empty($return_items)) {
        $error_message = "Please add at least one item to return";
        
        // Check if this is an AJAX request
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit();
        } else {
            $message = $error_message;
            $message_type = 'danger';
        }
    } else {
        try {
            $conn->beginTransaction();

            // Generate return number (always generate one)
            $return_number = '';
            if (isset($settings['auto_generate_return_number']) && $settings['auto_generate_return_number'] == '1') {
                $return_number = generateReturnNumber($settings);
            } else {
                // Use admin settings even when auto-generation is disabled
                $prefix = $settings['return_number_prefix'] ?? 'RTN';
                $separator = $settings['return_number_separator'] ?? '-';
                $return_number = $prefix . $separator . date('Ymd') . $separator . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            }
            
            // Ensure return number is not empty
            if (empty($return_number)) {
                $prefix = $settings['return_number_prefix'] ?? 'RTN';
                $separator = $settings['return_number_separator'] ?? '-';
                $return_number = $prefix . $separator . date('Ymd') . $separator . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            }
            
            // Clean up any existing empty return numbers (fix for existing data)
            $prefix = $settings['return_number_prefix'] ?? 'RTN';
            $separator = $settings['return_number_separator'] ?? '-';
            $conn->exec("UPDATE returns SET return_number = CONCAT('{$prefix}{$separator}', id, '{$separator}', UNIX_TIMESTAMP()) WHERE return_number = '' OR return_number IS NULL");
            
            // Ensure return number is unique
            $attempts = 0;
            do {
                $stmt = $conn->prepare("SELECT id FROM returns WHERE return_number = ?");
                $stmt->execute([$return_number]);
                if ($stmt->rowCount() > 0) {
                    // Generate a new number if duplicate found using admin settings
                    $prefix = $settings['return_number_prefix'] ?? 'RTN';
                    $separator = $settings['return_number_separator'] ?? '-';
                    $return_number = $prefix . $separator . date('Ymd') . $separator . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                    $attempts++;
                } else {
                    break;
                }
            } while ($attempts < 10); // Prevent infinite loop

            // Determine initial status based on settings
            $initial_status = $settings['return_default_status'] ?? 'pending';
            if (isset($settings['return_auto_approve']) && $settings['return_auto_approve'] == '1') {
                $initial_status = 'approved';
            }

            // Calculate totals
            $total_items = 0;
            $total_amount = 0;
            foreach ($return_items as $item) {
                $total_items += $item['quantity'];
                $total_amount += ($item['quantity'] * $item['cost_price']);
            }

            // Insert return record
            $stmt = $conn->prepare("
                INSERT INTO returns (
                    return_number, supplier_id, user_id, return_reason,
                    return_notes, total_items, total_amount,
                    status, created_at, updated_at
                ) VALUES (
                    :return_number, :supplier_id, :user_id, :return_reason,
                    :return_notes, :total_items, :total_amount,
                    :status, NOW(), NOW()
                )
            ");

            $stmt->execute([
                ':return_number' => $return_number,
                ':supplier_id' => $supplier_id,
                ':user_id' => $user_id,
                ':return_reason' => $return_reason,
                ':return_notes' => $return_notes,
                ':total_items' => $total_items,
                ':total_amount' => $total_amount,
                ':status' => $initial_status
            ]);

            $return_id = $conn->lastInsertId();

            // Insert return items
            $stmt = $conn->prepare("
                INSERT INTO return_items (
                    return_id, product_id, quantity, cost_price,
                    return_reason, notes, action_taken
                ) VALUES (
                    :return_id, :product_id, :quantity, :cost_price,
                    :return_reason, :notes, :action_taken
                )
            ");

            foreach ($return_items as $item) {
                // Set item status - negative quantities for pending returns
                $item_status = $initial_status;
                $item_quantity = $item['quantity'];

                // If allowing negative stock for returns, handle quantity
                if (isset($settings['return_allow_negative_stock']) && $settings['return_allow_negative_stock'] == '1') {
                    // For returns, quantity can be positive (decrease stock) or negative (increase stock)
                    // We'll use negative to indicate stock increase for returns
                    if ($item_quantity > 0) {
                        $item_quantity = -$item_quantity; // Make negative for return
                    }
                }

                $stmt->execute([
                    ':return_id' => $return_id,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item_quantity,
                    ':cost_price' => $item['cost_price'],
                    ':return_reason' => $item['return_reason'] ?? '',
                    ':notes' => $item['notes'] ?? '',
                    ':action_taken' => $item_status
                ]);

                // Update product stock based on approval status
                if ($initial_status === 'approved') {
                    // Only update stock if return is approved
                    $current_stock_stmt = $conn->prepare("SELECT quantity FROM products WHERE id = :product_id");
                    $current_stock_stmt->execute([':product_id' => $item['product_id']]);
                    $current_stock = $current_stock_stmt->fetchColumn();

                    // For returns, we need to DEDUCT stock (decrease it) when approved
                    $return_quantity = abs($item['quantity']); // Use absolute value for stock deduction
                    $new_quantity = $current_stock - $return_quantity;

                    // Check if negative stock is allowed
                    $allow_negative = isset($settings['return_allow_negative_stock']) && $settings['return_allow_negative_stock'] == '1';
                    if (!$allow_negative && $new_quantity < 0) {
                        throw new Exception("Insufficient stock for product ID {$item['product_id']}. Current stock: {$current_stock}, Required: {$return_quantity}");
                    }

                    $update_stmt = $conn->prepare("
                        UPDATE products
                        SET quantity = :new_quantity, updated_at = NOW()
                        WHERE id = :product_id
                    ");
                    $update_stmt->execute([
                        ':new_quantity' => $new_quantity,
                        ':product_id' => $item['product_id']
                    ]);
                }
                // If status is pending, stock will be updated when approved later
            }

                       // Log activity
                       $status_text = $initial_status === 'approved' ? 'approved' : 'pending approval';
                       logActivity($conn, $user_id, 'return_created',
                           "Created return {$return_number} for supplier ID {$supplier_id} ({$status_text})");

                       $conn->commit();
                       
                       // Clear session state after successful submission
                       unset($_SESSION['return_form_state']);

                       // Check if this is an AJAX request
                       if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                           // Return JSON response for AJAX
                           header('Content-Type: application/json');
                           echo json_encode([
                               'success' => true,
                               'return_id' => $return_id,
                               'return_number' => $return_number,
                               'status' => $initial_status,
                               'message' => $initial_status === 'approved' ? 'Return created and approved successfully!' : 'Return created successfully and is pending approval!'
                           ]);
                           exit();
                       } else {
                           // Redirect based on status for normal form submission
                           if ($initial_status === 'approved') {
                               header("Location: view_return.php?id=" . $return_id . "&success=return_created_approved");
                           } else {
                               header("Location: view_return.php?id=" . $return_id . "&success=return_created_pending");
                           }
                           exit();
                       }

        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Error creating return: " . $e->getMessage();
            
            // Check if this is an AJAX request
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $error_message]);
                exit();
            } else {
                $message = $error_message;
                $message_type = 'danger';
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error_message = "Error creating return: " . $e->getMessage();
            
            // Check if this is an AJAX request
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $error_message]);
                exit();
            } else {
                $message = $error_message;
                $message_type = 'danger';
            }
        }
    }
    } // Close the else block for database check
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Return - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .return-form {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .return-form-header {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .return-form-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .return-form-subtitle {
            color: #64748b;
            font-size: 0.875rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .step.active .step-circle {
            background: var(--primary-color);
            color: white;
        }

        .step.completed .step-circle {
            background: #28a745;
            color: white;
        }

        .step-label {
            font-size: 0.875rem;
            margin-top: 0.5rem;
            color: #6c757d;
            font-weight: 500;
            text-align: center;
            min-width: 80px;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: #28a745;
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: #e9ecef;
            margin: 0 1rem;
            position: relative;
            top: -20px;
        }

        .step.completed + .step-line {
            background: #28a745;
        }

        .step.active ~ .step-line {
            background: var(--primary-color);
        }

        .product-selection {
            max-height: 400px;
            overflow-y: auto;
        }

        .product-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            background: white;
            transition: all 0.2s ease;
        }

        .product-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
        }

        .product-info {
            flex: 1;
            margin-left: 1rem;
        }

        .product-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }

        .product-details {
            color: #64748b;
            font-size: 0.875rem;
        }

        .return-cart {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            animation: slideInUp 0.3s ease-out;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .return-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }

        .return-item:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .search-ready {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        .return-item-info {
            flex: 1;
        }

        .return-item-controls {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.25rem;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .quantity-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .quantity-btn:active {
            transform: translateY(0);
        }

        .quantity-input {
            width: 70px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.5rem 0.25rem;
            font-weight: 600;
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
        }

        .quantity-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.1);
        }

        .quantity-input::-webkit-outer-spin-button,
        .quantity-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .quantity-input[type=number] {
            -moz-appearance: textfield;
        }

        /* Quantity Modal Styles */
        .quantity-selection {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .quantity-controls-large {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            justify-content: center;
            margin: 1rem 0;
        }

        .quantity-btn-large {
            width: 48px;
            height: 48px;
            border: 2px solid #dee2e6;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 18px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .quantity-btn-large:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .quantity-btn-large:active:not(:disabled) {
            transform: translateY(0);
        }

        .quantity-btn-large:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
        }

        .quantity-btn-large:disabled:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
        }

        .quantity-btn-large.disabled {
            position: relative;
        }

        .quantity-btn-large.disabled::after {
            content: "Min: 1";
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: #6c757d;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .quantity-btn-large.disabled:hover::after {
            opacity: 1;
        }

        .quantity-input-large {
            width: 100px;
            text-align: center;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            font-weight: 700;
            font-size: 18px;
            background: white;
            transition: all 0.2s ease;
        }

        .quantity-input-large:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .quantity-input-large::-webkit-outer-spin-button,
        .quantity-input-large::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .quantity-input-large[type=number] {
            -moz-appearance: textfield;
        }

        /* Modal enhancements */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            border-radius: 12px 12px 0 0;
            border-bottom: none;
        }

        .modal-footer {
            border-top: 1px solid #e9ecef;
            border-radius: 0 0 12px 12px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h2>Create Product Return</h2>
                    <p class="header-subtitle">Process returns for defective or unwanted products</p>
                </div>
                <div class="header-actions">
                    <a href="inventory.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Inventory
                    </a>
                    <a href="view_returns.php" class="btn btn-outline-primary">
                        <i class="bi bi-list me-2"></i>View Returns
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-triangle' : 'info-circle'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step active" id="step1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Select Supplier</div>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Choose Products</div>
                </div>
                <div class="step-line"></div>
                <div class="step" id="step3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Review & Submit</div>
                </div>
            </div>

            <form id="returnForm" method="POST">
                <input type="hidden" name="action" value="create_return">
                <input type="hidden" name="return_items" id="returnItemsInput" value="[]">

                <!-- Step 1: Supplier Selection -->
                <div class="return-form" id="supplierStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-building me-2"></i>Step 1: Select Supplier</h3>
                        <p class="return-form-subtitle">Choose the supplier you're returning products to</p>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label for="supplierSelect" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplierSelect" name="supplier_id" required>
                                <option value="">Choose a supplier...</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="returnReason" class="form-label">Return Reason <span class="text-danger">*</span></label>
                            <select class="form-select" id="returnReason" name="return_reason" required>
                                <option value="">Select return reason...</option>
                                <option value="defective">Defective Products</option>
                                <option value="wrong_item">Wrong Items Received</option>
                                <option value="damaged">Damaged in Transit</option>
                                <option value="expired">Expired Products</option>
                                <option value="overstock">Overstock/Excess Inventory</option>
                                <option value="quality">Quality Issues</option>
                                <option value="recall">Product Recall</option>
                                <option value="customer_return">Customer Return</option>
                                <option value="warranty">Warranty Claim</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-12">
                            <label for="returnNotes" class="form-label">Return Notes</label>
                            <textarea class="form-control" id="returnNotes" name="return_notes" rows="3"
                                      placeholder="Additional notes about this return..."></textarea>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" class="btn btn-primary" id="nextToProductsBtn" disabled>
                            <i class="bi bi-arrow-right me-2"></i>Next: Select Products
                        </button>
                    </div>
                </div>

                <!-- Step 2: Product Selection -->
                <div class="return-form d-none" id="productsStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-box-seam me-2"></i>Step 2: Select Products to Return</h3>
                        <p class="return-form-subtitle">Choose products from received orders to return to the supplier</p>
                    </div>

                    <!-- Product Search -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label for="productSearch" class="form-label mb-0">Search Products from Selected Supplier</label>
                            <div id="searchStatus" class="text-muted small" style="display: none;">
                                <i class="bi bi-check-circle text-success me-1"></i>
                                Ready to add more products
                            </div>
                        </div>
                        <div class="input-group">
                            <input type="text" class="form-control" id="productSearch"
                                   placeholder="Search by name, SKU, barcode, description, category, or brand..."
                                   disabled>
                            <button class="btn btn-outline-secondary" type="button" id="searchBtn" disabled>
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Only products associated with the selected supplier can be returned.
                            Search results will show products directly linked to this supplier.
                        </div>
                    </div>

                    <!-- Product List -->
                    <div id="productList" class="product-selection">
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-search fs-1 mb-3"></i>
                            <p>Select a supplier and search for products to get started.</p>
                        </div>
                    </div>

                    <!-- Return Cart -->
                    <div class="return-cart" id="returnCart" style="display: none;">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="mb-0"><i class="bi bi-cart me-2"></i>Return Items</h5>
                            <span class="badge bg-primary" id="cartItemCount">0 items</span>
                        </div>
                        
                        <!-- Return Items Table -->
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th>Unit Price</th>
                                        <th>Quantity</th>
                                        <th>Total</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="returnItemsList">
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="bi bi-cart-x fs-1 mb-3"></i>
                                            <p class="mb-0">No items added yet</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="text-muted">
                                <small>Total: <span id="cartTotalValue" class="fw-bold">$0.00</span></small>
                            </div>
                            <button type="button" class="btn btn-success" id="proceedToReviewBtn">
                                <i class="bi bi-check-circle me-2"></i>Review Return
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" id="backToSupplierBtn">
                            <i class="bi bi-arrow-left me-2"></i>Back to Supplier
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" id="nextToReviewBtn" style="display: none;">
                            <i class="bi bi-arrow-right me-2"></i>Next: Review & Submit
                        </button>
                    </div>
                </div>

                <!-- Step 3: Review and Submit -->
                <div class="return-form d-none" id="reviewStep">
                    <div class="return-form-header">
                        <h3 class="return-form-title"><i class="bi bi-check-circle me-2"></i>Step 3: Review Return</h3>
                        <p class="return-form-subtitle">Review your return details before submitting</p>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Supplier Information</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Supplier:</strong> <span id="reviewSupplierName">Not selected</span></p>
                                    <p class="mb-1"><strong>Reason:</strong> <span id="reviewReturnReason">Not selected</span></p>
                                    <p class="mb-0"><strong>Notes:</strong> <span id="reviewReturnNotes">None</span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">Return Summary</h6>
                                    <div id="draftBadge" class="d-none mt-2">
                                        <span class="badge bg-info">
                                            <i class="bi bi-file-earmark-text me-1"></i>Draft Saved
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Total Items:</strong> <span id="reviewTotalItems">0</span></p>
                                    <p class="mb-1"><strong>Total Value:</strong> <span id="reviewTotalValue">$0.00</span></p>
                                    <p class="mb-1"><strong>Return Number:</strong> <span id="reviewReturnNumber" class="text-primary fw-bold">Will be generated</span></p>
                                    <p class="mb-0"><strong>Created By:</strong> <span id="reviewCreatedBy"><?php echo htmlspecialchars($username); ?></span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <h6>Items to Return:</h6>
                        <div id="reviewItemsList" class="mt-3">
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-cart-x fs-1 mb-3"></i>
                                <p>No items selected for return.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" id="backToProductsBtn">
                            <i class="bi bi-arrow-left me-2"></i>Back to Products
                        </button>
                        
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn btn-outline-primary" id="saveAsDraftBtn" style="pointer-events: auto;">
                                <i class="bi bi-save me-2"></i>Save Draft & New
                            </button>
                            <button type="button" class="btn btn-outline-info" id="createNewBtn">
                                <i class="bi bi-plus-circle me-2"></i>Create New
                            </button>
                            <button type="submit" class="btn btn-success" id="submitReturnBtn" disabled>
                                <i class="bi bi-send me-2"></i>Create Return
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedSupplier = null;
        let returnItems = [];
        let currentStep = 1;
        
        // Load draft data if available
        <?php if ($draft_data): ?>
        const draftData = <?php echo json_encode($draft_data); ?>;
        <?php endif; ?>
        
        // Load saved form state if available
        <?php if ($saved_form_state): ?>
        const savedFormState = <?php echo json_encode($saved_form_state); ?>;
        <?php endif; ?>
        
        // Populate form with saved data
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure save as draft button is clickable
            const saveAsDraftBtn = document.getElementById('saveAsDraftBtn');
            if (saveAsDraftBtn) {
                saveAsDraftBtn.disabled = false;
                saveAsDraftBtn.style.pointerEvents = 'auto';
                console.log('Save as draft button initialized');
            } else {
                console.error('Save as draft button not found!');
            }
            // Priority: Draft data > Saved session state
            const dataToLoad = <?php echo $draft_data ? 'draftData' : ($saved_form_state ? 'savedFormState' : 'null'); ?>;
            
            if (dataToLoad) {
                // Set supplier
                document.getElementById('supplierSelect').value = dataToLoad.supplier_id || '';
                selectedSupplier = dataToLoad.supplier_id || dataToLoad.selected_supplier || null;
                
                // Set return reason
                document.getElementById('returnReason').value = dataToLoad.return_reason || '';
                
                // Set return notes
                document.getElementById('returnNotes').value = dataToLoad.return_notes || '';
                
                // Restore current step
                if (dataToLoad.current_step) {
                    currentStep = dataToLoad.current_step;
                    showStep(currentStep);
                }
                
                // Restore product search query
                if (dataToLoad.product_search_query) {
                    document.getElementById('productSearch').value = dataToLoad.product_search_query;
                    // Trigger search if there's a query
                    if (dataToLoad.product_search_query.length >= 1) {
                        searchProducts(dataToLoad.product_search_query);
                    }
                }
                
                // Load return items
                if (dataToLoad.return_items && dataToLoad.return_items.length > 0) {
                    returnItems = dataToLoad.return_items.map(item => ({
                        product_id: item.product_id,
                        product_name: item.product_name,
                        sku: item.sku,
                        barcode: item.barcode,
                        quantity: item.quantity,
                        cost_price: item.cost_price,
                        return_reason: item.return_reason || '',
                        notes: item.notes || ''
                    }));
                    
                    // Update return cart
                    updateReturnCart();
                    
                    // Show return cart if it was visible
                    if (dataToLoad.return_cart_visible !== false) {
                        document.getElementById('returnCart').style.display = 'block';
                    }
                }
                
                // Enable next button if supplier is selected
                if (selectedSupplier) {
                    document.getElementById('nextToProductsBtn').disabled = false;
                    
                    // Enable product search
                    document.getElementById('productSearch').disabled = false;
                    document.getElementById('searchBtn').disabled = false;
                }
                
                // Show success message
                if (<?php echo $draft_data ? 'true' : 'false'; ?>) {
                    showToast('Draft loaded successfully!', 'success');
                    
                    // Show draft badge for loaded drafts
                    const draftBadge = document.getElementById('draftBadge');
                    if (draftBadge) {
                        draftBadge.classList.remove('d-none');
                    }
                } else {
                    showToast('Form state restored!', 'info');
                    
                    // Show draft badge if we have both supplier and reason in restored state
                    const returnReason = dataToLoad.return_reason || '';
                    if (selectedSupplier && returnReason) {
                        const draftBadge = document.getElementById('draftBadge');
                        if (draftBadge) {
                            draftBadge.classList.remove('d-none');
                        }
                    }
                }
            }
        });

        // Currency globals (used across cart and review)
        const currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>';
        const currencyPosition = '<?php echo $settings['currency_position'] ?? 'before'; ?>';

        // Quantity formatter for returns (negative with unit)
        function formatReturnQty(qty) {
            const unit = qty === 1 ? 'pc' : 'pcs';
            return `-${qty} ${unit}`;
        }

        // Step management
        function showStep(stepNumber) {
            // Hide all steps
            document.getElementById('supplierStep').classList.add('d-none');
            document.getElementById('productsStep').classList.add('d-none');
            document.getElementById('reviewStep').classList.add('d-none');

            // Update step indicators
            document.getElementById('step1').classList.remove('active', 'completed');
            document.getElementById('step2').classList.remove('active', 'completed');
            document.getElementById('step3').classList.remove('active', 'completed');

            // Show current step
            if (stepNumber === 1) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('active');
            } else if (stepNumber === 2) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('productsStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step2').classList.add('active');
            } else if (stepNumber === 3) {
                document.getElementById('supplierStep').classList.remove('d-none');
                document.getElementById('productsStep').classList.remove('d-none');
                document.getElementById('reviewStep').classList.remove('d-none');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step2').classList.add('completed');
                document.getElementById('step3').classList.add('active');
            }

            currentStep = stepNumber;
        }

        // Supplier selection
        document.getElementById('supplierSelect').addEventListener('change', function() {
            selectedSupplier = this.value;
            const hasSupplier = !!selectedSupplier;

            document.getElementById('nextToProductsBtn').disabled = !hasSupplier;

            // Enable/disable product search based on supplier selection
            document.getElementById('productSearch').disabled = !hasSupplier;
            document.getElementById('searchBtn').disabled = !hasSupplier;

            if (hasSupplier) {
                // Clear product search and list
                document.getElementById('productSearch').value = '';
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>Search for products associated with the selected supplier to add to your return.</p>
                    </div>
                `;
                
                // Check if we should save as draft
                checkAndSaveDraft();
            } else {
                // Clear and disable search when no supplier selected
                document.getElementById('productSearch').value = '';
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-arrow-left-circle fs-1 mb-3"></i>
                        <p>Please select a supplier first to search for products.</p>
                    </div>
                `;
            }
            
            // Update draft readiness indicator
            updateDraftReadiness();
            
            // Save form state to session
            saveFormState();
        });

        // Return reason selection
        document.getElementById('returnReason').addEventListener('change', function() {
            // Check if we should save as draft
            checkAndSaveDraft();
            
            // Update draft readiness indicator
            updateDraftReadiness();
            
            // Save form state to session
            saveFormState();
        });

        // Return notes
        document.getElementById('returnNotes').addEventListener('input', function() {
            // Save form state to session
            saveFormState();
        });

        // Function to check and save draft
        function checkAndSaveDraft() {
            const supplierId = document.getElementById('supplierSelect').value;
            const returnReason = document.getElementById('returnReason').value;
            const returnNotes = document.getElementById('returnNotes').value;
            
            // Only save draft if both supplier and reason are selected
            if (supplierId && returnReason) {
                saveDraft(supplierId, returnReason, returnNotes);
            }
        }

        // Function to update draft readiness indicator
        function updateDraftReadiness() {
            const supplierId = document.getElementById('supplierSelect').value;
            const returnReason = document.getElementById('returnReason').value;
            const draftBadge = document.getElementById('draftBadge');
            
            if (supplierId && returnReason) {
                // Show that form is ready for draft saving
                if (draftBadge) {
                    draftBadge.classList.remove('d-none');
                    draftBadge.innerHTML = '<i class="bi bi-save me-1"></i>Ready to save as draft';
                }
            } else {
                // Hide draft badge if not ready
                if (draftBadge) {
                    draftBadge.classList.add('d-none');
                }
            }
        }

        // Function to save form state to session
        function saveFormState() {
            const formData = new FormData();
            formData.append('action', 'save_session_state');
            formData.append('supplier_id', document.getElementById('supplierSelect').value);
            formData.append('return_reason', document.getElementById('returnReason').value);
            formData.append('return_notes', document.getElementById('returnNotes').value);
            formData.append('return_items', JSON.stringify(returnItems));
            
            // Save additional UI state
            formData.append('current_step', currentStep);
            formData.append('product_search_query', document.getElementById('productSearch').value);
            formData.append('return_cart_visible', document.getElementById('returnCart').style.display !== 'none');
            formData.append('selected_supplier', selectedSupplier);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Silent save - no notification needed
            })
            .catch(error => {
                console.error('Error saving form state:', error);
            });
        }

        // Function to save draft
        function saveDraft(supplierId, returnReason, returnNotes) {
            const formData = new FormData();
            formData.append('action', 'save_draft');
            formData.append('supplier_id', supplierId);
            formData.append('return_reason', returnReason);
            formData.append('return_notes', returnNotes);
            formData.append('return_items', JSON.stringify(returnItems));

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show draft saved notification
                    showToast('Draft saved successfully!', 'success');
                    
                    // Show draft badge with saved status
                    const draftBadge = document.getElementById('draftBadge');
                    if (draftBadge) {
                        draftBadge.classList.remove('d-none');
                        draftBadge.innerHTML = '<i class="bi bi-check-circle me-1"></i>Draft saved';
                    }
                    
                    // Update URL to include draft ID if provided
                    if (data.draft_id) {
                        const url = new URL(window.location);
                        url.searchParams.set('draft_id', data.draft_id);
                        window.history.replaceState({}, '', url);
                    }
                } else {
                    showToast('Draft save failed: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Error saving draft: ' + error.message, 'error');
            });
        }

        // Function to save draft and reset form for new return
        function saveDraftAndReset(supplierId, returnReason, returnNotes) {
            const formData = new FormData();
            formData.append('action', 'save_draft');
            formData.append('supplier_id', supplierId);
            formData.append('return_reason', returnReason);
            formData.append('return_notes', returnNotes);
            formData.append('return_items', JSON.stringify(returnItems));

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show draft saved notification
                    showToast('Draft saved successfully! Ready to create new return.', 'success');
                    
                    // Reset form for new return
                    resetFormForNewReturn();
                } else {
                    showToast('Draft save failed: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Error saving draft: ' + error.message, 'error');
            });
        }

        // Function to reset form for new return
        function resetFormForNewReturn() {
            // Clear form data
            document.getElementById('supplierSelect').value = '';
            document.getElementById('returnReason').value = '';
            document.getElementById('returnNotes').value = '';
            document.getElementById('productSearch').value = '';
            returnItems = [];
            selectedSupplier = null;
            currentStep = 1;
            
            // Clear return cart
            updateReturnCart();
            
            // Clear review display
            document.getElementById('reviewSupplierName').textContent = 'Not selected';
            document.getElementById('reviewReturnReason').textContent = 'Not selected';
            document.getElementById('reviewReturnNotes').textContent = 'None';
            document.getElementById('reviewItemsList').innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cart-x fs-1 mb-3"></i>
                    <p>No items selected for return.</p>
                </div>
            `;
            
            // Reset buttons
            document.getElementById('nextToProductsBtn').disabled = true;
            document.getElementById('productSearch').disabled = true;
            document.getElementById('searchBtn').disabled = true;
            document.getElementById('submitReturnBtn').disabled = true;
            
            // Hide draft badge
            const draftBadge = document.getElementById('draftBadge');
            if (draftBadge) {
                draftBadge.classList.add('d-none');
            }
            
            // Update draft readiness indicator
            updateDraftReadiness();
            
            // Clear product list
            document.getElementById('productList').innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-search fs-1 mb-3"></i>
                    <p>Type at least 1 character to search for products.</p>
                </div>
            `;
            
            // Go back to step 1
            showStep(1);
            
            // Clear session state
            const formData = new FormData();
            formData.append('action', 'clear_session_state');
            fetch('', {
                method: 'POST',
                body: formData
            }).catch(error => {
                console.error('Error clearing session state:', error);
            });
        }

        // Next to products button
        document.getElementById('nextToProductsBtn').addEventListener('click', function() {
            if (!selectedSupplier) {
                alert('Please select a supplier first.');
                return;
            }
            showStep(2);
            
            // Save form state to session
            saveFormState();
            
            // Show draft badge if we have both supplier and reason
            const returnReason = document.getElementById('returnReason').value;
            if (selectedSupplier && returnReason) {
                const draftBadge = document.getElementById('draftBadge');
                if (draftBadge) {
                    draftBadge.classList.remove('d-none');
                }
            }
        });

        // Back to supplier button
        document.getElementById('backToSupplierBtn').addEventListener('click', function() {
            showStep(1);
            
            // Save form state to session
            saveFormState();
        });

        // Product search
        document.getElementById('productSearch').addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length >= 1) { // Reduced from 2 to 1 character for better search
                searchProducts(query);
            } else {
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>Type at least 1 character to search for products.</p>
                    </div>
                `;
            }
            
            // Save form state to session (including search query)
            saveFormState();
        });

        document.getElementById('searchBtn').addEventListener('click', function() {
            const query = document.getElementById('productSearch').value.trim();
            if (query.length >= 1) { // Reduced from 2 to 1 character for better search
                searchProducts(query);
            }
            
            // Save form state to session
            saveFormState();
        });


        // Search products function
        function searchProducts(query) {
            if (!selectedSupplier) {
                alert('Please select a supplier first.');
                return;
            }

            if (!query || query.trim().length === 0) {
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>Please enter a search term.</p>
                    </div>
                `;
                return;
            }

            // Show loading state
            document.getElementById('productList').innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-hourglass-split fs-1 mb-3"></i>
                    <p>Searching for products...</p>
                </div>
            `;

            fetch(`?action=search_products&q=${encodeURIComponent(query)}&supplier_id=${selectedSupplier}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        displayProducts(data.products);
                    } else {
                        document.getElementById('productList').innerHTML = `
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('productList').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>Search failed: ${error.message}. Please try again.
                        </div>
                    `;
                });
        }

        // Display products
        function displayProducts(products) {
            if (products.length === 0) {
                document.getElementById('productList').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-search fs-1 mb-3"></i>
                        <p>No products found matching your search.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            products.forEach(product => {
                const existingItem = returnItems.find(item => item.product_id === product.id);
                const maxReturnQty = product.max_return_qty || 0;

                html += `
                    <div class="product-item">
                        ${product.image_url ? `<img src="${product.image_url}" alt="${product.name}" class="rounded" style="width: 50px; height: 50px; object-fit: cover; margin-right: 1rem;">` : '<div style="width: 50px; height: 50px; background: #f8f9fa; border-radius: 8px; margin-right: 1rem; display: flex; align-items: center; justify-content: center;"><i class="bi bi-image text-muted"></i></div>'}
                        <div class="product-info">
                            <div class="product-name">${product.name}</div>
                            <div class="product-details">
                                SKU: ${product.sku || 'N/A'} |
                                ${product.barcode ? `Barcode: ${product.barcode} |` : ''}
                                Category: ${product.category_name || 'N/A'} |
                                Brand: ${product.brand_name || 'N/A'} |
                                Current Stock: ${product.current_stock}
                                ${maxReturnQty > 0 ? ` | Max Return: ${maxReturnQty}` : ''}
                            </div>
                        </div>
                        <div class="ms-auto">
                            ${existingItem ?
                                `<button type="button" class="btn btn-success btn-sm" disabled>
                                    <i class="bi bi-check-circle me-1"></i>Added
                                </button>` :
                                `<button type="button" class="btn btn-primary btn-sm" onclick="addToReturn(${product.id}, '${product.name.replace(/'/g, "\\'")}', '${(product.sku || '').replace(/'/g, "\\'")}', '${(product.barcode || '').replace(/'/g, "\\'")}', ${product.order_cost_price || product.cost_price}, ${maxReturnQty})">
                                    <i class="bi bi-plus-circle me-1"></i>Add to Return
                                </button>`
                            }
                        </div>
                    </div>
                `;
            });

            document.getElementById('productList').innerHTML = html;
        }

        // Add product to return
        function addToReturn(productId, productName, sku, barcode, costPrice, maxQty) {
            // If maxQty is 0, allow any quantity (no limit)
            const maxLimit = maxQty > 0 ? maxQty : 'unlimited';
            
            // Show custom quantity modal instead of prompt
            showQuantityModal(productId, productName, sku, barcode, costPrice, maxQty);
        }

        // Show custom quantity selection modal
        function showQuantityModal(productId, productName, sku, barcode, costPrice, maxQty) {
            // Create modal HTML
            const modalHtml = `
                <div class="modal fade" id="quantityModal" tabindex="-1" aria-labelledby="quantityModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="quantityModalLabel">
                                    <i class="bi bi-cart-plus me-2"></i>Add to Return
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-12">
                                        <h6 class="fw-bold text-primary mb-3">${productName}</h6>
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted">SKU:</small><br>
                                                <span class="fw-semibold">${sku || 'N/A'}</span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Barcode:</small><br>
                                                <span class="fw-semibold">${barcode || 'N/A'}</span>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted">Unit Price:</small><br>
                                                <span class="fw-semibold text-success">${formatCurrency(costPrice)}</span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Max Return:</small><br>
                                                <span class="fw-semibold ${maxQty > 0 ? 'text-warning' : 'text-info'}">${maxQty > 0 ? maxQty : 'Unlimited'}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="quantity-selection">
                                    <label for="quantityInput" class="form-label fw-semibold">Quantity to Return</label>
                                    <div class="quantity-controls-large">
                                        <button type="button" class="btn btn-outline-secondary quantity-btn-large" id="decreaseQty">
                                            <i class="bi bi-dash-lg"></i>
                                        </button>
                                        <input type="number" class="form-control quantity-input-large" id="quantityInput" 
                                               value="1" min="1" ${maxQty > 0 ? `max="${maxQty}"` : ''} 
                                               placeholder="Enter quantity">
                                        <button type="button" class="btn btn-outline-secondary quantity-btn-large" id="increaseQty">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        ${maxQty > 0 ? `Maximum ${maxQty} units can be returned` : 'No quantity limit'}
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-x-circle me-1"></i>Cancel
                                </button>
                                <button type="button" class="btn btn-primary" id="confirmAddToReturn">
                                    <i class="bi bi-check-circle me-1"></i>Add to Return
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Remove existing modal if any
            const existingModal = document.getElementById('quantityModal');
            if (existingModal) {
                // Hide any existing modal first
                const existingModalInstance = bootstrap.Modal.getInstance(existingModal);
                if (existingModalInstance) {
                    existingModalInstance.hide();
                }
                existingModal.remove();
            }

            // Add modal to body
            document.body.insertAdjacentHTML('beforeend', modalHtml);

            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('quantityModal'));
            modal.show();

            // Store product data for later use
            window.currentProduct = {
                productId, productName, sku, barcode, costPrice, maxQty
            };

            // Add event listeners
            setupQuantityModalEvents();
        }

        // Setup quantity modal event listeners
        function setupQuantityModalEvents() {
            // Wait for modal to be fully rendered
            setTimeout(() => {
                const quantityInput = document.getElementById('quantityInput');
                const decreaseBtn = document.getElementById('decreaseQty');
                const increaseBtn = document.getElementById('increaseQty');
                const confirmBtn = document.getElementById('confirmAddToReturn');

                if (!quantityInput || !decreaseBtn || !increaseBtn || !confirmBtn) {
                    return;
                }

                // Update button states function
                function updateQuantityButtons() {
                    const currentQty = parseInt(quantityInput.value) || 1;
                    const maxQty = window.currentProduct.maxQty;
                    
                    decreaseBtn.disabled = currentQty <= 1;
                    increaseBtn.disabled = maxQty > 0 && currentQty >= maxQty;
                }

                // Decrease quantity
                decreaseBtn.addEventListener('click', function() {
                    let currentQty = parseInt(quantityInput.value) || 1;
                    if (currentQty > 1) {
                        quantityInput.value = currentQty - 1;
                        updateQuantityButtons();
                    }
                });

                // Increase quantity
                increaseBtn.addEventListener('click', function() {
                    let currentQty = parseInt(quantityInput.value) || 1;
                    const maxQty = window.currentProduct.maxQty;
                    if (maxQty === 0 || currentQty < maxQty) {
                        quantityInput.value = currentQty + 1;
                        updateQuantityButtons();
                    }
                });

                // Input change
                quantityInput.addEventListener('input', function() {
                    updateQuantityButtons();
                });

                // Confirm add to return
                confirmBtn.addEventListener('click', function() {
                    const qty = parseInt(quantityInput.value);
                    const maxQty = window.currentProduct.maxQty;

                    if (isNaN(qty) || qty <= 0) {
                        showToast('Please enter a valid quantity greater than 0.', 'warning');
                        return;
                    }

                    if (maxQty > 0 && qty > maxQty) {
                        showToast(`You can return maximum ${maxQty} units of this product.`, 'warning');
                        return;
                    }

                    // Add to return items
                    const newItem = {
                        product_id: window.currentProduct.productId,
                        product_name: window.currentProduct.productName,
                        sku: window.currentProduct.sku,
                        barcode: window.currentProduct.barcode,
                        quantity: qty,
                        cost_price: window.currentProduct.costPrice,
                        return_reason: '',
                        notes: ''
                    };

                    returnItems.push(newItem);
                    
                    // Update return cart first
                    updateReturnCart();
                    
                    // Save form state to session
                    saveFormState();
                    document.getElementById('returnCart').style.display = 'block';
                    
                    // Scroll to return cart to make it visible
                    setTimeout(() => {
                        const returnCart = document.getElementById('returnCart');
                        if (returnCart) {
                            returnCart.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center' 
                            });
                            
                            // Add a highlight effect to draw attention
                            returnCart.style.border = '3px solid #10b981';
                            returnCart.style.boxShadow = '0 0 20px rgba(16, 185, 129, 0.3)';
                            
                            // Remove highlight after 2 seconds
                            setTimeout(() => {
                                returnCart.style.border = '2px solid #10b981';
                                returnCart.style.boxShadow = '0 8px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)';
                            }, 2000);
                        }
                    }, 300);
                    
                    // Close modal
                    const modalElement = document.getElementById('quantityModal');
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                    } else {
                        // Fallback: hide modal manually
                        modalElement.classList.remove('show');
                        modalElement.style.display = 'none';
                        document.body.classList.remove('modal-open');
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                    }
                    
                    // Show success message
                    showToast('Product added to return successfully!', 'success');
                    
                    // Show "ready to add more products" status
                    const searchStatus = document.getElementById('searchStatus');
                    const searchInput = document.getElementById('productSearch');
                    if (searchStatus) {
                        searchStatus.style.display = 'block';
                        setTimeout(() => {
                            searchStatus.style.display = 'none';
                        }, 3000);
                    }
                    
                    // Clear search input and add pulse animation to indicate it's ready
                    if (searchInput) {
                        searchInput.value = '';
                        searchInput.classList.add('search-ready');
                        setTimeout(() => {
                            searchInput.classList.remove('search-ready');
                        }, 4000);
                    }
                    
                    // Clear product list
                    document.getElementById('productList').innerHTML = `
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-search fs-1 mb-3"></i>
                            <p>Search for more products to add to your return.</p>
                        </div>
                    `;
                });

                // Initial button state
                updateQuantityButtons();
            }, 100); // Small delay to ensure modal is rendered
        }

        // Update return cart
        function updateReturnCart() {
            
            // Format currency display
            const currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>';
            const currencyPosition = '<?php echo $settings['currency_position'] ?? 'before'; ?>';
            
            if (returnItems.length === 0) {
                document.getElementById('returnItemsList').innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-cart-x fs-1 mb-3"></i>
                            <p class="mb-0">No items added yet</p>
                        </td>
                    </tr>
                `;
                document.getElementById('returnCart').style.display = 'none';
                document.getElementById('nextToReviewBtn').style.display = 'none';
                return;
            }

            let html = '';
            let totalItems = 0;
            let totalValue = 0;

            returnItems.forEach((item, index) => {
                totalItems += item.quantity;
                totalValue += (item.quantity * item.cost_price);

                const unitPrice = item.cost_price.toFixed(2);
                const totalPrice = (item.quantity * item.cost_price).toFixed(2);

                const unitPriceDisplay = currencyPosition === 'before' ? `${currencySymbol}${unitPrice}` : `${unitPrice}${currencySymbol}`;
                const totalPriceDisplay = currencyPosition === 'before' ? `${currencySymbol}${totalPrice}` : `${totalPrice}${currencySymbol}`;

                html += `
                    <tr>
                        <td class="align-middle">${index + 1}</td>
                        <td class="align-middle">
                            <div class="fw-semibold">${item.product_name}</div>
                        </td>
                        <td class="align-middle">
                            <span class="badge bg-secondary">${item.sku || 'N/A'}</span>
                        </td>
                        <td class="align-middle">${unitPriceDisplay}</td>
                        <td class="align-middle">
                            <span class="badge bg-info">${formatReturnQty(item.quantity)}</span>
                        </td>
                        <td class="align-middle">
                            <span class="fw-bold text-success">${totalPriceDisplay}</span>
                        </td>
                        <td class="align-middle">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="editReturnQuantity(${index})" title="Edit quantity">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFromReturn(${index})" title="Remove from return">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            document.getElementById('returnItemsList').innerHTML = html;

            // Update cart item count
            document.getElementById('cartItemCount').textContent = `${totalItems} item${totalItems !== 1 ? 's' : ''}`;

            // Format total value with currency
            const totalValueDisplay = currencyPosition === 'before' ?
                `${currencySymbol}${totalValue.toFixed(2)}` :
                `${totalValue.toFixed(2)}${currencySymbol}`;

            document.getElementById('cartTotalValue').textContent = totalValueDisplay;

            document.getElementById('proceedToReviewBtn').innerHTML = `
                <i class="bi bi-check-circle me-2"></i>Review Return (${totalItems} items)
            `;
            
            // Show the return cart and next step button when there are items
            document.getElementById('returnCart').style.display = 'block';
            document.getElementById('nextToReviewBtn').style.display = 'block';
            console.log('Return cart shown with', returnItems.length, 'items');
        }

        // Edit return quantity - opens quantity input card
        function editReturnQuantity(index) {
            if (!returnItems[index]) {
                showToast('Item not found.', 'error');
                return;
            }

            const item = returnItems[index];
            showEditQuantityModal(item, index);
        }

        // Show edit quantity modal
        function showEditQuantityModal(item, index) {
            // Remove existing modal if any
            const existingModal = document.getElementById('editQuantityModal');
            if (existingModal) {
                existingModal.remove();
            }

            // Format currency display
            const currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>';
            const currencyPosition = '<?php echo $settings['currency_position'] ?? 'before'; ?>';
            const unitPrice = item.cost_price.toFixed(2);
            const unitPriceDisplay = currencyPosition === 'before' ? `${currencySymbol}${unitPrice}` : `${unitPrice}${currencySymbol}`;

            const modalHtml = `
                <div class="modal fade" id="editQuantityModal" tabindex="-1" aria-labelledby="editQuantityModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="editQuantityModalLabel">
                                    <i class="bi bi-pencil me-2"></i>Edit Quantity
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <h6 class="text-primary">${item.product_name}</h6>
                                    <div class="row">
                                        <div class="col-6">
                                            <small class="text-muted">SKU: ${item.sku || 'N/A'}</small>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">Unit Price: ${unitPriceDisplay}</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="editQuantity" class="form-label">New Quantity</label>
                                    <div class="quantity-controls-large">
                                        <button type="button" class="quantity-btn-large" id="editQuantityMinus" title="Decrease quantity">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <input type="number" class="quantity-input-large" id="editQuantity" 
                                               value="${item.quantity}" min="1" max="999">
                                        <button type="button" class="quantity-btn-large" id="editQuantityPlus" title="Increase quantity">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Use +/- buttons or type directly to set quantity
                                        <br><small class="text-muted">
                                            <i class="bi bi-dash-circle me-1"></i>Minus button disabled when quantity is 1
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-x-circle me-2"></i>Cancel
                                </button>
                                <button type="button" class="btn btn-primary" id="confirmEditBtn">
                                    <i class="bi bi-check-circle me-2"></i>Update Quantity
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            const modalElement = document.getElementById('editQuantityModal');
            const modal = new bootstrap.Modal(modalElement);
            modal.show();

            // Store the item index for the confirm button
            window.editingItemIndex = index;

            // Setup event listeners
            setupEditQuantityModalEvents();
        }

        // Setup edit quantity modal events
        function setupEditQuantityModalEvents() {
            const quantityInput = document.getElementById('editQuantity');
            const minusBtn = document.getElementById('editQuantityMinus');
            const plusBtn = document.getElementById('editQuantityPlus');
            const confirmBtn = document.getElementById('confirmEditBtn');

            if (!quantityInput || !minusBtn || !plusBtn || !confirmBtn) {
                console.error('Edit quantity modal elements not found');
                return;
            }

            // Quantity controls
            minusBtn.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value) || 1;
                if (currentValue > 1) {
                    quantityInput.value = currentValue - 1;
                    updateEditQuantityButtons();
                }
            });

            plusBtn.addEventListener('click', function() {
                const currentValue = parseInt(quantityInput.value) || 1;
                quantityInput.value = currentValue + 1;
                updateEditQuantityButtons();
            });

            quantityInput.addEventListener('input', function() {
                updateEditQuantityButtons();
            });

            // Confirm button
            confirmBtn.addEventListener('click', function() {
                const newQty = parseInt(quantityInput.value);
                const index = window.editingItemIndex;

                if (isNaN(newQty) || newQty <= 0) {
                    showToast('Please enter a valid quantity greater than 0.', 'warning');
                    return;
                }

                if (returnItems[index]) {
                    returnItems[index].quantity = newQty;
                    updateReturnCart();
                    
                    // Save form state to session
                    saveFormState();
                    
                    showToast('Quantity updated successfully!', 'success');
                }

                // Close modal
                const modalElement = document.getElementById('editQuantityModal');
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                } else {
                    modalElement.classList.remove('show');
                    modalElement.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            });

            // Initial button state
            updateEditQuantityButtons();
        }

        // Update edit quantity buttons state
        function updateEditQuantityButtons() {
            const quantityInput = document.getElementById('editQuantity');
            const minusBtn = document.getElementById('editQuantityMinus');
            const plusBtn = document.getElementById('editQuantityPlus');

            if (!quantityInput || !minusBtn || !plusBtn) return;

            const currentValue = parseInt(quantityInput.value) || 1;
            
            // Update minus button state
            if (currentValue <= 1) {
                minusBtn.disabled = true;
                minusBtn.classList.add('disabled');
                minusBtn.title = 'Minimum quantity is 1';
            } else {
                minusBtn.disabled = false;
                minusBtn.classList.remove('disabled');
                minusBtn.title = 'Decrease quantity';
            }
            
            // Plus button is always enabled
            plusBtn.disabled = false;
            plusBtn.title = 'Increase quantity';
        }

        // Update return quantity (legacy function for compatibility)
        function updateReturnQuantity(index, newQty) {
            newQty = parseInt(newQty);
            if (isNaN(newQty) || newQty <= 0) {
                // Reset to 1 if invalid input
                newQty = 1;
            }

            returnItems[index].quantity = newQty;
            updateReturnCart();
            
            // Save form state to session
            saveFormState();
            
            // Show feedback
            showToast(`Quantity updated to ${newQty}`, 'info');
        }

        // Remove from return
        function removeFromReturn(index) {
            const itemName = returnItems[index].product_name;
            returnItems.splice(index, 1);
            updateReturnCart();
            
            // Save form state to session
            saveFormState();
            
            // Show feedback
            showToast(`${itemName} removed from return`, 'warning');
        }

        // Format currency function
        function formatCurrency(amount) {
            const currencySymbol = '<?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>';
            const currencyPosition = '<?php echo $settings['currency_position'] ?? 'before'; ?>';
            const formattedAmount = parseFloat(amount).toFixed(2);
            
            return currencyPosition === 'before' ? 
                `${currencySymbol}${formattedAmount}` : 
                `${formattedAmount}${currencySymbol}`;
        }

        // Toast notification function
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            toast.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Add to body
            document.body.appendChild(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 3000);
        }

        // Proceed to review
        document.getElementById('proceedToReviewBtn').addEventListener('click', function() {
            if (returnItems.length === 0) {
                showToast('Please add at least one item to return.', 'warning');
                return;
            }
            
            updateReview();
            showStep(3);
        });

        // Back to products
        document.getElementById('backToProductsBtn').addEventListener('click', function() {
            showStep(2);
        });

        // Save as draft button
        document.getElementById('saveAsDraftBtn').addEventListener('click', function() {
            const supplierId = document.getElementById('supplierSelect').value;
            const returnReason = document.getElementById('returnReason').value;
            const returnNotes = document.getElementById('returnNotes').value;
            
            if (!supplierId || !returnReason) {
                showToast('Please select a supplier and return reason before saving as draft.', 'warning');
                return;
            }
            
            saveDraftAndReset(supplierId, returnReason, returnNotes);
        });

        // Create new button
        document.getElementById('createNewBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to create a new return? This will clear the current form.')) {
                resetFormForNewReturn();
                showToast('Form cleared. Select a supplier and reason to start a new return. Draft will be saved automatically.', 'info');
            }
        });

        // Next to review button
        document.getElementById('nextToReviewBtn').addEventListener('click', function() {
            if (returnItems.length === 0) {
                alert('Please add at least one item to return.');
                return;
            }
            updateReview();
            showStep(3);
        });

        // Update review
        function updateReview() {
            // Update supplier info
            const supplierSelect = document.getElementById('supplierSelect');
            const supplierName = supplierSelect.options[supplierSelect.selectedIndex].text;
            document.getElementById('reviewSupplierName').textContent = supplierName;

            // Update return reason
            const reasonSelect = document.getElementById('returnReason');
            const reasonText = reasonSelect.options[reasonSelect.selectedIndex].text;
            document.getElementById('reviewReturnReason').textContent = reasonText;

            // Update notes
            const notes = document.getElementById('returnNotes').value;
            document.getElementById('reviewReturnNotes').textContent = notes || 'None';

            // Update totals
            let totalItems = 0;
            let totalValue = 0;
            returnItems.forEach(item => {
                totalItems += item.quantity;
                totalValue += (item.quantity * item.cost_price);
            });

            document.getElementById('reviewTotalItems').textContent = totalItems;

            // Format total value with currency
            const reviewTotalValueDisplay = currencyPosition === 'before' ?
                `${currencySymbol}${totalValue.toFixed(2)}` :
                `${totalValue.toFixed(2)}${currencySymbol}`;
            document.getElementById('reviewTotalValue').textContent = reviewTotalValueDisplay;

            // Generate return number preview
            const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
            const returnNumber = `RTN-${today}-${String(Math.floor(Math.random() * 1000000)).padStart(6, '0')}`;
            document.getElementById('reviewReturnNumber').textContent = returnNumber;

            // Update items list
            let itemsHtml = '';
            returnItems.forEach(item => {
                const itemUnitPriceDisplay = currencyPosition === 'before' ?
                    `${currencySymbol}${item.cost_price.toFixed(2)}` :
                    `${item.cost_price.toFixed(2)}${currencySymbol}`;
                const itemTotalPriceDisplay = currencyPosition === 'before' ?
                    `${currencySymbol}${(item.quantity * item.cost_price).toFixed(2)}` :
                    `${(item.quantity * item.cost_price).toFixed(2)}${currencySymbol}`;

                itemsHtml += `
                    <div class="card mb-2">
                        <div class="card-body py-2">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <strong>${item.product_name}</strong>
                                    <br><small class="text-muted">SKU: ${item.sku || 'N/A'} ${item.barcode ? `| Barcode: ${item.barcode}` : ''}</small>
                                </div>
                                <div class="col-md-2 text-center">
                                    <span class="badge bg-primary">${formatReturnQty(item.quantity)}</span>
                                </div>
                                <div class="col-md-2 text-end">
                                    ${itemUnitPriceDisplay}
                                </div>
                                <div class="col-md-2 text-end">
                                    <strong>${itemTotalPriceDisplay}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            document.getElementById('reviewItemsList').innerHTML = itemsHtml;

            // Update form data
            document.getElementById('returnItemsInput').value = JSON.stringify(returnItems);
            document.getElementById('submitReturnBtn').disabled = false;
        }

        // Form submission
        document.getElementById('returnForm').addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission
            
            // Validate form fields
            const supplierId = document.getElementById('supplierSelect').value;
            const returnReason = document.getElementById('returnReason').value;
            
            if (!supplierId) {
                showToast('Please select a supplier.', 'warning');
                return;
            }
            
            if (!returnReason) {
                showToast('Please select a return reason.', 'warning');
                return;
            }
            
            if (returnItems.length === 0) {
                showToast('Please add at least one item to return.', 'warning');
                return;
            }
            
            // Update button state
            const submitBtn = document.getElementById('submitReturnBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creating Return...';
            
            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'create_return');
            formData.append('supplier_id', supplierId);
            formData.append('return_reason', returnReason);
            formData.append('return_notes', document.getElementById('returnNotes').value);
            formData.append('return_items', JSON.stringify(returnItems));
            
            // Submit via AJAX
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                if (response.redirected) {
                    // If server redirects, show success message and reset form
                    showToast('Return created successfully! Form reset for new return.', 'success');
                    resetFormForNewReturn();
                } else {
                    return response.json();
                }
            })
            .then(data => {
                if (data) {
                    if (data.success) {
                        // Success - show message with return number and reset form
                        const successMessage = data.return_number ? 
                            `${data.message} Return #${data.return_number}. Form reset for new return.` : 
                            `${data.message} Form reset for new return.`;
                        showToast(successMessage, 'success');
                        resetFormForNewReturn();
                    } else {
                        // Error - show error message
                        showToast(data.message || 'Error creating return. Please check your input.', 'error');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error creating return: ' + error.message, 'error');
            })
            .finally(() => {
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Create Return';
            });
        });


        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
