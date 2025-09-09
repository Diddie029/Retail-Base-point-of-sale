<?php
/**
 * Common utility functions for the POS system
 */

/**
 * Check if user has a specific permission
 * 
 * @param string $permission The permission to check
 * @param array $userPermissions Array of user permissions
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($permission, $userPermissions) {
    return in_array($permission, $userPermissions);
}

/**
 * Generate a unique 4-digit user ID (auto-generation only)
 * 
 * @param PDO $conn Database connection
 * @return string Unique 4-digit user ID
 */
function generateUniqueUserID($conn) {
    // Forbidden patterns (sequential, reverse sequential, common patterns)
    $forbidden = ['1234', '4321', '1001', '2002', '3003', '4004', '5005', '6006', '7007', '8008', '9009'];
    
    $maxAttempts = 1000; // Prevent infinite loops
    $attempts = 0;
    
    do {
        // Generate random 4-digit number (1000-9999)
        $user_id = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        $attempts++;
        
        // Check if it's not in forbidden list
        if (in_array($user_id, $forbidden)) {
            continue;
        }
        
        // Check if it's not already used
        $stmt = $conn->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        if ($stmt->rowCount() == 0) {
            return $user_id;
        }
        
    } while ($attempts < $maxAttempts);
    
    // If we can't find a unique ID after max attempts, throw an error
    throw new Exception("Unable to generate unique user ID after $maxAttempts attempts. All possible IDs may be in use.");
}

/**
 * Generate user IDs for existing users who don't have one
 * 
 * @param PDO $conn Database connection
 * @return array Results of the generation process
 */
function generateUserIDsForExistingUsers($conn) {
    $results = [
        'success' => true,
        'generated' => 0,
        'errors' => []
    ];
    
    try {
        // Get users without user_id
        $stmt = $conn->query("SELECT id, username, first_name, last_name FROM users WHERE user_id IS NULL OR user_id = ''");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($users as $user) {
            try {
                $user_id = generateUniqueUserID($conn);
                
                $update_stmt = $conn->prepare("UPDATE users SET user_id = ? WHERE id = ?");
                $update_stmt->execute([$user_id, $user['id']]);
                
                $results['generated']++;
            } catch (Exception $e) {
                $results['errors'][] = "Failed to generate ID for user {$user['username']}: " . $e->getMessage();
            }
        }
        
        if (!empty($results['errors'])) {
            $results['success'] = false;
        }
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['errors'][] = "Database error: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Get Employee ID settings
 * 
 * @param PDO $conn Database connection
 * @return array Employee ID settings
 */
function getEmployeeIdSettings($conn) {
    $settings = [];
    $stmt = $conn->query("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key LIKE 'employee_id_%'
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = str_replace('employee_id_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }
    
    // Convert string values to appropriate types
    $settings['auto_generate'] = (bool)($settings['auto_generate'] ?? false);
    $settings['include_year'] = (bool)($settings['include_year'] ?? false);
    $settings['include_month'] = (bool)($settings['include_month'] ?? false);
    $settings['reset_counter_yearly'] = (bool)($settings['reset_counter_yearly'] ?? false);
    $settings['number_length'] = (int)($settings['number_length'] ?? 4);
    $settings['start_number'] = (int)($settings['start_number'] ?? 1);
    $settings['current_counter'] = (int)($settings['current_counter'] ?? 0);
    
    return $settings;
}

/**
 * Generate Employee ID based on settings
 * 
 * @param PDO $conn Database connection
 * @return string Generated Employee ID
 */
function generateEmployeeId($conn) {
    $settings = getEmployeeIdSettings($conn);
    
    if (!$settings['auto_generate']) {
        return ''; // Auto-generation is disabled
    }
    
    // Check if we need to reset counter yearly
    if ($settings['reset_counter_yearly']) {
        $currentYear = date('Y');
        $lastResetYear = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'employee_id_last_reset_year'")->fetchColumn();
        
        if ($lastResetYear != $currentYear) {
            // Reset counter for new year
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES ('employee_id_current_counter', ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$settings['start_number'] - 1]);
            
            // Update last reset year
            $stmt = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value) 
                VALUES ('employee_id_last_reset_year', ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $stmt->execute([$currentYear]);
            
            $settings['current_counter'] = $settings['start_number'] - 1;
        }
    }
    
    // Increment counter
    $newCounter = $settings['current_counter'] + 1;
    
    // Update counter in database
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES ('employee_id_current_counter', ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$newCounter]);
    
    // Build Employee ID
    $employeeId = '';
    
    // Add prefix
    if (!empty($settings['prefix'])) {
        $employeeId .= $settings['prefix'];
        if (!empty($settings['separator'])) {
            $employeeId .= $settings['separator'];
        }
    }
    
    // Add year if enabled
    if ($settings['include_year']) {
        $employeeId .= date('Y');
        if (!empty($settings['separator'])) {
            $employeeId .= $settings['separator'];
        }
    }
    
    // Add month if enabled
    if ($settings['include_month']) {
        $employeeId .= date('m');
        if (!empty($settings['separator'])) {
            $employeeId .= $settings['separator'];
        }
    }
    
    // Add number
    $employeeId .= str_pad($newCounter, $settings['number_length'], '0', STR_PAD_LEFT);
    
    // Add suffix
    if (!empty($settings['suffix'])) {
        if (!empty($settings['separator'])) {
            $employeeId .= $settings['separator'];
        }
        $employeeId .= $settings['suffix'];
    }
    
    return $employeeId;
}

/**
 * Sanitize input data
 * 
 * @param string $data The data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Format currency amount
 * 
 * @param float $amount The amount to format
 * @param string $currency The currency symbol
 * @param int $decimals Number of decimal places
 * @return string Formatted currency string
 */
function formatCurrency($amount, $settings = null) {
    // If no settings provided, get from database
    if ($settings === null) {
        global $conn;
        $settings = getSystemSettings($conn);
    }

    $symbol = $settings['currency_symbol'] ?? 'KES';
    $position = $settings['currency_position'] ?? 'before';
    $decimals = intval($settings['currency_decimal_places'] ?? 2);

    $formatted_amount = number_format($amount, $decimals);

    if ($position === 'before') {
        return $symbol . ' ' . $formatted_amount;
    } else {
        return $formatted_amount . ' ' . $symbol;
    }
}

/**
 * Get system setting value
 * 
 * @param PDO $conn Database connection
 * @param string $key Setting key
 * @param mixed $default Default value if setting not found
 * @return mixed Setting value or default
 */
function getSetting($conn, $key, $default = null) {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
        $stmt->bindParam(':key', $key);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Log activity to the system
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param string $action Action performed
 * @param string $details Additional details
 * @return bool Success status
 */
function logActivity($conn, $user_id, $action, $details = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, details, created_at) 
            VALUES (:user_id, :action, :details, NOW())
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        return $stmt->execute();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Generate a random string
 * 
 * @param int $length Length of the string
 * @return string Random string
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Validate email address
 * 
 * @param string $email Email to validate
 * @return bool True if valid, false otherwise
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Check if a product is currently on sale
 *
 * @param array $product Product data array
 * @return bool True if product is on sale, false otherwise
 */
function isProductOnSale($product) {
    if (!isset($product['sale_price']) || empty($product['sale_price'])) {
        return false;
    }

    $now = new DateTime();
    $start_date = isset($product['sale_start_date']) && !empty($product['sale_start_date']) ?
                  new DateTime($product['sale_start_date']) : null;
    $end_date = isset($product['sale_end_date']) && !empty($product['sale_end_date']) ?
                new DateTime($product['sale_end_date']) : null;

    // Check if sale has started
    if ($start_date && $now < $start_date) {
        return false;
    }

    // Check if sale has ended
    if ($end_date && $now > $end_date) {
        return false;
    }

    return true;
}

/**
 * Get the current price of a product (considering sale price)
 *
 * @param array $product Product data array
 * @return float Current price (sale price if on sale, regular price otherwise)
 */
function getCurrentProductPrice($product) {
    if (isProductOnSale($product)) {
        return (float)$product['sale_price'];
    }

    return (float)$product['price'];
}

/**
 * Get sale information for a product
 *
 * @param array $product Product data array
 * @return array|null Sale information or null if not on sale
 */
function getProductSaleInfo($product) {
    if (!isProductOnSale($product)) {
        return null;
    }

    $sale_info = [
        'sale_price' => (float)$product['sale_price'],
        'original_price' => (float)$product['price'],
        'discount_percentage' => round((($product['price'] - $product['sale_price']) / $product['price']) * 100, 1),
        'savings' => $product['price'] - $product['sale_price']
    ];

    if (!empty($product['sale_start_date'])) {
        $sale_info['start_date'] = $product['sale_start_date'];
    }

    if (!empty($product['sale_end_date'])) {
        $sale_info['end_date'] = $product['sale_end_date'];
    }

    return $sale_info;
}

/**
 * Format sale date for display
 *
 * @param string $date Date string
 * @return string Formatted date
 */
function formatSaleDate($date) {
    if (empty($date)) {
        return '';
    }

    $date_obj = new DateTime($date);
    return $date_obj->format('M j, Y g:i A');
}

/**
 * Calculate tax amount for a product
 *
 * @param array $product Product data array
 * @param float $price Price to calculate tax on (current/sale price)
 * @return float Tax amount
 */
function calculateProductTax($product, $price = null) {
    if ($price === null) {
        $price = getCurrentProductPrice($product);
    }

    $tax_rate = isset($product['tax_rate']) && !empty($product['tax_rate']) ?
                (float)$product['tax_rate'] : 0;

    return round(($price * $tax_rate / 100), 2);
}

/**
 * Get product price with tax
 *
 * @param array $product Product data array
 * @param float $price Price to calculate with tax (current/sale price)
 * @return float Price including tax
 */
function getProductPriceWithTax($product, $price = null) {
    if ($price === null) {
        $price = getCurrentProductPrice($product);
    }

    return $price + calculateProductTax($product, $price);
}

/**
 * Check if user is logged in
 * 
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to a URL
 *
 * @param string $url URL to redirect to
 * @param bool $permanent Whether this is a permanent redirect
 */
function redirect($url, $permanent = false) {
    if ($permanent) {
        header("HTTP/1.1 301 Moved Permanently");
    }
    header("Location: $url");
    exit();
}

/**
 * Generate a unique SKU for a product
 *
 * @param PDO $conn Database connection
 * @param string $prefix Optional prefix for SKU
 * @param int $length Length of random part
 * @return string Generated SKU
 */
function generateSKU($conn, $prefix = '', $length = 8) {
    do {
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, $length));
        $sku = $prefix . $random;
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
        $stmt->bindParam(':sku', $sku);
        $stmt->execute();
        $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
    } while ($exists);

    return $sku;
}

/**
 * Generate a unique SKU using system settings
 *
 * @param PDO $conn Database connection
 * @param int $product_id Optional product ID for sequential numbering
 * @return string Generated SKU based on system settings
 */
function generateSystemSKU($conn, $product_id = null) {
    try {
        // Get SKU settings from database
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sku_prefix', 'sku_format', 'sku_length', 'sku_separator')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // Set defaults if not configured
        $prefix = $settings['sku_prefix'] ?? 'LIZ';
        $format = $settings['sku_format'] ?? 'SKU000001';
        $length = intval($settings['sku_length'] ?? 6);
        $separator = $settings['sku_separator'] ?? '';

        // Get next available number by parsing existing SKUs
        if ($product_id) {
            $next_number = $product_id;
        } else {
            // Get all SKUs with the current prefix and separator
            $stmt = $conn->prepare("SELECT sku FROM products WHERE sku LIKE CONCAT(:prefix, :separator, '%') AND sku REGEXP '^[A-Za-z0-9\-_]+$' ORDER BY sku DESC LIMIT 1");
            $stmt->bindParam(':prefix', $prefix);
            $stmt->bindParam(':separator', $separator);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && !empty($result['sku'])) {
                $sku = $result['sku'];
                // Remove prefix and separator to get the number part
                $number_part = substr($sku, strlen($prefix . $separator));

                // Handle cases where SKU might have a suffix (like -1, -2, etc.)
                if (strpos($number_part, '-') !== false) {
                    $parts = explode('-', $number_part);
                    $number_part = $parts[0]; // Get the base number
                }

                // Extract only the numeric part
                preg_match('/(\d+)$/', $number_part, $matches);
                if ($matches) {
                    $last_number = (int)$matches[1];
                    $next_number = $last_number + 1;
                } else {
                    $next_number = 1;
                }
            } else {
                // No existing SKUs, start from 1
                $next_number = 1;
            }
        }

        // Generate SKU based on format
        if (strpos($format, '000') !== false) {
            // Replace zeros with padded number
            $sku = $prefix . $separator . str_pad($next_number, $length, '0', STR_PAD_LEFT);
        } else {
            // Use format as template, replace # with padded number
            $sku = $prefix . $separator . str_replace('#', str_pad($next_number, $length, '0', STR_PAD_LEFT), $format);
        }

        // Ensure uniqueness
        $counter = 1;
        $original_sku = $sku;
        do {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE sku = :sku");
            $stmt->bindParam(':sku', $sku);
            $stmt->execute();
            $exists = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if ($exists) {
                $sku = $original_sku . '-' . $counter;
                $counter++;
            }
        } while ($exists && $counter < 1000);

        return $sku;
    } catch (PDOException $e) {
        // Fallback to simple SKU generation
        return generateSKU($conn, $prefix, $length);
    }
}

/**
 * Get current SKU settings from database
 *
 * @param PDO $conn Database connection
 * @return array Array of SKU settings
 */
function getSKUSettings($conn) {
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sku_prefix', 'sku_format', 'sku_length', 'sku_separator', 'auto_generate_sku')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        // Set defaults if not configured
        return [
            'sku_prefix' => $settings['sku_prefix'] ?? 'LIZ',
            'sku_format' => $settings['sku_format'] ?? 'SKU000001',
            'sku_length' => intval($settings['sku_length'] ?? 6),
            'sku_separator' => $settings['sku_separator'] ?? '',
            'auto_generate_sku' => isset($settings['auto_generate_sku']) ? $settings['auto_generate_sku'] == '1' : true
        ];
    } catch (PDOException $e) {
        // Return defaults if database error
        return [
            'sku_prefix' => 'LIZ',
            'sku_format' => 'SKU000001',
            'sku_length' => 6,
            'sku_separator' => '',
            'auto_generate_sku' => true
        ];
    }
}

/**
 * Sanitize product input with type-specific validation
 *
 * @param string $input Input value to sanitize
 * @param string $type Type of input validation (string, text, float, int, email, url)
 * @return string Sanitized input
 */
function sanitizeProductInput($input, $type = 'string') {
    $input = trim($input);

    switch ($type) {
        case 'text':
            $input = filter_var($input, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
            break;
        case 'float':
            $input = filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            break;
        case 'int':
            $input = filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            break;
        case 'email':
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            break;
        case 'url':
            $input = filter_var($input, FILTER_SANITIZE_URL);
            break;
        default:
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    return $input;
}

/**
 * Get complete product information including images, variants, and attributes
 *
 * @param PDO $conn Database connection
 * @param int $product_id Product ID
 * @return array|null Complete product data or null if not found
 */
function getCompleteProduct($conn, $product_id) {
    try {
        // Get main product data
        $stmt = $conn->prepare("
            SELECT p.*, c.name as category_name, b.name as brand_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE p.id = :id
        ");
        $stmt->bindParam(':id', $product_id);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return null;
        }

        // Get product images
        $stmt = $conn->prepare("
            SELECT * FROM product_images
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, is_primary DESC
        ");
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        $product['images'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get product variants
        $stmt = $conn->prepare("
            SELECT * FROM product_variants
            WHERE product_id = :product_id AND is_active = 1
            ORDER BY variant_name ASC, variant_value ASC
        ");
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        $product['variants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get product attributes
        $stmt = $conn->prepare("
            SELECT * FROM product_attributes
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, attribute_name ASC
        ");
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        $product['attributes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $product;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Check if product is low on stock
 *
 * @param array $product Product data array
 * @return bool True if low stock, false otherwise
 */
function isLowStock($product) {
    if (!isset($product['minimum_stock']) || $product['minimum_stock'] <= 0) {
        return false;
    }
    return $product['quantity'] <= $product['minimum_stock'];
}

/**
 * Check if product needs reorder
 *
 * @param array $product Product data array
 * @return bool True if needs reorder, false otherwise
 */
function needsReorder($product) {
    if (!isset($product['reorder_point']) || $product['reorder_point'] <= 0) {
        return false;
    }
    return $product['quantity'] <= $product['reorder_point'];
}

/**
 * Calculate profit margin for a product
 *
 * @param array $product Product data array
 * @return float|null Profit margin percentage or null if cost_price is 0
 */
function calculateProfitMargin($product) {
    if (!isset($product['cost_price']) || $product['cost_price'] <= 0) {
        return null;
    }
    $profit = $product['price'] - $product['cost_price'];
    return round(($profit / $product['cost_price']) * 100, 2);
}

/**
 * Get products by type
 *
 * @param PDO $conn Database connection
 * @param string $type Product type (physical, digital, service, subscription)
 * @param int $limit Maximum number of products to return
 * @return array Array of products
 */
function getProductsByType($conn, $type, $limit = 50) {
    try {
        $stmt = $conn->prepare("
            SELECT p.*, c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.product_type = :type AND p.status = 'active'
            ORDER BY p.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Search products with advanced filters
 *
 * @param PDO $conn Database connection
 * @param array $filters Search filters
 * @param int $limit Maximum number of products to return
 * @param int $offset Offset for pagination
 * @return array Array of products with total count
 */
function searchProducts($conn, $filters = [], $limit = 20, $offset = 0) {
    try {
        $where = ["p.status = 'active'"];
        $params = [];

        // Search term
        if (!empty($filters['search'])) {
            $where[] = "(p.name LIKE :search OR p.description LIKE :search OR p.sku LIKE :search OR p.tags LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Category filter
        if (!empty($filters['category_id'])) {
            $where[] = "p.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        // Product type filter
        if (!empty($filters['product_type'])) {
            $where[] = "p.product_type = :product_type";
            $params[':product_type'] = $filters['product_type'];
        }

        // Brand filter
        if (!empty($filters['brand'])) {
            $where[] = "p.brand_id = :brand_id";
            $params[':brand_id'] = $filters['brand'];
        }

        // Price range
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $where[] = "p.price >= :min_price";
            $params[':min_price'] = $filters['min_price'];
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $where[] = "p.price <= :max_price";
            $params[':max_price'] = $filters['max_price'];
        }

        // Stock status
        if (!empty($filters['stock_status'])) {
            switch ($filters['stock_status']) {
                case 'in_stock':
                    $where[] = "p.quantity > 0";
                    break;
                case 'out_of_stock':
                    $where[] = "p.quantity = 0";
                    break;
                case 'low_stock':
                    $where[] = "p.quantity > 0 AND p.quantity <= p.minimum_stock";
                    break;
            }
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM products p WHERE $whereClause";
        $stmt = $conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get products
        $sql = "
            SELECT p.*, c.name as category_name, b.name as brand_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE $whereClause
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    } catch (PDOException $e) {
        return [
            'products' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
}

/**
 * Get low stock products
 *
 * @param PDO $conn Database connection
 * @param int $limit Maximum number of products to return
 * @return array Array of low stock products
 */
function getLowStockProducts($conn, $limit = 50) {
    try {
        $stmt = $conn->prepare("
            SELECT p.*, c.name as category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.status = 'active'
            AND p.track_inventory = 1
            AND p.minimum_stock > 0
            AND p.quantity <= p.minimum_stock
            ORDER BY (p.minimum_stock - p.quantity) DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Update product stock after sale
 *
 * @param PDO $conn Database connection
 * @param int $product_id Product ID
 * @param int $quantity_change Quantity to subtract (positive number)
 * @return bool Success status
 */
function updateProductStock($conn, $product_id, $quantity_change) {
    try {
        $stmt = $conn->prepare("
            UPDATE products
            SET quantity = GREATEST(0, quantity - :quantity_change),
                updated_at = NOW()
            WHERE id = :product_id
        ");
        $stmt->bindParam(':product_id', $product_id);
        $stmt->bindParam(':quantity_change', $quantity_change, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get recent login attempts for monitoring
 *
 * @param PDO $conn Database connection
 * @param string|null $identifier Filter by specific identifier (username/email)
 * @param string|null $ip_address Filter by specific IP address
 * @param int $limit Maximum number of records to return
 * @return array Array of login attempts
 */
function getRecentLoginAttempts($conn, $identifier = null, $ip_address = null, $limit = 50) {
    try {
        $sql = "SELECT identifier, attempt_type, ip_address, success, created_at
                FROM login_attempts
                WHERE 1=1";
        $params = [];

        if ($identifier !== null) {
            $sql .= " AND identifier = :identifier";
            $params[':identifier'] = $identifier;
        }

        if ($ip_address !== null) {
            $sql .= " AND ip_address = :ip_address";
            $params[':ip_address'] = $ip_address;
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;

        $stmt = $conn->prepare($sql);

        // Bind parameters
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * SUPPLIER PERFORMANCE FUNCTIONS
 */

/**
 * Calculate supplier delivery performance metrics
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $start_date Start date for calculation (Y-m-d)
 * @param string $end_date End date for calculation (Y-m-d)
 * @return array Delivery performance metrics
 */
function calculateDeliveryPerformance($conn, $supplier_id, $start_date = null, $end_date = null) {
    try {
        $sql = "
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN io.received_date IS NOT NULL AND io.received_date <= io.expected_date THEN 1 ELSE 0 END) as on_time_deliveries,
                SUM(CASE WHEN io.received_date IS NOT NULL AND io.received_date > io.expected_date THEN 1 ELSE 0 END) as late_deliveries,
                AVG(CASE WHEN io.received_date IS NOT NULL THEN DATEDIFF(io.received_date, io.order_date) ELSE NULL END) as avg_delivery_days,
                AVG(CASE WHEN io.received_date IS NOT NULL AND io.expected_date IS NOT NULL THEN DATEDIFF(io.received_date, io.expected_date) ELSE NULL END) as avg_delay_days
            FROM inventory_orders io
            WHERE io.supplier_id = :supplier_id
            AND io.status IN ('received', 'completed')
        ";

        $params = [':supplier_id' => $supplier_id];

        if ($start_date) {
            $sql .= " AND io.order_date >= :start_date";
            $params[':start_date'] = $start_date;
        }

        if ($end_date) {
            $sql .= " AND io.order_date <= :end_date";
            $params[':end_date'] = $end_date;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_orders = (int)($result['total_orders'] ?? 0);
        $on_time = (int)($result['on_time_deliveries'] ?? 0);
        $late = (int)($result['late_deliveries'] ?? 0);

        return [
            'total_orders' => $total_orders,
            'on_time_deliveries' => $on_time,
            'late_deliveries' => $late,
            'on_time_percentage' => $total_orders > 0 ? round(($on_time / $total_orders) * 100, 2) : 0,
            'average_delivery_days' => round($result['avg_delivery_days'] ?? 0, 2),
            'average_delay_days' => round($result['avg_delay_days'] ?? 0, 2)
        ];
    } catch (PDOException $e) {
        return [
            'total_orders' => 0,
            'on_time_deliveries' => 0,
            'late_deliveries' => 0,
            'on_time_percentage' => 0,
            'average_delivery_days' => 0,
            'average_delay_days' => 0
        ];
    }
}

/**
 * Calculate supplier quality metrics
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $start_date Start date for calculation (Y-m-d)
 * @param string $end_date End date for calculation (Y-m-d)
 * @return array Quality metrics
 */
function calculateQualityMetrics($conn, $supplier_id, $start_date = null, $end_date = null) {
    try {
        // Get total orders and returns
        $sql = "
            SELECT
                COUNT(DISTINCT io.id) as total_orders,
                COUNT(DISTINCT r.id) as total_returns,
                COALESCE(SUM(r.total_amount), 0) as total_return_value,
                COALESCE(SUM(io.total_amount), 0) as total_order_value
            FROM inventory_orders io
            LEFT JOIN returns r ON io.supplier_id = r.supplier_id
            WHERE io.supplier_id = :supplier_id
            AND io.status IN ('received', 'completed')
        ";

        $params = [':supplier_id' => $supplier_id];

        if ($start_date) {
            $sql .= " AND io.order_date >= :start_date";
            $params[':start_date'] = $start_date;
        }

        if ($end_date) {
            $sql .= " AND io.order_date <= :end_date";
            $params[':end_date'] = $end_date;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_orders = (int)($result['total_orders'] ?? 0);
        $total_returns = (int)($result['total_returns'] ?? 0);
        $total_return_value = (float)($result['total_return_value'] ?? 0);
        $total_order_value = (float)($result['total_order_value'] ?? 0);

        // Calculate return rate and quality score
        $return_rate = $total_orders > 0 ? ($total_returns / $total_orders) * 100 : 0;
        $value_return_rate = $total_order_value > 0 ? ($total_return_value / $total_order_value) * 100 : 0;

        // Quality score: 100 - (return_rate * 10) - (value_return_rate * 5)
        // Higher score = better quality
        $quality_score = max(0, min(100, 100 - ($return_rate * 10) - ($value_return_rate * 5)));

        return [
            'total_orders' => $total_orders,
            'total_returns' => $total_returns,
            'return_rate' => round($return_rate, 2),
            'total_return_value' => $total_return_value,
            'total_order_value' => $total_order_value,
            'value_return_rate' => round($value_return_rate, 2),
            'quality_score' => round($quality_score, 2)
        ];
    } catch (PDOException $e) {
        return [
            'total_orders' => 0,
            'total_returns' => 0,
            'return_rate' => 0,
            'total_return_value' => 0,
            'total_order_value' => 0,
            'value_return_rate' => 0,
            'quality_score' => 100
        ];
    }
}

/**
 * Calculate supplier cost performance
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $start_date Start date for calculation (Y-m-d)
 * @param string $end_date End date for calculation (Y-m-d)
 * @return array Cost performance metrics
 */
function calculateCostPerformance($conn, $supplier_id, $start_date = null, $end_date = null) {
    try {
        $sql = "
            SELECT
                AVG(ioi.cost_price) as avg_cost_per_unit,
                MIN(ioi.cost_price) as min_cost_per_unit,
                MAX(ioi.cost_price) as max_cost_per_unit,
                SUM(ioi.total_amount) as total_order_value,
                COUNT(DISTINCT ioi.product_id) as unique_products,
                COUNT(ioi.id) as total_items_ordered
            FROM inventory_order_items ioi
            INNER JOIN inventory_orders io ON ioi.order_id = io.id
            WHERE io.supplier_id = :supplier_id
            AND io.status IN ('received', 'completed')
        ";

        $params = [':supplier_id' => $supplier_id];

        if ($start_date) {
            $sql .= " AND io.order_date >= :start_date";
            $params[':start_date'] = $start_date;
        }

        if ($end_date) {
            $sql .= " AND io.order_date <= :end_date";
            $params[':end_date'] = $end_date;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'average_cost_per_unit' => round($result['avg_cost_per_unit'] ?? 0, 2),
            'min_cost_per_unit' => round($result['min_cost_per_unit'] ?? 0, 2),
            'max_cost_per_unit' => round($result['max_cost_per_unit'] ?? 0, 2),
            'total_order_value' => round($result['total_order_value'] ?? 0, 2),
            'unique_products' => (int)($result['unique_products'] ?? 0),
            'total_items_ordered' => (int)($result['total_items_ordered'] ?? 0)
        ];
    } catch (PDOException $e) {
        return [
            'average_cost_per_unit' => 0,
            'min_cost_per_unit' => 0,
            'max_cost_per_unit' => 0,
            'total_order_value' => 0,
            'unique_products' => 0,
            'total_items_ordered' => 0
        ];
    }
}

/**
 * Get supplier performance summary
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $period Period for calculation ('30days', '90days', '1year', 'all')
 * @return array Comprehensive performance metrics
 */
function getSupplierPerformance($conn, $supplier_id, $period = '90days') {
    // Calculate date range based on period
    $end_date = date('Y-m-d');
    switch ($period) {
        case '30days':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            break;
        case '90days':
            $start_date = date('Y-m-d', strtotime('-90 days'));
            break;
        case '1year':
            $start_date = date('Y-m-d', strtotime('-1 year'));
            break;
        case 'all':
        default:
            $start_date = null;
            break;
    }

    $delivery = calculateDeliveryPerformance($conn, $supplier_id, $start_date, $end_date);
    $quality = calculateQualityMetrics($conn, $supplier_id, $start_date, $end_date);
    $cost = calculateCostPerformance($conn, $supplier_id, $start_date, $end_date);

    // Calculate overall performance score (weighted average)
    $delivery_weight = 0.4;
    $quality_weight = 0.4;
    $cost_weight = 0.2;

    $delivery_score = $delivery['on_time_percentage'];
    $quality_score = $quality['quality_score'];

    // Cost score: higher is better (inverse relationship with cost)
    $avg_cost = $cost['average_cost_per_unit'];
    $cost_score = $avg_cost > 0 ? max(0, 100 - ($avg_cost / 10)) : 100;

    $overall_score = round(
        ($delivery_score * $delivery_weight) +
        ($quality_score * $quality_weight) +
        ($cost_score * $cost_weight),
        2
    );

    return [
        'period' => $period,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'delivery_performance' => $delivery,
        'quality_metrics' => $quality,
        'cost_performance' => $cost,
        'overall_score' => $overall_score,
        'performance_rating' => getPerformanceRating($overall_score)
    ];
}

/**
 * Get performance rating based on score
 *
 * @param float $score Performance score (0-100)
 * @return string Performance rating
 */
function getPerformanceRating($score) {
    if ($score >= 90) return 'Excellent';
    if ($score >= 80) return 'Very Good';
    if ($score >= 70) return 'Good';
    if ($score >= 60) return 'Fair';
    if ($score >= 50) return 'Poor';
    return 'Critical';
}

/**
 * Get supplier cost comparison with market averages
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @return array Cost comparison data
 */
function getSupplierCostComparison($conn, $supplier_id) {
    try {
        // Get supplier's average costs by product category
        $sql = "
            SELECT
                c.name as category_name,
                AVG(ioi.cost_price) as supplier_avg_cost,
                COUNT(DISTINCT ioi.product_id) as products_count
            FROM inventory_order_items ioi
            INNER JOIN inventory_orders io ON ioi.order_id = io.id
            INNER JOIN products p ON ioi.product_id = p.id
            INNER JOIN categories c ON p.category_id = c.id
            WHERE io.supplier_id = :supplier_id
            AND io.status IN ('received', 'completed')
            GROUP BY c.id, c.name
            ORDER BY supplier_avg_cost DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':supplier_id' => $supplier_id]);
        $supplier_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get market average costs (from all suppliers)
        $market_sql = "
            SELECT
                c.name as category_name,
                AVG(ioi.cost_price) as market_avg_cost
            FROM inventory_order_items ioi
            INNER JOIN inventory_orders io ON ioi.order_id = io.id
            INNER JOIN products p ON ioi.product_id = p.id
            INNER JOIN categories c ON p.category_id = c.id
            WHERE io.status IN ('received', 'completed')
            GROUP BY c.id, c.name
        ";

        $stmt = $conn->prepare($market_sql);
        $stmt->execute();
        $market_costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Combine data
        $comparison = [];
        foreach ($supplier_costs as $supplier_cost) {
            $category_name = $supplier_cost['category_name'];
            $market_cost = 0;

            foreach ($market_costs as $mc) {
                if ($mc['category_name'] === $category_name) {
                    $market_cost = $mc['market_avg_cost'];
                    break;
                }
            }

            $supplier_avg = $supplier_cost['supplier_avg_cost'];
            $difference = $market_cost > 0 ? (($supplier_avg - $market_cost) / $market_cost) * 100 : 0;

            $comparison[] = [
                'category' => $category_name,
                'supplier_avg_cost' => round($supplier_avg, 2),
                'market_avg_cost' => round($market_cost, 2),
                'difference_percentage' => round($difference, 2),
                'products_count' => $supplier_cost['products_count'],
                'cost_status' => $difference > 10 ? 'high' : ($difference < -10 ? 'low' : 'average')
            ];
        }

        return $comparison;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * BILL OF MATERIALS (BOM) FUNCTIONS
 */

/**
 * Generate Product number based on system settings
 *
 * @param PDO $conn Database connection
 * @return string Generated Product number
 */
function generateProductNumber($conn) {
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('product_number_prefix', 'product_number_length', 'product_number_separator', 'product_number_format')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        $prefix = $settings['product_number_prefix'] ?? 'PRD';
        $length = intval($settings['product_number_length'] ?? 6);
        $separator = $settings['product_number_separator'] ?? '-';
        $format = $settings['product_number_format'] ?? 'prefix-number';
        $currentDate = date('Ymd');

        // Get the next sequential number based on format
        $nextNumber = 1;
        
        if ($format === 'prefix-date-number') {
            // Find the highest number for today's date
            $searchPattern = $prefix . $separator . $currentDate . $separator . '%';
            $stmt = $conn->prepare("SELECT product_number FROM products WHERE product_number LIKE :pattern ORDER BY product_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastProduct = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastProduct) {
                // Extract the number part and increment
                $lastNumber = substr($lastProduct['product_number'], strlen($prefix . $separator . $currentDate . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'prefix-number') {
            // Find the highest number for this prefix
            $searchPattern = $prefix . $separator . '%';
            $stmt = $conn->prepare("SELECT product_number FROM products WHERE product_number LIKE :pattern ORDER BY product_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastProduct = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastProduct) {
                // Extract the number part and increment
                $lastNumber = substr($lastProduct['product_number'], strlen($prefix . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'date-prefix-number') {
            // Find the highest number for today's date and prefix
            $searchPattern = $currentDate . $separator . $prefix . $separator . '%';
            $stmt = $conn->prepare("SELECT product_number FROM products WHERE product_number LIKE :pattern ORDER BY product_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastProduct = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastProduct) {
                // Extract the number part and increment
                $lastNumber = substr($lastProduct['product_number'], strlen($currentDate . $separator . $prefix . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'number-only') {
            // Find the highest number (no prefix/date)
            $stmt = $conn->prepare("SELECT product_number FROM products WHERE product_number REGEXP '^[0-9]+$' ORDER BY CAST(product_number AS UNSIGNED) DESC LIMIT 1");
            $stmt->execute();
            $lastProduct = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastProduct) {
                $nextNumber = intval($lastProduct['product_number']) + 1;
            }
        }

        // Format the number with leading zeros
        $number = str_pad($nextNumber, $length, '0', STR_PAD_LEFT);

        // Generate the final product number
        switch ($format) {
            case 'prefix-date-number':
                $product_number = $prefix . $separator . $currentDate . $separator . $number;
                break;
            case 'prefix-number':
                $product_number = $prefix . $separator . $number;
                break;
            case 'date-prefix-number':
                $product_number = $currentDate . $separator . $prefix . $separator . $number;
                break;
            case 'number-only':
                $product_number = $number;
                break;
            default:
                $product_number = $prefix . $separator . $number;
        }

        return $product_number;
    } catch (PDOException $e) {
        // Fallback to simple generation with timestamp
        return 'PRD-' . date('Ymd') . '-' . str_pad(1, 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Generate BOM number based on system settings
 *
 * @param PDO $conn Database connection
 * @return string Generated BOM number
 */
function generateBOMNumber($conn) {
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bom_number_prefix', 'bom_number_length', 'bom_number_separator', 'bom_number_format')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        $prefix = $settings['bom_number_prefix'] ?? 'BOM';
        $length = intval($settings['bom_number_length'] ?? 6);
        $separator = $settings['bom_number_separator'] ?? '-';
        $format = $settings['bom_number_format'] ?? 'prefix-date-number';
        $currentDate = date('Ymd');

        // Get the next sequential number based on format
        $nextNumber = 1;
        
        if ($format === 'prefix-date-number') {
            // Find the highest number for today's date
            $searchPattern = $prefix . $separator . $currentDate . $separator . '%';
            $stmt = $conn->prepare("SELECT bom_number FROM bom_headers WHERE bom_number LIKE :pattern ORDER BY bom_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastBOM = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastBOM) {
                // Extract the number part and increment
                $lastNumber = substr($lastBOM['bom_number'], strlen($prefix . $separator . $currentDate . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'prefix-number') {
            // Find the highest number for this prefix
            $searchPattern = $prefix . $separator . '%';
            $stmt = $conn->prepare("SELECT bom_number FROM bom_headers WHERE bom_number LIKE :pattern ORDER BY bom_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastBOM = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastBOM) {
                // Extract the number part and increment
                $lastNumber = substr($lastBOM['bom_number'], strlen($prefix . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'date-prefix-number') {
            // Find the highest number for today's date and prefix
            $searchPattern = $currentDate . $separator . $prefix . $separator . '%';
            $stmt = $conn->prepare("SELECT bom_number FROM bom_headers WHERE bom_number LIKE :pattern ORDER BY bom_number DESC LIMIT 1");
            $stmt->bindParam(':pattern', $searchPattern);
            $stmt->execute();
            $lastBOM = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastBOM) {
                // Extract the number part and increment
                $lastNumber = substr($lastBOM['bom_number'], strlen($currentDate . $separator . $prefix . $separator));
                $nextNumber = intval($lastNumber) + 1;
            }
        } elseif ($format === 'number-only') {
            // Find the highest number (no prefix/date)
            $stmt = $conn->prepare("SELECT bom_number FROM bom_headers WHERE bom_number REGEXP '^[0-9]+$' ORDER BY CAST(bom_number AS UNSIGNED) DESC LIMIT 1");
            $stmt->execute();
            $lastBOM = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastBOM) {
                $nextNumber = intval($lastBOM['bom_number']) + 1;
            }
        }

        // Format the number with leading zeros
        $number = str_pad($nextNumber, $length, '0', STR_PAD_LEFT);

        // Generate the final BOM number
        switch ($format) {
            case 'prefix-date-number':
                $bom_number = $prefix . $separator . $currentDate . $separator . $number;
                break;
            case 'prefix-number':
                $bom_number = $prefix . $separator . $number;
                break;
            case 'date-prefix-number':
                $bom_number = $currentDate . $separator . $prefix . $separator . $number;
                break;
            case 'number-only':
                $bom_number = $number;
                break;
            default:
                $bom_number = $prefix . $separator . $currentDate . $separator . $number;
        }

        return $bom_number;
    } catch (PDOException $e) {
        // Fallback to simple generation with timestamp
        return 'BOM-' . date('Ymd') . '-' . str_pad(1, 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Generate Production Order number based on system settings
 *
 * @param PDO $conn Database connection
 * @return string Generated Production Order number
 */
function generateProductionOrderNumber($conn) {
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bom_production_order_prefix', 'bom_production_order_length')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        $prefix = $settings['bom_production_order_prefix'] ?? 'PROD';
        $length = intval($settings['bom_production_order_length'] ?? 6);
        $currentDate = date('Ymd');

        // Get the next sequential number for today's date
        $searchPattern = $prefix . '-' . $currentDate . '-%';
        $stmt = $conn->prepare("SELECT production_order_number FROM bom_production_orders WHERE production_order_number LIKE :pattern ORDER BY production_order_number DESC LIMIT 1");
        $stmt->bindParam(':pattern', $searchPattern);
        $stmt->execute();
        $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextNumber = 1;
        if ($lastOrder) {
            // Extract the number part and increment
            $lastNumber = substr($lastOrder['production_order_number'], strlen($prefix . '-' . $currentDate . '-'));
            $nextNumber = intval($lastNumber) + 1;
        }

        // Format the number with leading zeros
        $number = str_pad($nextNumber, $length, '0', STR_PAD_LEFT);
        $production_order_number = $prefix . '-' . $currentDate . '-' . $number;

        return $production_order_number;
    } catch (PDOException $e) {
        // Fallback to simple generation with timestamp
        return 'PROD-' . date('Ymd') . '-' . str_pad(1, 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Calculate BOM component costs and totals (with multi-level BOM support)
 *
 * @param PDO $conn Database connection
 * @param int $bom_id BOM ID
 * @return array Cost calculation results
 */
function calculateBOMCost($conn, $bom_id) {
    try {
        $sql = "
            SELECT
                bc.*,
                p.name as component_name,
                p.sku as component_sku,
                p.quantity as available_stock,
                COALESCE(p.cost_price, 0) as current_cost_price,
                COALESCE(bh_sub.total_cost, 0) as sub_bom_total_cost,
                COALESCE(bh_sub.total_quantity, 1) as sub_bom_quantity,
                CASE WHEN bh_sub.id IS NOT NULL THEN 1 ELSE 0 END as has_sub_bom
            FROM bom_components bc
            INNER JOIN products p ON bc.component_product_id = p.id
            LEFT JOIN bom_headers bh_sub ON p.id = bh_sub.product_id AND bh_sub.status = 'active'
            WHERE bc.bom_id = :bom_id
            ORDER BY bc.sequence_number ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_material_cost = 0;
        $components_data = [];

        foreach ($components as $component) {
            // Use the component's unit_cost if set, otherwise use current product cost_price
            $unit_cost = $component['unit_cost'] > 0 ? $component['unit_cost'] : $component['current_cost_price'];

            // If component has its own BOM, use the rolled-up cost per unit from that BOM
            if ($component['has_sub_bom'] && $component['sub_bom_total_cost'] > 0) {
                $sub_bom_cost_per_unit = $component['sub_bom_total_cost'] / $component['sub_bom_quantity'];
                $unit_cost = max($unit_cost, $sub_bom_cost_per_unit); // Use the higher cost for safety
            }

            // Calculate quantity with waste
            $quantity_with_waste = $component['quantity_required'] * (1 + ($component['waste_percentage'] / 100));
            $total_cost = $quantity_with_waste * $unit_cost;

            $components_data[] = [
                'id' => $component['id'],
                'component_name' => $component['component_name'],
                'component_sku' => $component['component_sku'],
                'quantity_required' => $component['quantity_required'],
                'unit_of_measure' => $component['unit_of_measure'],
                'waste_percentage' => $component['waste_percentage'],
                'quantity_with_waste' => round($quantity_with_waste, 3),
                'unit_cost' => $unit_cost,
                'total_cost' => round($total_cost, 2),
                'available_stock' => $component['available_stock'],
                'stock_status' => $component['available_stock'] >= $quantity_with_waste ? 'sufficient' : 'insufficient',
                'supplier_id' => $component['supplier_id'],
                'has_sub_bom' => $component['has_sub_bom'],
                'sub_bom_cost' => $component['sub_bom_total_cost'] ?? 0
            ];

            $total_material_cost += $total_cost;
        }

        // Get BOM header to calculate final totals
        $stmt = $conn->prepare("SELECT * FROM bom_headers WHERE id = :bom_id");
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $bom_header = $stmt->fetch(PDO::FETCH_ASSOC);

        $labor_cost = $bom_header['labor_cost'] ?? 0;
        $overhead_cost = $bom_header['overhead_cost'] ?? 0;
        $total_quantity = $bom_header['total_quantity'] ?? 1;

        $total_cost = $total_material_cost + $labor_cost + $overhead_cost;
        $cost_per_unit = $total_quantity > 0 ? $total_cost / $total_quantity : 0;

        return [
            'bom_id' => $bom_id,
            'components' => $components_data,
            'material_cost' => round($total_material_cost, 2),
            'labor_cost' => round($labor_cost, 2),
            'overhead_cost' => round($overhead_cost, 2),
            'total_cost' => round($total_cost, 2),
            'cost_per_unit' => round($cost_per_unit, 2),
            'total_quantity' => $total_quantity,
            'multi_level_components' => count(array_filter($components_data, function($c) { return $c['has_sub_bom']; })),
            'profit_margin' => calculateBOMProfitMargin($conn, $bom_id, $cost_per_unit)
        ];
    } catch (PDOException $e) {
        return [
            'error' => $e->getMessage(),
            'material_cost' => 0,
            'labor_cost' => 0,
            'overhead_cost' => 0,
            'total_cost' => 0,
            'cost_per_unit' => 0,
            'components' => []
        ];
    }
}

/**
 * Get detailed BOM structure with multi-level breakdown
 *
 * @param PDO $conn Database connection
 * @param int $bom_id BOM ID
 * @return array Detailed BOM structure
 */
function getBOMStructure($conn, $bom_id) {
    try {
        // Get main BOM info
        $stmt = $conn->prepare("
            SELECT bh.*, p.name as product_name, p.sku as product_sku
            FROM bom_headers bh
            INNER JOIN products p ON bh.product_id = p.id
            WHERE bh.id = :bom_id
        ");
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $bom_header = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bom_header) {
            return ['error' => 'BOM not found'];
        }

        // Get all components with their sub-BOM info
        $stmt = $conn->prepare("
            SELECT
                bc.*,
                p.name as component_name,
                p.sku as component_sku,
                p.quantity as available_stock,
                COALESCE(bh_sub.id, NULL) as sub_bom_id,
                COALESCE(bh_sub.bom_number, NULL) as sub_bom_number,
                CASE WHEN bh_sub.id IS NOT NULL THEN 1 ELSE 0 END as has_sub_bom
            FROM bom_components bc
            INNER JOIN products p ON bc.component_product_id = p.id
            LEFT JOIN bom_headers bh_sub ON p.id = bh_sub.product_id AND bh_sub.status = 'active'
            WHERE bc.bom_id = :bom_id
            ORDER BY bc.sequence_number ASC
        ");
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build hierarchical structure
        $structure = [
            'bom_id' => $bom_id,
            'bom_number' => $bom_header['bom_number'],
            'product_name' => $bom_header['product_name'],
            'product_sku' => $bom_header['product_sku'],
            'version' => $bom_header['version'],
            'status' => $bom_header['status'],
            'components' => []
        ];

        foreach ($components as $component) {
            $component_data = [
                'id' => $component['id'],
                'component_name' => $component['component_name'],
                'component_sku' => $component['component_sku'],
                'quantity_required' => $component['quantity_required'],
                'unit_of_measure' => $component['unit_of_measure'],
                'waste_percentage' => $component['waste_percentage'],
                'has_sub_bom' => $component['has_sub_bom'],
                'available_stock' => $component['available_stock'],
                'stock_status' => $component['available_stock'] >= $component['quantity_required'] ? 'sufficient' : 'insufficient'
            ];

            // If component has sub-BOM, get its structure recursively
            if ($component['has_sub_bom']) {
                $sub_structure = getBOMStructure($conn, $component['sub_bom_id']);
                if (!isset($sub_structure['error'])) {
                    $component_data['sub_bom'] = $sub_structure;
                }
            }

            $structure['components'][] = $component_data;
        }

        return $structure;
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Calculate profit margin for BOM
 *
 * @param PDO $conn Database connection
 * @param int $bom_id BOM ID
 * @param float $cost_per_unit Calculated cost per unit
 * @return array Profit margin data
 */
function calculateBOMProfitMargin($conn, $bom_id, $cost_per_unit) {
    try {
        // Get the finished product information
        $stmt = $conn->prepare("
            SELECT p.price, p.sale_price, p.name
            FROM bom_headers bh
            INNER JOIN products p ON bh.product_id = p.id
            WHERE bh.id = :bom_id
        ");
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return ['error' => 'Product not found'];
        }

        $selling_price = $product['sale_price'] > 0 ? $product['sale_price'] : $product['price'];

        if ($cost_per_unit <= 0) {
            return [
                'selling_price' => $selling_price,
                'cost_per_unit' => $cost_per_unit,
                'profit_margin' => 0,
                'profit_amount' => 0
            ];
        }

        $profit_amount = $selling_price - $cost_per_unit;
        $profit_margin = ($profit_amount / $cost_per_unit) * 100;

        return [
            'product_name' => $product['name'],
            'selling_price' => $selling_price,
            'cost_per_unit' => $cost_per_unit,
            'profit_amount' => round($profit_amount, 2),
            'profit_margin' => round($profit_margin, 2)
        ];
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Check material availability for BOM production
 *
 * @param PDO $conn Database connection
 * @param int $bom_id BOM ID
 * @param int $quantity_to_produce Quantity to produce
 * @return array Availability check results
 */
function checkBOMMaterialAvailability($conn, $bom_id, $quantity_to_produce = 1) {
    try {
        $sql = "
            SELECT
                bc.*,
                p.name as component_name,
                p.quantity as available_stock,
                p.minimum_stock,
                p.sku as component_sku
            FROM bom_components bc
            INNER JOIN products p ON bc.component_product_id = p.id
            WHERE bc.bom_id = :bom_id
            ORDER BY bc.sequence_number ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $availability = [];
        $all_available = true;
        $shortages = [];

        foreach ($components as $component) {
            $required_quantity = $component['quantity_required'] * $quantity_to_produce;
            $quantity_with_waste = $required_quantity * (1 + ($component['waste_percentage'] / 100));
            $available = $component['available_stock'];
            $is_available = $available >= $quantity_with_waste;

            $availability[] = [
                'component_id' => $component['component_product_id'],
                'component_name' => $component['component_name'],
                'component_sku' => $component['component_sku'],
                'required_quantity' => round($required_quantity, 3),
                'quantity_with_waste' => round($quantity_with_waste, 3),
                'available_stock' => $available,
                'is_available' => $is_available,
                'shortage_quantity' => $is_available ? 0 : round($quantity_with_waste - $available, 3),
                'minimum_stock' => $component['minimum_stock']
            ];

            if (!$is_available) {
                $all_available = false;
                $shortages[] = $component['component_name'];
            }
        }

        return [
            'all_available' => $all_available,
            'components' => $availability,
            'shortages' => $shortages,
            'shortage_count' => count($shortages),
            'quantity_to_produce' => $quantity_to_produce
        ];
    } catch (PDOException $e) {
        return [
            'error' => $e->getMessage(),
            'all_available' => false,
            'components' => [],
            'shortages' => [],
            'shortage_count' => 0
        ];
    }
}

/**
 * Get BOM explosion (all components needed)
 *
 * @param PDO $conn Database connection
 * @param int $bom_id BOM ID
 * @param int $quantity Quantity to produce
 * @param int $level Current explosion level (for multi-level BOMs)
 * @return array BOM explosion data
 */
function getBOMExplosion($conn, $bom_id, $quantity = 1, $level = 0) {
    try {
        $explosion = [];
        $sql = "
            SELECT
                bc.*,
                p.name as component_name,
                p.sku as component_sku,
                p.quantity as available_stock,
                p.cost_price,
                c.name as category_name
            FROM bom_components bc
            INNER JOIN products p ON bc.component_product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE bc.bom_id = :bom_id
            ORDER BY bc.sequence_number ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bom_id', $bom_id, PDO::PARAM_INT);
        $stmt->execute();
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($components as $component) {
            $required_qty = $component['quantity_required'] * $quantity;
            $qty_with_waste = $required_qty * (1 + ($component['waste_percentage'] / 100));

            $explosion[] = [
                'level' => $level,
                'component_id' => $component['component_product_id'],
                'component_name' => $component['component_name'],
                'component_sku' => $component['component_sku'],
                'category_name' => $component['category_name'],
                'quantity_required' => round($required_qty, 3),
                'quantity_with_waste' => round($qty_with_waste, 3),
                'unit_of_measure' => $component['unit_of_measure'],
                'waste_percentage' => $component['waste_percentage'],
                'available_stock' => $component['available_stock'],
                'unit_cost' => $component['cost_price'],
                'total_cost' => round($qty_with_waste * $component['cost_price'], 2),
                'is_available' => $component['available_stock'] >= $qty_with_waste
            ];

            // Check if this component has its own BOM (multi-level BOM)
            $stmt = $conn->prepare("SELECT id FROM bom_headers WHERE product_id = :product_id AND status = 'active'");
            $stmt->bindParam(':product_id', $component['component_product_id'], PDO::PARAM_INT);
            $stmt->execute();
            $sub_bom = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($sub_bom) {
                // Recursively get sub-components
                $sub_explosion = getBOMExplosion($conn, $sub_bom['id'], $required_qty, $level + 1);
                $explosion = array_merge($explosion, $sub_explosion);
            }
        }

        return $explosion;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get where-used report for a component (which BOMs use this component)
 *
 * @param PDO $conn Database connection
 * @param int $component_product_id Component product ID
 * @return array Where-used data
 */
function getWhereUsedReport($conn, $component_product_id) {
    try {
        $sql = "
            SELECT
                bh.id as bom_id,
                bh.bom_number,
                bh.name as bom_name,
                bh.version,
                bh.status,
                p.name as finished_product_name,
                p.sku as finished_product_sku,
                bc.quantity_required,
                bc.unit_of_measure,
                bc.waste_percentage,
                (bc.quantity_required * (1 + bc.waste_percentage / 100)) as quantity_with_waste,
                bc.unit_cost,
                (bc.quantity_required * (1 + bc.waste_percentage / 100) * bc.unit_cost) as total_cost
            FROM bom_components bc
            INNER JOIN bom_headers bh ON bc.bom_id = bh.id
            INNER JOIN products p ON bh.product_id = p.id
            WHERE bc.component_product_id = :component_product_id
            AND bh.status IN ('active', 'draft')
            ORDER BY bh.bom_number ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':component_product_id', $component_product_id, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'component_id' => $component_product_id,
            'used_in_count' => count($results),
            'boms' => $results
        ];
    } catch (PDOException $e) {
        return [
            'error' => $e->getMessage(),
            'component_id' => $component_product_id,
            'used_in_count' => 0,
            'boms' => []
        ];
    }
}

/**
 * Get BOM statistics for dashboard
 *
 * @param PDO $conn Database connection
 * @return array BOM statistics
 */
function getBOMStatistics($conn) {
    try {
        $stats = [];

        // Total active BOMs
        $stmt = $conn->query("SELECT COUNT(*) as count FROM bom_headers WHERE status = 'active'");
        $stats['total_active_boms'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Draft BOMs
        $stmt = $conn->query("SELECT COUNT(*) as count FROM bom_headers WHERE status = 'draft'");
        $stats['draft_boms'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Total production orders
        $stmt = $conn->query("SELECT COUNT(*) as count FROM bom_production_orders");
        $stats['total_production_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Active production orders
        $stmt = $conn->query("SELECT COUNT(*) as count FROM bom_production_orders WHERE status IN ('planned', 'in_progress')");
        $stats['active_production_orders'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Completed production orders this month
        $stmt = $conn->query("SELECT COUNT(*) as count FROM bom_production_orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $stats['completed_this_month'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Total production value this month
        $stmt = $conn->query("SELECT COALESCE(SUM(total_production_cost), 0) as total FROM bom_production_orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $stats['production_value_this_month'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        return $stats;
    } catch (PDOException $e) {
        return [
            'total_active_boms' => 0,
            'draft_boms' => 0,
            'total_production_orders' => 0,
            'active_production_orders' => 0,
            'completed_this_month' => 0,
            'production_value_this_month' => 0
        ];
    }
}

/**
 * Update supplier performance metrics in database
 *
 * @param PDO $conn Database connection
 * @param int $supplier_id Supplier ID
 * @param string $metric_date Date for metrics (Y-m-d)
 * @return bool Success status
 */
function updateSupplierPerformanceMetrics($conn, $supplier_id, $metric_date = null) {
    try {
        if (!$metric_date) {
            $metric_date = date('Y-m-d');
        }

        // Get performance data for last 90 days
        $performance = getSupplierPerformance($conn, $supplier_id, '90days');

        // Insert or update metrics
        $sql = "
            INSERT INTO supplier_performance_metrics
            (supplier_id, metric_date, total_orders, on_time_deliveries, late_deliveries,
             average_delivery_days, total_returns, quality_score, total_order_value,
             average_cost_per_unit, total_return_value, return_rate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_orders = VALUES(total_orders),
                on_time_deliveries = VALUES(on_time_deliveries),
                late_deliveries = VALUES(late_deliveries),
                average_delivery_days = VALUES(average_delivery_days),
                total_returns = VALUES(total_returns),
                quality_score = VALUES(quality_score),
                total_order_value = VALUES(total_order_value),
                average_cost_per_unit = VALUES(average_cost_per_unit),
                total_return_value = VALUES(total_return_value),
                return_rate = VALUES(return_rate)
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $supplier_id,
            $metric_date,
            $performance['delivery_performance']['total_orders'],
            $performance['delivery_performance']['on_time_deliveries'],
            $performance['delivery_performance']['late_deliveries'],
            $performance['delivery_performance']['average_delivery_days'],
            $performance['quality_metrics']['total_returns'],
            $performance['quality_metrics']['quality_score'],
            $performance['cost_performance']['total_order_value'],
            $performance['cost_performance']['average_cost_per_unit'],
            $performance['quality_metrics']['total_return_value'],
            $performance['quality_metrics']['return_rate']
        ]);

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Get currency symbol only
 *
 * @param array $settings System settings array
 * @return string Currency symbol
 */
function getCurrencySymbol($settings = null) {
    if ($settings === null) {
        global $conn;
        $settings = getSystemSettings($conn);
    }
    return $settings['currency_symbol'] ?? 'KES';
}

/**
 * Get system settings with defaults
 *
 * @param PDO $conn Database connection
 * @return array System settings
 */
function getSystemSettings($conn) {
    try {
        $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['setting_key']) && isset($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return $settings;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get the walk-in customer ID
 *
 * @param PDO $conn Database connection
 * @return int|null Walk-in customer ID or null if not found
 */
function getWalkInCustomerId($conn) {
    try {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE customer_type = 'walk_in' AND customer_number = 'WALK-IN-001' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get walk-in customer details
 *
 * @param PDO $conn Database connection
 * @return array|null Walk-in customer data or null if not found
 */
function getWalkInCustomer($conn) {
    try {
        $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_type = 'walk_in' AND customer_number = 'WALK-IN-001' LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get all customers for selection
 *
 * @param PDO $conn Database connection
 * @param string $search Search term for customer name or phone
 * @return array List of customers
 */
function getAllCustomers($conn, $search = '') {
    try {
        // First, let's check if tax_exempt column exists
        $columnsStmt = $conn->query("SHOW COLUMNS FROM customers LIKE 'tax_exempt'");
        $hasTaxExempt = $columnsStmt->rowCount() > 0;
        
        $sql = "SELECT id, customer_number, first_name, last_name, email, phone, customer_type, 
                       company_name, membership_level";
        
        if ($hasTaxExempt) {
            $sql .= ", tax_exempt";
        } else {
            $sql .= ", 0 as tax_exempt";
        }
        
        $sql .= " FROM customers WHERE membership_status = 'active'";
        
        $params = [];
        if (!empty($search)) {
            $sql .= " AND (CONCAT(first_name, ' ', last_name) LIKE :search 
                     OR phone LIKE :search 
                     OR email LIKE :search 
                     OR customer_number LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $sql .= " ORDER BY customer_type ASC, first_name ASC, last_name ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getAllCustomers error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get customer by ID
 *
 * @param PDO $conn Database connection
 * @param int $customerId Customer ID
 * @return array|null Customer data or null if not found
 */
function getCustomerById($conn, $customerId) {
    try {
        $stmt = $conn->prepare("SELECT * FROM customers WHERE id = :customer_id");
        $stmt->bindParam(':customer_id', $customerId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * LOYALTY POINTS FUNCTIONS
 */

/**
 * Get customer loyalty points balance
 *
 * @param PDO $conn Database connection
 * @param int $customerId Customer ID
 * @return int Current loyalty points balance
 */
function getCustomerLoyaltyBalance($conn, $customerId) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(
                CASE 
                    WHEN transaction_type = 'earned' THEN points_earned
                    WHEN transaction_type = 'redeemed' THEN -points_redeemed
                    WHEN transaction_type = 'expired' THEN -points_earned
                    ELSE 0
                END
            ), 0) as balance
            FROM loyalty_points 
            WHERE customer_id = :customer_id
        ");
        $stmt->bindParam(':customer_id', $customerId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['balance'];
    } catch (PDOException $e) {
        error_log("Error getting loyalty balance: " . $e->getMessage());
        return 0;
    }
}

/**
 * Add loyalty points to customer
 *
 * @param PDO $conn Database connection
 * @param int $customerId Customer ID
 * @param int $points Points to add
 * @param string $description Description of the transaction
 * @param string $transactionReference Reference for the transaction
 * @param string $expiryDate Expiry date (optional, will be calculated from settings if not provided)
 * @return bool Success status
 */
function addLoyaltyPoints($conn, $customerId, $points, $description, $transactionReference = null, $expiryDate = null) {
    try {
        if ($points <= 0) {
            return false;
        }

        // Calculate expiry date if not provided
        if ($expiryDate === null) {
            $settings = getLoyaltySettings($conn);
            $expiryDays = $settings['points_expiry_days'];
            if ($expiryDays > 0) {
                $expiryDate = date('Y-m-d', strtotime("+{$expiryDays} days"));
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO loyalty_points (
                customer_id, points_earned, points_balance, transaction_type, 
                transaction_reference, description, expiry_date
            ) VALUES (?, ?, ?, 'earned', ?, ?, ?)
        ");

        // Get current balance
        $currentBalance = getCustomerLoyaltyBalance($conn, $customerId);
        $newBalance = $currentBalance + $points;

        $stmt->execute([
            $customerId,
            $points,
            $newBalance,
            $transactionReference,
            $description,
            $expiryDate
        ]);

        // Update customer's loyalty_points field
        $updateStmt = $conn->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
        $updateStmt->execute([$newBalance, $customerId]);

        return true;
    } catch (PDOException $e) {
        error_log("Error adding loyalty points: " . $e->getMessage());
        return false;
    }
}

/**
 * Redeem loyalty points from customer
 *
 * @param PDO $conn Database connection
 * @param int $customerId Customer ID
 * @param int $points Points to redeem
 * @param string $description Description of the transaction
 * @param string $transactionReference Reference for the transaction
 * @return bool Success status
 */
function redeemLoyaltyPoints($conn, $customerId, $points, $description, $transactionReference = null) {
    try {
        if ($points <= 0) {
            return false;
        }

        // Check if customer has enough points
        $currentBalance = getCustomerLoyaltyBalance($conn, $customerId);
        if ($currentBalance < $points) {
            return false;
        }

        $stmt = $conn->prepare("
            INSERT INTO loyalty_points (
                customer_id, points_redeemed, points_balance, transaction_type, 
                transaction_reference, description
            ) VALUES (?, ?, ?, 'redeemed', ?, ?)
        ");

        $newBalance = $currentBalance - $points;

        $stmt->execute([
            $customerId,
            $points,
            $newBalance,
            $transactionReference,
            $description
        ]);

        // Update customer's loyalty_points field
        $updateStmt = $conn->prepare("UPDATE customers SET loyalty_points = ? WHERE id = ?");
        $updateStmt->execute([$newBalance, $customerId]);

        return true;
    } catch (PDOException $e) {
        error_log("Error redeeming loyalty points: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate loyalty points to earn based on purchase amount and settings
 *
 * @param PDO $conn Database connection
 * @param float $amount Purchase amount
 * @param string $membershipLevel Customer membership level
 * @return int Points to earn
 */
function calculateLoyaltyPoints($conn, $amount, $membershipLevel = 'Basic') {
    // Get loyalty settings
    $settings = getLoyaltySettings($conn);
    
    // Check if loyalty program is enabled
    if (!$settings['enable_loyalty_program']) {
        return 0;
    }
    
    // Check minimum purchase requirement
    if ($amount < $settings['loyalty_minimum_purchase']) {
        return 0;
    }
    
    // Get base points per currency unit
    $basePointsPerCurrency = $settings['loyalty_points_per_currency'];
    
    // Get membership level multiplier from database
    $multiplier = getMembershipLevelMultiplier($conn, $membershipLevel);
    
    $pointsPerCurrency = $basePointsPerCurrency * $multiplier;
    return (int)floor($amount * $pointsPerCurrency);
}

/**
 * Get loyalty program settings
 *
 * @param PDO $conn Database connection
 * @return array Loyalty settings
 */
function getLoyaltySettings($conn) {
    try {
        $stmt = $conn->query("
            SELECT setting_key, setting_value 
            FROM pos_settings 
            WHERE setting_key LIKE 'loyalty_%' AND is_active = 1
        ");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('loyalty_', '', $row['setting_key']);
            $value = $row['setting_value'];
            
            // Convert to appropriate type
            if (in_array($key, ['enable_loyalty_program', 'auto_level_upgrade'])) {
                $settings[$key] = (bool)$value;
            } elseif (in_array($key, ['points_expiry_days'])) {
                $settings[$key] = (int)$value;
            } else {
                $settings[$key] = (float)$value;
            }
        }
        
        // Set defaults if settings don't exist
        $defaults = [
            'enable_loyalty_program' => true,
            'points_per_currency' => 1.0,
            'minimum_purchase' => 0.0,
            'points_expiry_days' => 365,
            'auto_level_upgrade' => true
        ];
        
        return array_merge($defaults, $settings);
    } catch (PDOException $e) {
        error_log("Error getting loyalty settings: " . $e->getMessage());
        return [
            'enable_loyalty_program' => true,
            'points_per_currency' => 1.0,
            'minimum_purchase' => 0.0,
            'points_expiry_days' => 365,
            'auto_level_upgrade' => true
        ];
    }
}

/**
 * MEMBERSHIP LEVEL MANAGEMENT FUNCTIONS
 */

/**
 * Get all active membership levels
 *
 * @param PDO $conn Database connection
 * @return array Membership levels
 */
function getAllMembershipLevels($conn) {
    try {
        $stmt = $conn->query("
            SELECT * FROM membership_levels 
            WHERE is_active = 1 
            ORDER BY sort_order ASC, level_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting membership levels: " . $e->getMessage());
        return [];
    }
}

/**
 * Get membership level by name
 *
 * @param PDO $conn Database connection
 * @param string $levelName Level name
 * @return array|null Membership level data
 */
function getMembershipLevelByName($conn, $levelName) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM membership_levels 
            WHERE level_name = ? AND is_active = 1
        ");
        $stmt->execute([$levelName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting membership level: " . $e->getMessage());
        return null;
    }
}

/**
 * Get membership level multiplier
 *
 * @param PDO $conn Database connection
 * @param string $levelName Level name
 * @return float Multiplier value
 */
function getMembershipLevelMultiplier($conn, $levelName) {
    $level = getMembershipLevelByName($conn, $levelName);
    return $level ? (float)$level['points_multiplier'] : 1.0;
}

/**
 * Create a new membership level
 *
 * @param PDO $conn Database connection
 * @param array $levelData Level data
 * @return bool Success status
 */
function createMembershipLevel($conn, $levelData) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO membership_levels (
                level_name, level_description, points_multiplier, 
                minimum_points_required, color_code, is_active, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $levelData['level_name'],
            $levelData['level_description'],
            $levelData['points_multiplier'],
            $levelData['minimum_points_required'],
            $levelData['color_code'],
            $levelData['is_active'],
            $levelData['sort_order']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating membership level: " . $e->getMessage());
        return false;
    }
}

/**
 * Update a membership level
 *
 * @param PDO $conn Database connection
 * @param int $levelId Level ID
 * @param array $levelData Level data
 * @return bool Success status
 */
function updateMembershipLevel($conn, $levelId, $levelData) {
    try {
        $stmt = $conn->prepare("
            UPDATE membership_levels SET
                level_name = ?, level_description = ?, points_multiplier = ?,
                minimum_points_required = ?, color_code = ?, is_active = ?, sort_order = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $levelData['level_name'],
            $levelData['level_description'],
            $levelData['points_multiplier'],
            $levelData['minimum_points_required'],
            $levelData['color_code'],
            $levelData['is_active'],
            $levelData['sort_order'],
            $levelId
        ]);
    } catch (PDOException $e) {
        error_log("Error updating membership level: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a membership level
 *
 * @param PDO $conn Database connection
 * @param int $levelId Level ID
 * @return bool Success status
 */
function deleteMembershipLevel($conn, $levelId) {
    try {
        // Check if this is the only active level
        $countStmt = $conn->query("SELECT COUNT(*) FROM membership_levels WHERE is_active = 1");
        $activeCount = $countStmt->fetchColumn();
        
        if ($activeCount <= 1) {
            throw new Exception("Cannot delete the last active membership level");
        }
        
        $stmt = $conn->prepare("DELETE FROM membership_levels WHERE id = ?");
        return $stmt->execute([$levelId]);
    } catch (PDOException $e) {
        error_log("Error deleting membership level: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Error deleting membership level: " . $e->getMessage());
        return false;
    }
}

/**
 * Get customer's appropriate membership level based on points
 *
 * @param PDO $conn Database connection
 * @param int $customerPoints Customer's current points
 * @return array Membership level data
 */
function getCustomerMembershipLevel($conn, $customerPoints) {
    try {
        $stmt = $conn->query("
            SELECT * FROM membership_levels 
            WHERE is_active = 1 AND minimum_points_required <= ?
            ORDER BY minimum_points_required DESC, sort_order ASC
            LIMIT 1
        ");
        $stmt->execute([$customerPoints]);
        $level = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return default level if none found
        if (!$level) {
            $stmt = $conn->query("
                SELECT * FROM membership_levels 
                WHERE is_active = 1 
                ORDER BY sort_order ASC 
                LIMIT 1
            ");
            $level = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $level ?: ['level_name' => 'Basic', 'points_multiplier' => 1.0];
    } catch (PDOException $e) {
        error_log("Error getting customer membership level: " . $e->getMessage());
        return ['level_name' => 'Basic', 'points_multiplier' => 1.0];
    }
}

/**
 * Calculate loyalty points value in currency
 *
 * @param int $points Loyalty points
 * @param float $pointsToCurrencyRate Points to currency conversion rate (default: 100 points = 1 currency unit)
 * @return float Currency value of points
 */
function calculateLoyaltyPointsValue($points, $pointsToCurrencyRate = 100) {
    return $points / $pointsToCurrencyRate;
}

/**
 * Get available loyalty rewards
 *
 * @param PDO $conn Database connection
 * @param int $customerPoints Customer's current points
 * @return array Available rewards
 */
function getAvailableLoyaltyRewards($conn, $customerPoints = 0) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_rewards 
            WHERE is_active = 1 AND points_required <= ?
            ORDER BY points_required ASC
        ");
        $stmt->execute([$customerPoints]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting loyalty rewards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get customer loyalty transaction history
 *
 * @param PDO $conn Database connection
 * @param int $customerId Customer ID
 * @param int $limit Number of records to return
 * @return array Transaction history
 */
function getCustomerLoyaltyHistory($conn, $customerId, $limit = 20) {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM loyalty_points 
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting loyalty history: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a customer is the walk-in customer
 *
 * @param int $customerId Customer ID to check
 * @param PDO $conn Database connection
 * @return bool True if customer is walk-in, false otherwise
 */
function isWalkInCustomer($customerId, $conn) {
    try {
        $stmt = $conn->prepare("SELECT customer_type FROM customers WHERE id = :customer_id");
        $stmt->bindParam(':customer_id', $customerId);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['customer_type'] === 'walk_in';
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Generate formatted receipt number
 * 
 * @param int $sale_id Sale ID from database
 * @param string $date Sale date (optional, defaults to current date)
 * @return string Formatted receipt number (e.g., R241204000123)
 */
function generateReceiptNumber($sale_id, $date = null) {
    if ($date === null) {
        $date = date('ymd');
    } else {
        $date = date('ymd', strtotime($date));
    }
    
    $padded_id = str_pad($sale_id, 6, '0', STR_PAD_LEFT);
    return "R{$date}{$padded_id}";
}

/**
 * Generate barcode-friendly receipt number
 * 
 * @param int $sale_id Sale ID from database
 * @param string $date Sale date (optional, defaults to current date)
 * @return string Barcode-ready receipt number
 */
function generateBarcodeReceiptNumber($sale_id, $date = null) {
    return generateReceiptNumber($sale_id, $date);
}

/**
 * Map sales payment method to payment type name
 *
 * @param string $paymentMethod The payment method from sales table
 * @return string The corresponding payment type name
 */
function mapPaymentMethodToType($paymentMethod) {
    $mapping = [
        'cash' => 'cash',
        'mobile_money' => 'mobile_money',
        'mpesa' => 'mobile_money',
        'airtel_money' => 'mobile_money',
        'credit_card' => 'credit_card',
        'debit_card' => 'debit_card',
        'bank_transfer' => 'bank_transfer',
        'bank' => 'bank_transfer',
        'check' => 'check',
        'pos_card' => 'pos_card',
        'card' => 'pos_card',
        'online' => 'online_payment',
        'online_payment' => 'online_payment',
        'voucher' => 'voucher',
        'store_credit' => 'store_credit',
        'loyalty' => 'store_credit'
    ];
    
    return $mapping[$paymentMethod] ?? 'cash';
}

/**
 * Get payment type information for a sales transaction
 *
 * @param string $paymentMethod The payment method from sales table
 * @param PDO $conn Database connection
 * @return array|null Payment type information or null if not found
 */
function getPaymentTypeForSale($paymentMethod, $conn) {
    $paymentTypeName = mapPaymentMethodToType($paymentMethod);
    
    $stmt = $conn->prepare("SELECT * FROM payment_types WHERE name = ? AND is_active = 1");
    $stmt->execute([$paymentTypeName]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Dynamically discover pages in the system
 * 
 * @param string $basePath Base path to scan for PHP files
 * @return array Array of discovered pages with metadata
 */
function discoverSystemPages($basePath = null) {
    if ($basePath === null) {
        $basePath = __DIR__ . '/..';
    }
    
    $pages = [];
    $excludeDirs = ['vendor', 'node_modules', 'logs', 'backups', 'storage', 'sql', 'assets'];
    $excludeFiles = ['index.php', 'starter.php', 'composer.json', 'composer.lock', 'composer.phar'];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);
            
            // Skip excluded directories
            $skip = false;
            foreach ($excludeDirs as $excludeDir) {
                if (strpos($relativePath, $excludeDir . '/') === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;
            
            // Skip excluded files
            $filename = basename($relativePath);
            if (in_array($filename, $excludeFiles)) continue;
            
            // Skip API files and utility files
            if (strpos($relativePath, 'api/') === 0 || 
                strpos($relativePath, 'utils/') === 0 ||
                strpos($relativePath, 'include/') === 0) continue;
            
            // Generate page name from path
            $pageName = generatePageNameFromPath($relativePath);
            $pageUrl = '/' . $relativePath;
            
            // Determine category
            $category = determinePageCategory($relativePath);
            
            // Determine if admin only
            $isAdminOnly = isAdminOnlyPage($relativePath);
            
            // Determine required permission
            $requiredPermission = getRequiredPermissionForPage($relativePath);
            
            $pages[] = [
                'page_name' => $pageName,
                'page_url' => $pageUrl,
                'page_category' => $category,
                'page_description' => generatePageDescription($pageName, $category),
                'is_admin_only' => $isAdminOnly,
                'required_permission' => $requiredPermission,
                'sort_order' => getSortOrderForPage($relativePath, $category)
            ];
        }
    }
    
    return $pages;
}

/**
 * Generate a user-friendly page name from file path
 */
function generatePageNameFromPath($path) {
    $pathParts = explode('/', $path);
    $filename = pathinfo($path, PATHINFO_FILENAME);
    
    // Handle special cases
    $specialNames = [
        'dashboard.php' => 'Dashboard',
        'sale.php' => 'Point of Sale',
        'products.php' => 'All Products',
        'categories.php' => 'Categories',
        'brands.php' => 'Brands',
        'suppliers.php' => 'Suppliers',
        'inventory.php' => 'Inventory Management',
        'expiry_tracker.php' => 'Expiry Tracker',
        'index.php' => ucfirst($pathParts[count($pathParts) - 2]) . ' Dashboard',
        'add.php' => 'Add ' . ucfirst($pathParts[count($pathParts) - 2]),
        'edit.php' => 'Edit ' . ucfirst($pathParts[count($pathParts) - 2]),
        'view.php' => 'View ' . ucfirst($pathParts[count($pathParts) - 2]),
        'delete.php' => 'Delete ' . ucfirst($pathParts[count($pathParts) - 2])
    ];
    
    if (isset($specialNames[$filename])) {
        return $specialNames[$filename];
    }
    
    // Convert filename to readable name
    $name = str_replace('_', ' ', $filename);
    $name = ucwords($name);
    
    return $name;
}

/**
 * Determine page category based on path
 */
function determinePageCategory($path) {
    $pathParts = explode('/', $path);
    $firstDir = $pathParts[0];
    
    $categories = [
        'dashboard' => 'Dashboard',
        'pos' => 'Point of Sale',
        'products' => 'Products',
        'categories' => 'Products',
        'brands' => 'Products',
        'suppliers' => 'Products',
        'product_families' => 'Products',
        'inventory' => 'Inventory',
        'shelf_label' => 'Inventory',
        'expiry_tracker' => 'Inventory',
        'bom' => 'BOM Management',
        'finance' => 'Finance',
        'expenses' => 'Expenses',
        'sales' => 'Sales',
        'customers' => 'Customers',
        'analytics' => 'Analytics',
        'admin' => 'Administration',
        'auth' => 'Authentication'
    ];
    
    return $categories[$firstDir] ?? 'General';
}

/**
 * Check if page is admin only
 */
function isAdminOnlyPage($path) {
    $adminOnlyPatterns = [
        'admin/',
        'dashboard/roles/',
        'dashboard/users/',
        'api/',
        'include/',
        'utils/'
    ];
    
    foreach ($adminOnlyPatterns as $pattern) {
        if (strpos($path, $pattern) === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get required permission for page
 */
function getRequiredPermissionForPage($path) {
    $permissionMap = [
        'pos/' => 'process_sales',
        'customers/' => 'view_customers',
        'products/' => 'manage_inventory',
        'categories/' => 'manage_categories',
        'brands/' => 'manage_product_brands',
        'suppliers/' => 'manage_product_suppliers',
        'inventory/' => 'manage_inventory',
        'expiry_tracker/' => 'view_expiry_alerts',
        'bom/' => 'view_boms',
        'finance/' => 'view_finance',
        'expenses/' => 'view_expense_reports',
        'sales/' => 'view_analytics',
        'analytics/' => 'view_analytics',
        'dashboard/users/' => 'manage_users',
        'dashboard/roles/' => 'manage_roles',
        'admin/' => 'manage_settings'
    ];
    
    foreach ($permissionMap as $pattern => $permission) {
        if (strpos($path, $pattern) === 0) {
            return $permission;
        }
    }
    
    return null;
}

/**
 * Generate page description
 */
function generatePageDescription($pageName, $category) {
    return "Access {$pageName} in the {$category} section";
}

/**
 * Get sort order for page
 */
function getSortOrderForPage($path, $category) {
    $categoryOrder = [
        'Dashboard' => 0,
        'Point of Sale' => 1,
        'Products' => 2,
        'Inventory' => 3,
        'BOM Management' => 4,
        'Finance' => 5,
        'Expenses' => 6,
        'Sales' => 7,
        'Customers' => 8,
        'Analytics' => 9,
        'Administration' => 10,
        'Authentication' => 11,
        'General' => 12
    ];
    
    $baseOrder = $categoryOrder[$category] ?? 12;
    
    // Add sub-order based on filename
    $filename = basename($path);
    $subOrder = 0;
    
    if (strpos($filename, 'index.php') !== false) $subOrder = 0;
    elseif (strpos($filename, 'add.php') !== false) $subOrder = 1;
    elseif (strpos($filename, 'edit.php') !== false) $subOrder = 2;
    elseif (strpos($filename, 'view.php') !== false) $subOrder = 3;
    elseif (strpos($filename, 'delete.php') !== false) $subOrder = 4;
    else $subOrder = 5;
    
    return ($baseOrder * 100) + $subOrder;
}

/**
 * Sync discovered pages with database
 */
function syncPagesWithDatabase($conn) {
    $discoveredPages = discoverSystemPages();
    
    foreach ($discoveredPages as $page) {
        // Check if page already exists
        $stmt = $conn->prepare("SELECT id FROM available_pages WHERE page_url = ?");
        $stmt->execute([$page['page_url']]);
        
        if ($stmt->rowCount() == 0) {
            // Insert new page
            $stmt = $conn->prepare("
                INSERT INTO available_pages 
                (page_name, page_url, page_category, page_description, is_admin_only, required_permission, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $page['page_name'],
                $page['page_url'],
                $page['page_category'],
                $page['page_description'],
                $page['is_admin_only'] ? 1 : 0,
                $page['required_permission'],
                $page['sort_order']
            ]);
        } else {
            // Update existing page metadata
            $stmt = $conn->prepare("
                UPDATE available_pages 
                SET page_name = ?, page_category = ?, page_description = ?, 
                    is_admin_only = ?, required_permission = ?, sort_order = ?
                WHERE page_url = ?
            ");
            $stmt->execute([
                $page['page_name'],
                $page['page_category'],
                $page['page_description'],
                $page['is_admin_only'] ? 1 : 0,
                $page['required_permission'],
                $page['sort_order'],
                $page['page_url']
            ]);
        }
    }
}

/**
 * Get available pages for dropdown based on user permissions
 */
function getAvailablePagesForUser($conn, $isAdmin, $permissions) {
    if ($isAdmin) {
        // Admin gets all active pages
        $stmt = $conn->prepare("
            SELECT page_name, page_url 
            FROM available_pages 
            WHERE is_active = 1 
            ORDER BY sort_order, page_name
        ");
        $stmt->execute();
    } else {
        // Non-admin gets pages based on permissions
        $permissionConditions = [];
        $params = [];
        
        // Always include basic pages
        $permissionConditions[] = "required_permission IS NULL";
        
        // Add pages based on user permissions
        foreach ($permissions as $permission) {
            $permissionConditions[] = "required_permission = ?";
            $params[] = $permission;
        }
        
        $whereClause = implode(' OR ', $permissionConditions);
        
        $stmt = $conn->prepare("
            SELECT page_name, page_url 
            FROM available_pages 
            WHERE is_active = 1 AND ({$whereClause})
            ORDER BY sort_order, page_name
        ");
        $stmt->execute($params);
    }
    
    $pages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pages[$row['page_name']] = $row['page_url'];
    }
    
    return $pages;
}

/**
 * Get available pages grouped by section for filtering
 */
function getAvailablePagesBySection($conn, $isAdmin, $permissions) {
    if ($isAdmin) {
        // Admin gets all active pages
        $stmt = $conn->prepare("
            SELECT page_name, page_url, page_category 
            FROM available_pages 
            WHERE is_active = 1 
            ORDER BY page_category, sort_order, page_name
        ");
        $stmt->execute();
    } else {
        // Non-admin gets pages based on permissions
        $permissionConditions = [];
        $params = [];
        
        // Always include basic pages
        $permissionConditions[] = "required_permission IS NULL";
        
        // Add pages based on user permissions
        foreach ($permissions as $permission) {
            $permissionConditions[] = "required_permission = ?";
            $params[] = $permission;
        }
        
        $whereClause = implode(' OR ', $permissionConditions);
        
        $stmt = $conn->prepare("
            SELECT page_name, page_url, page_category 
            FROM available_pages 
            WHERE is_active = 1 AND ({$whereClause})
            ORDER BY page_category, sort_order, page_name
        ");
        $stmt->execute($params);
    }
    
    $pagesBySection = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $category = $row['page_category'];
        if (!isset($pagesBySection[$category])) {
            $pagesBySection[$category] = [];
        }
        $pagesBySection[$category][$row['page_name']] = $row['page_url'];
    }
    
    return $pagesBySection;
}

/**
 * Map section keys to page categories for filtering
 */
function getSectionToCategoryMapping() {
    return [
        'inventory' => ['Inventory', 'Products'],
        'expiry' => ['Inventory'],
        'bom' => ['BOM Management'],
        'finance' => ['Finance'],
        'expenses' => ['Expenses'],
        'admin' => ['Administration', 'Dashboard'],
        'analytics' => ['Analytics', 'Sales'],
        'pos' => ['Point of Sale'],
        'customers' => ['Customers'],
        'auth' => ['Authentication']
    ];
}

/**
 * CART MANAGEMENT FUNCTIONS
 */

/**
 * Calculate cart totals from cart data
 *
 * @param array $cart Cart data array
 * @param float $tax_rate Tax rate percentage (default: 16.0)
 * @return array Cart totals (subtotal, tax, total, item_count)
 */
function calculateCartTotals($cart, $tax_rate = 16.0) {
    $subtotal = 0;
    $item_count = 0;
    
    if (empty($cart) || !is_array($cart)) {
        return [
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'item_count' => 0
        ];
    }
    
    foreach ($cart as $item) {
        if (isset($item['price']) && isset($item['quantity'])) {
            $subtotal += floatval($item['price']) * intval($item['quantity']);
            $item_count += intval($item['quantity']);
        }
    }
    
    $tax = $subtotal * ($tax_rate / 100);
    $total = $subtotal + $tax;
    
    return [
        'subtotal' => round($subtotal, 2),
        'tax' => round($tax, 2),
        'total' => round($total, 2),
        'item_count' => $item_count
    ];
}

/**
 * Get cart totals from active session
 *
 * @param float $tax_rate Tax rate percentage (default: 16.0)
 * @return array Cart totals (subtotal, tax, total, item_count)
 */
function getActiveCartTotals($tax_rate = 16.0) {
    $cart = $_SESSION['cart'] ?? [];
    return calculateCartTotals($cart, $tax_rate);
}

/**
 * Validate cart item data
 *
 * @param array $item Cart item data
 * @return array Validation result (valid, errors)
 */
function validateCartItem($item) {
    $errors = [];
    
    if (!isset($item['product_id']) || !$item['product_id']) {
        $errors[] = 'Product ID is required';
    }
    
    if (!isset($item['name']) || empty($item['name'])) {
        $errors[] = 'Product name is required';
    }
    
    if (!isset($item['price']) || !is_numeric($item['price']) || $item['price'] < 0) {
        $errors[] = 'Valid price is required';
    }
    
    if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] < 1) {
        $errors[] = 'Valid quantity is required';
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Format cart totals for display
 *
 * @param array $totals Cart totals array
 * @param string $currency_symbol Currency symbol (default: 'KES')
 * @return array Formatted totals for display
 */
function formatCartTotalsForDisplay($totals, $currency_symbol = 'KES') {
    return [
        'subtotal' => $currency_symbol . ' ' . number_format($totals['subtotal'], 2),
        'tax' => $currency_symbol . ' ' . number_format($totals['tax'], 2),
        'total' => $currency_symbol . ' ' . number_format($totals['total'], 2),
        'item_count' => $totals['item_count']
    ];
}

/**
 * Get sale items from database by sale ID
 *
 * @param PDO $conn Database connection
 * @param int $sale_id Sale ID
 * @return array Sale items with product details
 */
function getSaleItems($conn, $sale_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                si.*,
                p.name as product_name,
                p.sku as product_sku,
                p.image_url as product_image
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
            ORDER BY si.id ASC
        ");
        $stmt->execute([$sale_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get sale details with totals from database
 *
 * @param PDO $conn Database connection
 * @param int $sale_id Sale ID
 * @return array|null Sale details or null if not found
 */
function getSaleDetails($conn, $sale_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                s.*,
                u.username as cashier_name
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
        ");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($sale) {
            $sale['items'] = getSaleItems($conn, $sale_id);
        }
        
        return $sale;
    } catch (PDOException $e) {
        return null;
    }
}

?>
