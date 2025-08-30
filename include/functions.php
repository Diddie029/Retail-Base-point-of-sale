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

?>
