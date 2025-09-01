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
function formatCurrency($amount, $currency = 'KES', $decimals = 2) {
    return $currency . ' ' . number_format($amount, $decimals);
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
            $settings[$row['setting_key']] = $row['setting_value'];
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

?>
