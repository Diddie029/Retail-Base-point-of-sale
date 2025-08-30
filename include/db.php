<?php
$host = 'localhost';
$dbname = 'pos_system';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $conn->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $conn->exec("USE `$dbname`");
    
    // Create tables
    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('Admin', 'Cashier') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            category_id INT NOT NULL,
            sku VARCHAR(100) UNIQUE,
            product_type ENUM('physical', 'digital', 'service', 'subscription') DEFAULT 'physical',
            price DECIMAL(10, 2) NOT NULL,
            cost_price DECIMAL(10, 2) DEFAULT 0,
            quantity INT NOT NULL DEFAULT 0,
            minimum_stock INT DEFAULT 0,
            maximum_stock INT DEFAULT NULL,
            reorder_point INT DEFAULT 0,
            barcode VARCHAR(50) UNIQUE DEFAULT NULL,
            brand_id INT DEFAULT NULL COMMENT 'Brand ID reference',
            supplier_id INT DEFAULT NULL COMMENT 'Supplier ID reference',
            weight DECIMAL(8, 3) DEFAULT NULL COMMENT 'Weight in kg',
            length DECIMAL(8, 2) DEFAULT NULL COMMENT 'Length in cm',
            width DECIMAL(8, 2) DEFAULT NULL COMMENT 'Width in cm',
            height DECIMAL(8, 2) DEFAULT NULL COMMENT 'Height in cm',
            status ENUM('active', 'inactive', 'discontinued', 'blocked') DEFAULT 'active' COMMENT 'Product status including blocked state',
            tax_rate DECIMAL(5, 2) DEFAULT NULL COMMENT 'Product-specific tax rate percentage',
            image_url VARCHAR(500),
            tags TEXT COMMENT 'Comma-separated tags for search',
            warranty_period VARCHAR(50),
            is_serialized TINYINT(1) DEFAULT 0 COMMENT 'Whether product requires serial number tracking',
            allow_backorders TINYINT(1) DEFAULT 0,
            track_inventory TINYINT(1) DEFAULT 1,
            sale_price DECIMAL(10, 2) DEFAULT NULL COMMENT 'Sale price when on sale',
            sale_start_date DATETIME DEFAULT NULL COMMENT 'Sale start date',
            sale_end_date DATETIME DEFAULT NULL COMMENT 'Sale end date',
            block_reason TEXT COMMENT 'Reason why product is blocked (if status = blocked)',
            publication_status ENUM('draft', 'publish_now', 'scheduled') DEFAULT 'publish_now' COMMENT 'Publication status',
            scheduled_date DATETIME DEFAULT NULL COMMENT 'Scheduled publication date',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            INDEX idx_sku (sku),
            INDEX idx_product_type (product_type),
            INDEX idx_status (status),
            INDEX idx_brand_id (brand_id),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_barcode (barcode),
            INDEX idx_publication_status (publication_status),
            INDEX idx_block_reason (block_reason(100))
        )
    ");

    // Create product_images table for multiple images support
    $conn->exec("
        CREATE TABLE IF NOT EXISTS product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            image_url VARCHAR(500) NOT NULL,
            image_alt VARCHAR(255),
            sort_order INT DEFAULT 0,
            is_primary TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_product_id (product_id),
            INDEX idx_is_primary (is_primary)
        )
    ");

    // Create product_variants table for size/color variations
    $conn->exec("
        CREATE TABLE IF NOT EXISTS product_variants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            variant_name VARCHAR(100) NOT NULL COMMENT 'e.g., Size, Color, Style',
            variant_value VARCHAR(100) NOT NULL COMMENT 'e.g., Small, Red, Modern',
            sku_suffix VARCHAR(20) COMMENT 'Suffix added to base SKU',
            additional_price DECIMAL(10, 2) DEFAULT 0,
            additional_cost DECIMAL(10, 2) DEFAULT 0,
            quantity INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            UNIQUE KEY unique_variant (product_id, variant_name, variant_value),
            INDEX idx_product_id (product_id),
            INDEX idx_variant_name (variant_name)
        )
    ");

    // Create product_attributes table for custom attributes
    $conn->exec("
        CREATE TABLE IF NOT EXISTS product_attributes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            attribute_name VARCHAR(100) NOT NULL,
            attribute_value TEXT,
            is_filterable TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_product_id (product_id),
            INDEX idx_attribute_name (attribute_name)
        )
    ");

    // Create suppliers table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            payment_terms VARCHAR(100),
            notes TEXT,
            supplier_block_note TEXT COMMENT 'Required note when supplier is blocked/deactivated',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_is_active (is_active)
        )
    ");

    // Create brands table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS brands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            logo_url VARCHAR(500),
            website VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_active (is_active)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            customer_name VARCHAR(255) DEFAULT 'Walking Customer',
            customer_phone VARCHAR(20) DEFAULT '',
            customer_email VARCHAR(255) DEFAULT '',
            customer_address TEXT,
            customer_id_number VARCHAR(50) DEFAULT '',
            total_amount DECIMAL(10, 2) NOT NULL,
            discount DECIMAL(10, 2) DEFAULT 0,
            tax_amount DECIMAL(10, 2) DEFAULT 0,
            final_amount DECIMAL(10, 2) NOT NULL,
            payment_method VARCHAR(50) DEFAULT 'cash',
            notes TEXT,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            FOREIGN KEY (sale_id) REFERENCES sales(id),
            FOREIGN KEY (product_id) REFERENCES products(id)
        )
    ");
        // Create roles table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Create permissions table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Create role_permission table
    $conn->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        UNIQUE KEY role_permission (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )");
    
    // Create settings table
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL,
        setting_value TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY setting_key (setting_key)
    )");

    // Create signup_attempts table for rate limiting
    $conn->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip_address, created_at)
    )");

    // Create password_reset_attempts table for rate limiting
    $conn->exec("CREATE TABLE IF NOT EXISTS password_reset_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_time (email, created_at),
        INDEX idx_ip_time (ip_address, created_at)
    )");

    // Create activity_logs table for user activity tracking
    $conn->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at)
    )");

    // Create login_attempts table for security monitoring
    $conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(100) NOT NULL, -- username or email
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        attempt_type ENUM('username', 'email') DEFAULT 'email',
        success TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier_time (identifier, created_at),
        INDEX idx_ip_time (ip_address, created_at),
        INDEX idx_success (success)
    )");

    // Create inventory_orders table for purchase orders
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(50) NOT NULL UNIQUE,
        supplier_id INT NOT NULL,
        user_id INT NOT NULL,
        order_date DATE NOT NULL,
        expected_date DATE DEFAULT NULL,
        total_items INT DEFAULT 0,
        total_amount DECIMAL(10, 2) DEFAULT 0,
        status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        invoice_number VARCHAR(100) DEFAULT NULL,
        received_date DATE DEFAULT NULL,
        invoice_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_order_number (order_number),
        INDEX idx_supplier_id (supplier_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_order_date (order_date),
        INDEX idx_invoice_number (invoice_number)
    )");

    // Update existing inventory_orders table enum if needed
    try {
        $stmt = $conn->query("DESCRIBE inventory_orders status");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_enum = $result['Type'];

        // Check if the enum includes our new values
        if (strpos($current_enum, 'sent') === false || strpos($current_enum, 'waiting_for_delivery') === false || strpos($current_enum, 'received') === false || strpos($current_enum, 'cancelled') === false) {
            try {
                $conn->exec("ALTER TABLE inventory_orders MODIFY COLUMN status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending'");
                error_log("Updated inventory_orders status enum to include new values");
            } catch (PDOException $alterError) {
                error_log("Failed to alter status enum: " . $alterError->getMessage());
                // Try a different approach - set to a valid value first, then alter
                try {
                    $conn->exec("UPDATE inventory_orders SET status = 'pending' WHERE status NOT IN ('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled')");
                    $conn->exec("ALTER TABLE inventory_orders MODIFY COLUMN status ENUM('pending', 'sent', 'waiting_for_delivery', 'received', 'cancelled') DEFAULT 'pending'");
                    error_log("Successfully updated inventory_orders status enum after cleanup");
                } catch (PDOException $retryError) {
                    error_log("Retry also failed: " . $retryError->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet, that's okay
        error_log("Could not update inventory_orders enum: " . $e->getMessage());
    }

    // Add invoice-related columns to inventory_orders table if they don't exist
    try {
        $stmt = $conn->query("DESCRIBE inventory_orders");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('invoice_number', $columns)) {
            $conn->exec("ALTER TABLE inventory_orders ADD COLUMN invoice_number VARCHAR(100) DEFAULT NULL AFTER notes");
            error_log("Added invoice_number column to inventory_orders");
        }

        if (!in_array('received_date', $columns)) {
            $conn->exec("ALTER TABLE inventory_orders ADD COLUMN received_date DATE DEFAULT NULL AFTER invoice_number");
            error_log("Added received_date column to inventory_orders");
        }

        if (!in_array('invoice_notes', $columns)) {
            $conn->exec("ALTER TABLE inventory_orders ADD COLUMN invoice_notes TEXT AFTER received_date");
            error_log("Added invoice_notes column to inventory_orders");
        }

        if (!in_array('supplier_invoice_number', $columns)) {
            $conn->exec("ALTER TABLE inventory_orders ADD COLUMN supplier_invoice_number VARCHAR(100) AFTER invoice_notes");
            error_log("Added supplier_invoice_number column to inventory_orders");
        }

        // Add index for invoice_number if it doesn't exist
        try {
            $conn->exec("ALTER TABLE inventory_orders ADD INDEX idx_invoice_number (invoice_number)");
        } catch (PDOException $e) {
            // Index might already exist, that's okay
        }

    } catch (PDOException $e) {
        // Table might not exist yet, that's okay
        error_log("Could not add invoice columns to inventory_orders: " . $e->getMessage());
    }

    // Create inventory_order_items table for order line items
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        cost_price DECIMAL(10, 2) NOT NULL,
        total_amount DECIMAL(10, 2) NOT NULL,
        received_quantity INT DEFAULT 0,
        status ENUM('pending', 'received', 'cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES inventory_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        INDEX idx_order_id (order_id),
        INDEX idx_product_id (product_id),
        INDEX idx_status (status)
    )");

    // Add account lockout fields to users table
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'account_locked'");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result) {
        $conn->exec("ALTER TABLE users ADD COLUMN account_locked TINYINT(1) DEFAULT 0");
        $conn->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL");
        $conn->exec("ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0");
        $conn->exec("ALTER TABLE users ADD COLUMN last_failed_login DATETIME DEFAULT NULL");
        $conn->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
        $conn->exec("ALTER TABLE users ADD COLUMN login_count INT DEFAULT 0");
    }
    
    // Add role_id to users table if it doesn't exist
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'role_id'");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result) {
        $conn->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL");
        $conn->exec("ALTER TABLE users ADD FOREIGN KEY (role_id) REFERENCES roles(id)");
    }

    // Add verification and OTP fields to users table
    $verificationFields = [
        'email_verified' => "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0",
        'verification_token' => "ALTER TABLE users ADD COLUMN verification_token VARCHAR(255) DEFAULT NULL",
        'verification_token_expiry' => "ALTER TABLE users ADD COLUMN verification_token_expiry DATETIME DEFAULT NULL",
        'otp_code' => "ALTER TABLE users ADD COLUMN otp_code VARCHAR(6) DEFAULT NULL",
        'otp_expiry' => "ALTER TABLE users ADD COLUMN otp_expiry DATETIME DEFAULT NULL",
        'reset_token' => "ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) DEFAULT NULL",
        'reset_token_expiry' => "ALTER TABLE users ADD COLUMN reset_token_expiry DATETIME DEFAULT NULL"
    ];

    foreach ($verificationFields as $field => $sql) {
        $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE '$field'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec($sql);
        }
    }
    
    // Insert default roles
    $conn->exec("INSERT IGNORE INTO roles (id, name, description) VALUES (1, 'Admin', 'Full access to the system')");
    $conn->exec("INSERT IGNORE INTO roles (id, name, description) VALUES (2, 'Cashier', 'Limited access for cashier operations')");
    
    // Insert default permissions
    $permissions = [
        ['view_dashboard', 'View dashboard'],
        ['manage_categories', 'Add, edit, delete categories'],
        ['manage_products', 'Add, edit, delete products'],
        ['manage_sales', 'View sales history and details'],
        ['process_sales', 'Process sales transactions'],
        ['manage_users', 'Add, edit, delete users'],
        ['manage_roles', 'Add, edit, delete roles and assign permissions']
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description) VALUES (:name, :description)");
    foreach ($permissions as $permission) {
        $stmt->bindParam(':name', $permission[0]);
        $stmt->bindParam(':description', $permission[1]);
        $stmt->execute();
    }
    
    // Clear existing role permissions for default roles to avoid conflicts
    $conn->exec("DELETE FROM role_permissions WHERE role_id IN (1, 2)");
    
    // Assign permissions to Admin role (all permissions)
    $admin_role_id = 1;
    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) 
                            SELECT :role_id, id FROM permissions");
    $stmt->bindParam(':role_id', $admin_role_id);
    $stmt->execute();
    
    // Assign permissions to Cashier role (limited permissions)
    $cashier_role_id = 2;
    $cashier_permissions = ['view_dashboard', 'manage_sales', 'process_sales'];
    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) 
                            SELECT :role_id, id FROM permissions WHERE name IN ('" . implode("','", $cashier_permissions) . "')");
    $stmt->bindParam(':role_id', $cashier_role_id);
    $stmt->execute();
        // Update existing users to have role_id
    $conn->exec("UPDATE users SET role_id = 1 WHERE role = 'Admin' AND (role_id IS NULL OR role_id = 0)");
    $conn->exec("UPDATE users SET role_id = 2 WHERE role = 'Cashier' AND (role_id IS NULL OR role_id = 0)");
    
    // Add manage_settings permission
    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description) VALUES ('manage_settings', 'Manage system settings')");
    $stmt->execute();
    
    // Add manage_settings permission to admin role
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 1, id FROM permissions WHERE name = 'manage_settings'");
    $stmt->execute();

    // Add supplier_block_note field to suppliers table if it doesn't exist
    $stmt = $conn->prepare("SHOW COLUMNS FROM suppliers LIKE 'supplier_block_note'");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result) {
        $conn->exec("ALTER TABLE suppliers ADD COLUMN supplier_block_note TEXT COMMENT 'Required note when supplier is blocked/deactivated'");
    }

    // Add new product fields to existing products table
    $productFields = [
        'description' => "ALTER TABLE products ADD COLUMN description TEXT AFTER name",
        'sku' => "ALTER TABLE products ADD COLUMN sku VARCHAR(100) UNIQUE AFTER category_id",
        'product_type' => "ALTER TABLE products ADD COLUMN product_type ENUM('physical', 'digital', 'service', 'subscription') DEFAULT 'physical' AFTER sku",
        'cost_price' => "ALTER TABLE products ADD COLUMN cost_price DECIMAL(10, 2) DEFAULT 0 AFTER price",
        'minimum_stock' => "ALTER TABLE products ADD COLUMN minimum_stock INT DEFAULT 0 AFTER quantity",
        'maximum_stock' => "ALTER TABLE products ADD COLUMN maximum_stock INT DEFAULT NULL AFTER minimum_stock",
        'reorder_point' => "ALTER TABLE products ADD COLUMN reorder_point INT DEFAULT 0 AFTER maximum_stock",
        'brand_id' => "ALTER TABLE products ADD COLUMN brand_id INT DEFAULT NULL COMMENT 'Brand ID reference' AFTER sale_end_date",
        'supplier_id' => "ALTER TABLE products ADD COLUMN supplier_id INT DEFAULT NULL COMMENT 'Supplier ID reference' AFTER brand_id",
        'weight' => "ALTER TABLE products ADD COLUMN weight DECIMAL(8, 3) DEFAULT NULL COMMENT 'Weight in kg' AFTER supplier_id",
        'length' => "ALTER TABLE products ADD COLUMN length DECIMAL(8, 2) DEFAULT NULL COMMENT 'Length in cm' AFTER weight",
        'width' => "ALTER TABLE products ADD COLUMN width DECIMAL(8, 2) DEFAULT NULL COMMENT 'Width in cm' AFTER length",
        'height' => "ALTER TABLE products ADD COLUMN height DECIMAL(8, 2) DEFAULT NULL COMMENT 'Height in cm' AFTER width",
        'status' => "ALTER TABLE products ADD COLUMN status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active' AFTER height",
        'tax_rate' => "ALTER TABLE products ADD COLUMN tax_rate DECIMAL(5, 2) DEFAULT NULL COMMENT 'Product-specific tax rate percentage' AFTER status",
        'image_url' => "ALTER TABLE products ADD COLUMN image_url VARCHAR(500) AFTER tax_rate",
        'tags' => "ALTER TABLE products ADD COLUMN tags TEXT COMMENT 'Comma-separated tags for search' AFTER image_url",
        'warranty_period' => "ALTER TABLE products ADD COLUMN warranty_period VARCHAR(50) AFTER tags",
        'is_serialized' => "ALTER TABLE products ADD COLUMN is_serialized TINYINT(1) DEFAULT 0 COMMENT 'Whether product requires serial number tracking' AFTER warranty_period",
        'allow_backorders' => "ALTER TABLE products ADD COLUMN allow_backorders TINYINT(1) DEFAULT 0 AFTER is_serialized",
        'track_inventory' => "ALTER TABLE products ADD COLUMN track_inventory TINYINT(1) DEFAULT 1 AFTER allow_backorders",
        'sale_price' => "ALTER TABLE products ADD COLUMN sale_price DECIMAL(10, 2) DEFAULT NULL COMMENT 'Sale price when on sale' AFTER track_inventory",
        'sale_start_date' => "ALTER TABLE products ADD COLUMN sale_start_date DATETIME DEFAULT NULL COMMENT 'Sale start date' AFTER sale_price",
        'sale_end_date' => "ALTER TABLE products ADD COLUMN sale_end_date DATETIME DEFAULT NULL COMMENT 'Sale end date' AFTER sale_start_date",

        'publication_status' => "ALTER TABLE products ADD COLUMN publication_status ENUM('draft', 'publish_now', 'scheduled') DEFAULT 'publish_now' COMMENT 'Publication status' AFTER supplier_id",
        'scheduled_date' => "ALTER TABLE products ADD COLUMN scheduled_date DATETIME DEFAULT NULL COMMENT 'Scheduled publication date' AFTER publication_status"
    ];

    foreach ($productFields as $field => $sql) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE '$field'");
            $stmt->execute();
            $result = $stmt->fetch();
            if (!$result) {
                $conn->exec($sql);
            }
        } catch (PDOException $e) {
            // Column might already exist or there's an error, continue
            continue;
        }
    }

    // Add indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_sku ON products (sku)",
        "CREATE INDEX IF NOT EXISTS idx_product_type ON products (product_type)",
        "CREATE INDEX IF NOT EXISTS idx_status ON products (status)",
        "CREATE INDEX IF NOT EXISTS idx_brand ON products (brand)",
        "CREATE INDEX IF NOT EXISTS idx_brand_id ON products (brand_id)",
        "CREATE INDEX IF NOT EXISTS idx_supplier_id ON products (supplier_id)"
    ];

    foreach ($indexes as $indexSql) {
        try {
            $conn->exec($indexSql);
        } catch (PDOException $e) {
            // Index might already exist, continue
            continue;
        }
    }

    // Add foreign key constraints for brand_id and supplier_id
    $foreignKeys = [
        "ALTER TABLE products ADD CONSTRAINT fk_products_brand_id FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL",
        "ALTER TABLE products ADD CONSTRAINT fk_products_supplier_id FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL"
    ];

    foreach ($foreignKeys as $fkSql) {
        try {
            $conn->exec($fkSql);
        } catch (PDOException $e) {
            // Foreign key might already exist, continue
            continue;
        }
    }
    
    // Insert default settings
    $default_settings = [
        ['company_name', 'POS System'],
        ['company_address', ''],
        ['company_phone', ''],
        ['company_email', ''],
        ['company_website', ''],
        ['company_logo', ''],
        ['currency_symbol', 'KES'],
        ['currency_position', 'before'],
        ['currency_decimal_places', '2'],
        ['tax_rate', '0'],
        ['tax_name', 'VAT'],
        ['tax_registration_number', ''],
        ['receipt_header', 'POS SYSTEM'],
        ['receipt_contact', 'Contact: [Configure in Settings]'],
        ['receipt_show_tax', '1'],
        ['receipt_show_discount', '1'],
        ['receipt_footer', 'Thank you for your purchase!'],
        ['receipt_thanks_message', 'Please come again.'],
        ['receipt_width', '80'],
        ['receipt_font_size', '12'],
        ['auto_print_receipt', '0'],
        ['theme_color', '#6366f1'],
        ['sidebar_color', '#1e293b'],
        ['timezone', 'Africa/Nairobi'],
        ['date_format', 'Y-m-d'],
        ['time_format', 'H:i:s'],
        ['low_stock_threshold', '10'],
        ['backup_frequency', 'daily'],
        ['backup_retention_count', '10'],
        ['enable_sound', '1'],
        ['default_payment_method', 'cash'],
        ['allow_negative_stock', '0'],
        ['barcode_type', 'CODE128'],
        ['smtp_host', 'smtp.gmail.com'],
        ['smtp_port', '587'],
        ['smtp_username', ''],
        ['smtp_password', ''],
        ['smtp_encryption', 'tls'],
        ['smtp_from_email', ''],
        ['smtp_from_name', 'POS System'],
        ['sku_prefix', 'LIZ'],
        ['sku_format', 'SKU000001'],
        ['sku_length', '6'],
        ['sku_separator', ''],
        ['auto_generate_sku', '1'],
        // Order creation settings
        ['auto_generate_order_number', '1'],
        ['order_number_prefix', 'PO'],
        ['order_number_format', 'prefix-date-number'],
        ['order_number_length', '6'],
        ['order_number_separator', '-'],
        ['default_order_currency', 'KES'],
        ['order_approval_required', '0'],
        ['order_notification_email', ''],
        ['order_auto_approval', '1'],
        ['order_reminder_days', '3'],
        ['order_expiry_days', '30'],
        ['order_auto_approve', '1'],
        ['order_require_approval', '0'],
        ['order_notification_sms', '0'],
        ['order_notification_email', '1'],
        ['order_show_cost_price', '1'],
        ['order_show_profit_margin', '0'],
        ['order_allow_partial_receipt', '1'],
        ['order_auto_close_days', '90']
    ];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (:key, :value)");
    foreach ($default_settings as $setting) {
        $stmt->bindParam(':key', $setting[0]);
        $stmt->bindParam(':value', $setting[1]);
        $stmt->execute();
    }
    
    // Check if default categories exist
    $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($count == 0) {
        // Insert default categories
        $categories = [
            ['Electronics', 'Electronic devices and accessories'],
            ['Clothing', 'Apparel and fashion items'],
            ['Food & Beverages', 'Food items and drinks'],
            ['Home & Kitchen', 'Household items and kitchenware'],
            ['Beauty & Health', 'Cosmetics and health products']
        ];
        
        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
        
        foreach ($categories as $category) {
            $stmt->bindParam(':name', $category[0]);
            $stmt->bindParam(':description', $category[1]);
            $stmt->execute();
        }
        
        // Default categories created silently
    }
    
    // Database initialized silently
    // Store connection status for login page
    $GLOBALS['db_connected'] = true;
    
    // Add helper functions for error handling and validation
    if (!function_exists('validateOrderCreation')) {
        function validateOrderCreation($conn, $supplier_id = null, $product_ids = []) {
            $errors = [];
            $warnings = [];
            
            // Check if supplier exists and is active
            if ($supplier_id) {
                $stmt = $conn->prepare("SELECT id, name, is_active, supplier_block_note FROM suppliers WHERE id = ?");
                $stmt->execute([$supplier_id]);
                $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$supplier) {
                    $errors[] = "Supplier not found (ID: $supplier_id)";
                } elseif (!$supplier['is_active']) {
                    $errors[] = "Supplier '{$supplier['name']}' is inactive";
                    if ($supplier['supplier_block_note']) {
                        $warnings[] = "Block reason: {$supplier['supplier_block_note']}";
                    }
                }
            }
            
            // Check products if provided
            if (!empty($product_ids)) {
                $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
                $stmt = $conn->prepare("
                    SELECT p.id, p.name, p.status, p.block_reason, p.supplier_id, 
                           s.name as supplier_name, s.is_active as supplier_active
                    FROM products p
                    LEFT JOIN suppliers s ON p.supplier_id = s.id
                    WHERE p.id IN ($placeholders)
                ");
                $stmt->execute($product_ids);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($products as $product) {
                    if ($product['status'] === 'blocked') {
                        $errors[] = "Product '{$product['name']}' is blocked";
                        if ($product['block_reason']) {
                            $warnings[] = "Block reason: {$product['block_reason']}";
                        }
                    } elseif ($product['status'] === 'inactive') {
                        $errors[] = "Product '{$product['name']}' is inactive";
                    }
                    
                    if ($product['supplier_id'] && !$product['supplier_active']) {
                        $errors[] = "Product '{$product['name']}' is assigned to inactive supplier '{$product['supplier_name']}'";
                    }
                }
            }
            
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings
            ];
        }
    }
    
    if (!function_exists('getOrderCreationStatus')) {
        function getOrderCreationStatus($conn) {
            $status = [
                'ready' => false,
                'issues' => [],
                'warnings' => [],
                'supplier_count' => 0,
                'product_count' => 0,
                'category_count' => 0
            ];
            
            try {
                // Count active suppliers
                $stmt = $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE is_active = 1");
                $status['supplier_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($status['supplier_count'] == 0) {
                    $status['issues'][] = "No active suppliers found. Add at least one active supplier to create orders.";
                }
                
                // Count products with suppliers
                $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE supplier_id IS NOT NULL AND status = 'active'");
                $status['product_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($status['product_count'] == 0) {
                    $status['issues'][] = "No products assigned to suppliers. Assign products to suppliers to create orders.";
                }
                
                // Count categories
                $stmt = $conn->query("SELECT COUNT(*) as count FROM categories");
                $status['category_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($status['category_count'] == 0) {
                    $status['issues'][] = "No product categories found. Add at least one category.";
                }
                
                // Check for blocked/inactive products
                $stmt = $conn->query("
                    SELECT COUNT(*) as count FROM products p
                    JOIN suppliers s ON p.supplier_id = s.id
                    WHERE p.status IN ('blocked', 'inactive') OR s.is_active = 0
                ");
                $blocked_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($blocked_count > 0) {
                    $status['warnings'][] = "$blocked_count products are blocked, inactive, or assigned to inactive suppliers.";
                }
                
                $status['ready'] = empty($status['issues']);
                
            } catch (PDOException $e) {
                $status['issues'][] = "Database error: " . $e->getMessage();
            }
            
            return $status;
        }
    }
    
} catch(PDOException $e) {
    // Store connection error for login page
    $GLOBALS['db_connected'] = false;
    $GLOBALS['db_error'] = $e->getMessage();
}
?>