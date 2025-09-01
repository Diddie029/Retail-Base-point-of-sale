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

    // Create returns table for product returns
    $conn->exec("CREATE TABLE IF NOT EXISTS returns (
        id INT PRIMARY KEY AUTO_INCREMENT,
        return_number VARCHAR(50) UNIQUE NOT NULL,
        supplier_id INT NOT NULL,
        user_id INT NOT NULL,
        return_reason ENUM('defective', 'wrong_item', 'damaged', 'expired', 'overstock', 'quality', 'other') NOT NULL,
        return_notes TEXT,
        total_items INT NOT NULL DEFAULT 0,
        total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed') NOT NULL DEFAULT 'pending',
        shipping_carrier VARCHAR(100),
        tracking_number VARCHAR(100),
        approved_by INT,
        approved_at DATETIME,
        shipped_at DATETIME,
        completed_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_return_number (return_number),
        INDEX idx_supplier_id (supplier_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at)
    )");

    // Create return_items table for return line items
    $conn->exec("CREATE TABLE IF NOT EXISTS return_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        cost_price DECIMAL(10, 2) NOT NULL,
        return_reason VARCHAR(255),
        notes TEXT,
        condition_status ENUM('new', 'used', 'damaged', 'defective') DEFAULT 'new',
        accepted_quantity INT DEFAULT 0 COMMENT 'Quantity of items accepted for return (0 = none, NULL = not processed)',
        action_taken ENUM('pending', 'accepted', 'partial_accept', 'rejected', 'exchange', 'refund') DEFAULT 'pending' COMMENT 'Action taken on this return item',
        action_notes TEXT COMMENT 'Notes about the action taken on this item',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this item was last updated',
        FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        INDEX idx_return_id (return_id),
        INDEX idx_product_id (product_id),
        INDEX idx_action_taken (action_taken),
        INDEX idx_accepted_quantity (accepted_quantity),
        INDEX idx_updated_at (updated_at)
    )");

    // Create return_attachments table for file attachments
    $conn->exec("CREATE TABLE IF NOT EXISTS return_attachments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        return_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_return_id (return_id)
    )");

    // Create return_status_history table for status tracking
    $conn->exec("CREATE TABLE IF NOT EXISTS return_status_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        return_id INT NOT NULL,
        old_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed'),
        new_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed') NOT NULL,
        changed_by INT NOT NULL,
        change_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_return_id (return_id),
        INDEX idx_changed_by (changed_by),
        INDEX idx_created_at (created_at)
    )");

    // Create supplier performance tracking tables
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_performance_metrics (
            id INT PRIMARY KEY AUTO_INCREMENT,
            supplier_id INT NOT NULL,
            metric_date DATE NOT NULL,
            total_orders INT DEFAULT 0,
            on_time_deliveries INT DEFAULT 0,
            late_deliveries INT DEFAULT 0,
            average_delivery_days DECIMAL(5,2) DEFAULT 0,
            total_returns INT DEFAULT 0,
            quality_score DECIMAL(5,2) DEFAULT 100,
            total_order_value DECIMAL(10,2) DEFAULT 0,
            average_cost_per_unit DECIMAL(8,2) DEFAULT 0,
            total_return_value DECIMAL(10,2) DEFAULT 0,
            return_rate DECIMAL(5,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            UNIQUE KEY unique_supplier_date (supplier_id, metric_date),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_metric_date (metric_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create supplier_quality_issues table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_quality_issues (
            id INT PRIMARY KEY AUTO_INCREMENT,
            supplier_id INT NOT NULL,
            issue_type ENUM('defective', 'wrong_item', 'damaged', 'expired', 'quality', 'other') NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            description TEXT,
            product_id INT,
            order_id INT,
            return_id INT,
            reported_date DATE NOT NULL,
            resolved TINYINT(1) DEFAULT 0,
            resolved_date DATE,
            resolution_notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_issue_type (issue_type),
            INDEX idx_reported_date (reported_date),
            INDEX idx_resolved (resolved)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create supplier_cost_history table for price tracking
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_cost_history (
            id INT PRIMARY KEY AUTO_INCREMENT,
            supplier_id INT NOT NULL,
            product_id INT NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL,
            effective_date DATE NOT NULL,
            order_id INT,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (order_id) REFERENCES inventory_orders(id) ON DELETE SET NULL,
            INDEX idx_supplier_product (supplier_id, product_id),
            INDEX idx_effective_date (effective_date),
            INDEX idx_cost_price (cost_price)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create expiry tracker tables with improved error handling and foreign key management
    try {
        // Create expiry_categories table first (referenced by others)
        $conn->exec("
            CREATE TABLE IF NOT EXISTS expiry_categories (
                id INT PRIMARY KEY AUTO_INCREMENT,
                category_name VARCHAR(100) NOT NULL UNIQUE,
                alert_threshold_days INT DEFAULT 30,
                color_code VARCHAR(7) DEFAULT '#ff6b6b' COMMENT 'Hex color for UI display',
                description TEXT,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert default expiry categories
        $expiry_categories = [
            ['Perishable Foods', 7, '#ff6b6b', 'Foods that spoil quickly (dairy, meat, etc.)'],
            ['Medications', 90, '#4ecdc4', 'Pharmaceutical products and medicines'],
            ['Cosmetics', 365, '#45b7d1', 'Beauty and personal care products'],
            ['Electronics', 730, '#96ceb4', 'Electronic devices and components'],
            ['Chemicals', 180, '#feca57', 'Cleaning supplies and chemicals'],
            ['General', 30, '#ff9ff3', 'General products with standard expiry']
        ];

        $stmt = $conn->prepare("INSERT IGNORE INTO expiry_categories (category_name, alert_threshold_days, color_code, description) VALUES (?, ?, ?, ?)");
        foreach ($expiry_categories as $category) {
            $stmt->execute($category);
        }

        // Create product_expiry_dates table - tracks expiry dates for products
        $conn->exec("
            CREATE TABLE IF NOT EXISTS product_expiry_dates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                batch_number VARCHAR(100),
                expiry_date DATE NOT NULL,
                manufacturing_date DATE,
                quantity INT NOT NULL DEFAULT 0,
                remaining_quantity INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(10,2) DEFAULT 0,
                location VARCHAR(255),
                supplier_id INT,
                purchase_order_id INT,
                alert_days_before INT DEFAULT 30 COMMENT 'Days before expiry to send alert',
                alert_sent TINYINT(1) DEFAULT 0,
                alert_sent_date DATETIME,
                status ENUM('active', 'expired', 'disposed', 'returned') DEFAULT 'active',
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_expiry_date (expiry_date),
                INDEX idx_batch_number (batch_number),
                INDEX idx_status (status),
                INDEX idx_alert_sent (alert_sent),
                INDEX idx_supplier_id (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create expiry_actions table - tracks actions taken on expired items
        $conn->exec("
            CREATE TABLE IF NOT EXISTS expiry_actions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_expiry_id INT NOT NULL,
                action_type ENUM('dispose', 'return', 'sell_at_discount', 'donate', 'recall', 'other') NOT NULL,
                action_date DATETIME NOT NULL,
                quantity_affected INT NOT NULL,
                user_id INT NOT NULL,
                reason TEXT,
                cost DECIMAL(10,2) DEFAULT 0,
                revenue DECIMAL(10,2) DEFAULT 0,
                disposal_method VARCHAR(255),
                return_reference VARCHAR(100),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_expiry_id (product_expiry_id),
                INDEX idx_action_type (action_type),
                INDEX idx_action_date (action_date),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create expiry_alerts table - for notification settings and history
        $conn->exec("
            CREATE TABLE IF NOT EXISTS expiry_alerts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_expiry_id INT NOT NULL,
                alert_type ENUM('email', 'sms', 'dashboard', 'system') NOT NULL,
                alert_days_before INT NOT NULL,
                alert_date DATETIME NOT NULL,
                recipient_user_id INT,
                recipient_email VARCHAR(255),
                recipient_phone VARCHAR(20),
                alert_message TEXT,
                sent_status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
                sent_at DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_expiry_id (product_expiry_id),
                INDEX idx_alert_type (alert_type),
                INDEX idx_alert_date (alert_date),
                INDEX idx_sent_status (sent_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create expiry_alert_settings table - user preferences for alerts
        $conn->exec("
            CREATE TABLE IF NOT EXISTS expiry_alert_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                alert_days_before INT DEFAULT 30,
                alert_types VARCHAR(255) DEFAULT 'email,dashboard' COMMENT 'Comma-separated alert types',
                enable_email_alerts TINYINT(1) DEFAULT 1,
                enable_sms_alerts TINYINT(1) DEFAULT 0,
                enable_dashboard_alerts TINYINT(1) DEFAULT 1,
                enable_system_alerts TINYINT(1) DEFAULT 1,
                email_frequency ENUM('immediate', 'daily', 'weekly') DEFAULT 'daily',
                sms_frequency ENUM('immediate', 'daily', 'weekly') DEFAULT 'immediate',
                last_email_sent DATETIME,
                last_sms_sent DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_settings (user_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Now add foreign key constraints (after all tables are created)
        try {
            // Add foreign key for product_expiry_dates.product_id
            $conn->exec("ALTER TABLE product_expiry_dates ADD CONSTRAINT fk_product_expiry_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for product_expiry_dates.supplier_id
            $conn->exec("ALTER TABLE product_expiry_dates ADD CONSTRAINT fk_product_expiry_supplier_id FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for expiry_actions.product_expiry_id
            $conn->exec("ALTER TABLE expiry_actions ADD CONSTRAINT fk_expiry_actions_product_expiry_id FOREIGN KEY (product_expiry_id) REFERENCES product_expiry_dates(id) ON DELETE CASCADE");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for expiry_actions.user_id
            $conn->exec("ALTER TABLE expiry_actions ADD CONSTRAINT fk_expiry_actions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for expiry_alerts.product_expiry_id
            $conn->exec("ALTER TABLE expiry_alerts ADD CONSTRAINT fk_expiry_alerts_product_expiry_id FOREIGN KEY (product_expiry_id) REFERENCES product_expiry_dates(id) ON DELETE CASCADE");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for expiry_alerts.recipient_user_id
            $conn->exec("ALTER TABLE expiry_alerts ADD CONSTRAINT fk_expiry_alerts_recipient_user_id FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        try {
            // Add foreign key for expiry_alert_settings.user_id
            $conn->exec("ALTER TABLE expiry_alert_settings ADD CONSTRAINT fk_expiry_alert_settings_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        // Add expiry_category_id to products table if it doesn't exist
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'expiry_category_id'");
            $stmt->execute();
            $result = $stmt->fetch();

            if (!$result) {
                $conn->exec("ALTER TABLE products ADD COLUMN expiry_category_id INT DEFAULT NULL AFTER category_id");
                $conn->exec("ALTER TABLE products ADD FOREIGN KEY (expiry_category_id) REFERENCES expiry_categories(id) ON DELETE SET NULL");
                $conn->exec("CREATE INDEX idx_expiry_category_id ON products (expiry_category_id)");
            }
        } catch (PDOException $e) {
            // Column might already exist, continue silently
        }

        // Add expiry tracker permissions
        $expiry_permissions = [
            ['manage_expiry_tracker', 'Manage expiry tracker system'],
            ['view_expiry_alerts', 'View expiry alerts and notifications'],
            ['handle_expired_items', 'Handle expired items and take actions'],
            ['configure_expiry_alerts', 'Configure expiry alert settings']
        ];

        $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description) VALUES (?, ?)");
        foreach ($expiry_permissions as $permission) {
            $stmt->execute($permission);
        }

        // Assign expiry permissions to Admin role
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                               SELECT 1, id FROM permissions WHERE name IN ('manage_expiry_tracker', 'view_expiry_alerts', 'handle_expired_items', 'configure_expiry_alerts')");
        $stmt->execute();

        // Assign limited expiry permissions to Cashier role
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                               SELECT 2, id FROM permissions WHERE name IN ('view_expiry_alerts')");
        $stmt->execute();

    } catch (PDOException $e) {
        // Log expiry tracker setup error but don't fail the entire database initialization
        error_log("Warning: Expiry tracker setup failed: " . $e->getMessage());
    }

    // Create return_reasons table for predefined reasons
    $conn->exec("CREATE TABLE IF NOT EXISTS return_reasons (
        id INT PRIMARY KEY AUTO_INCREMENT,
        code VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code (code),
        INDEX idx_is_active (is_active)
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

    // Update returns table status enum if needed (for new 'processed' status)
    try {
        $stmt = $conn->query("DESCRIBE returns status");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_enum = $result['Type'];

        // Check if the enum includes our new 'processed' value
        if (strpos($current_enum, 'processed') === false) {
            try {
                $conn->exec("ALTER TABLE returns MODIFY COLUMN status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled', 'processed') DEFAULT 'pending'");
                error_log("Updated returns status enum to include 'processed' status");
            } catch (PDOException $alterError) {
                error_log("Failed to alter returns status enum: " . $alterError->getMessage());
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet, that's okay
        error_log("Could not update returns status enum: " . $e->getMessage());
    }

    // Update return_items table with new columns if they don't exist
    try {
        $stmt = $conn->query("DESCRIBE return_items");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('accepted_quantity', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN accepted_quantity INT DEFAULT 0 COMMENT 'Quantity of items accepted for return (0 = none, NULL = not processed)'");
            error_log("Added accepted_quantity column to return_items");
        }

        if (!in_array('action_taken', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN action_taken ENUM('pending', 'accepted', 'partial_accept', 'rejected', 'exchange', 'refund') DEFAULT 'pending' COMMENT 'Action taken on this return item'");
            error_log("Added action_taken column to return_items");
        }

        if (!in_array('action_notes', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN action_notes TEXT COMMENT 'Notes about the action taken on this item'");
            error_log("Added action_notes column to return_items");
        }

        if (!in_array('updated_at', $columns)) {
            $conn->exec("ALTER TABLE return_items ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this item was last updated'");
            error_log("Added updated_at column to return_items");
        }

        // Add indexes if they don't exist
        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_action_taken (action_taken)");
        } catch (PDOException $e) {
            // Index might already exist, that's okay
        }

        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_accepted_quantity (accepted_quantity)");
        } catch (PDOException $e) {
            // Index might already exist, that's okay
        }

        try {
            $conn->exec("ALTER TABLE return_items ADD INDEX idx_updated_at (updated_at)");
        } catch (PDOException $e) {
            // Index might already exist, that's okay
        }

        // Update existing records to have default values
        $stmt = $conn->prepare("UPDATE return_items SET action_taken = 'pending', accepted_quantity = 0 WHERE action_taken IS NULL");
        $stmt->execute();
        $affected_rows = $stmt->rowCount();
        if ($affected_rows > 0) {
            error_log("Updated $affected_rows existing return_items records with default values");
        }

    } catch (PDOException $e) {
        // Table might not exist yet, that's okay
        error_log("Could not update return_items table: " . $e->getMessage());
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
        ['manage_roles', 'Add, edit, delete roles and assign permissions'],
        ['manage_inventory', 'Manage inventory and orders'],
        ['manage_returns', 'Create and manage product returns'],
        ['approve_returns', 'Approve product returns'],
        ['view_returns', 'View return history and details'],
        ['manage_suppliers', 'Add, edit, delete suppliers'],
        ['view_supplier_performance', 'View supplier performance metrics'],
        ['manage_supplier_performance', 'Manage supplier performance tracking']
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
    $cashier_permissions = ['view_dashboard', 'manage_sales', 'process_sales', 'view_returns', 'view_supplier_performance'];
    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id)
                            SELECT :role_id, id FROM permissions WHERE name IN ('" . implode("','", $cashier_permissions) . "')");
    $stmt->bindParam(':role_id', $cashier_role_id);
    $stmt->execute();

    // Insert default return reasons
    $return_reasons = [
        ['defective', 'Defective Products', 'Products that are damaged or not working properly'],
        ['wrong_item', 'Wrong Items Received', 'Received different products than ordered'],
        ['damaged', 'Damaged in Transit', 'Products damaged during shipping'],
        ['expired', 'Expired Products', 'Products that have passed their expiration date'],
        ['overstock', 'Overstock/Excess Inventory', 'Too much inventory, need to return excess'],
        ['quality', 'Quality Issues', 'Products do not meet quality standards'],
        ['other', 'Other', 'Other reasons not listed above']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO return_reasons (code, name, description) VALUES (?, ?, ?)");
    foreach ($return_reasons as $reason) {
        $stmt->execute($reason);
    }
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
        ['order_auto_close_days', '90'],
        // Return settings
        ['return_number_prefix', 'RTN'],
        ['return_number_length', '6'],
        ['return_number_separator', '-'],
        ['return_auto_approval', '0'],
        ['return_approval_required', '1'],
        ['return_notification_email', ''],
        ['return_allow_attachments', '1'],
        ['return_max_attachment_size', '5242880'],
        ['return_allowed_file_types', 'jpg,jpeg,png,pdf,doc,docx'],
        ['return_auto_update_inventory', '1'],

        // Add expiry tracker settings
        ['expiry_alert_enabled', '1'],
        ['expiry_default_alert_days', '30'],
        ['expiry_email_template', 'Product {product_name} (Batch: {batch_number}) will expire on {expiry_date}. Please take necessary action.'],
        ['expiry_sms_template', '{product_name} expires {expiry_date}. Action required.'],
        ['expiry_auto_disposal', '0'],
        ['expiry_disposal_method', 'incineration'],
        ['expiry_tracker_enabled', '1']
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

    // Populate supplier performance data if suppliers exist
    $supplier_count = $conn->query("SELECT COUNT(*) as count FROM suppliers")->fetch(PDO::FETCH_ASSOC)['count'];
    if ($supplier_count > 0) {
        try {
            // Get existing suppliers
            $stmt = $conn->query("SELECT id, name FROM suppliers ORDER BY id");
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Silent population - no console output
            foreach ($suppliers as $supplier) {
                // Generate sample performance data for different periods
                $periods = ['30days', '90days', '1year'];
                $baseDate = date('Y-m-d');

                foreach ($periods as $period) {
                    // Calculate date range and realistic metrics
                    switch ($period) {
                        case '30days':
                            $orders = rand(3, 8);
                            $onTimeRate = rand(85, 98);
                            $qualityScore = rand(88, 97);
                            break;
                        case '90days':
                            $orders = rand(8, 15);
                            $onTimeRate = rand(82, 95);
                            $qualityScore = rand(85, 94);
                            break;
                        case '1year':
                            $orders = rand(25, 50);
                            $onTimeRate = rand(78, 92);
                            $qualityScore = rand(80, 91);
                            break;
                    }

                    $onTimeDeliveries = round($orders * $onTimeRate / 100);
                    $lateDeliveries = $orders - $onTimeDeliveries;
                    $avgDeliveryDays = rand(5, 12) + (rand(0, 9) / 10);
                    $returns = rand(0, max(1, round($orders * 0.05)));
                    $totalOrderValue = $orders * rand(5000, 15000);
                    $totalReturnValue = $returns * rand(1000, 5000);
                    $returnRate = $orders > 0 ? ($returns / $orders) * 100 : 0;

                    // Insert performance metrics
                    $stmt = $conn->prepare("
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
                    ");

                    $stmt->execute([
                        $supplier['id'],
                        $baseDate,
                        $orders,
                        $onTimeDeliveries,
                        $lateDeliveries,
                        $avgDeliveryDays,
                        $returns,
                        $qualityScore,
                        $totalOrderValue,
                        rand(50, 200) + (rand(0, 99) / 100),
                        $totalReturnValue,
                        $returnRate
                    ]);
                }

                // Generate historical data (last 6 months)
                for ($i = 1; $i <= 6; $i++) {
                    $historicalDate = date('Y-m-d', strtotime("-{$i} months"));

                    $historicalOrders = rand(5, 12);
                    $historicalOnTimeRate = rand(80, 95);
                    $historicalQualityScore = rand(82, 95);

                    $historicalOnTimeDeliveries = round($historicalOrders * $historicalOnTimeRate / 100);
                    $historicalLateDeliveries = $historicalOrders - $historicalOnTimeDeliveries;

                    $stmt->execute([
                        $supplier['id'],
                        $historicalDate,
                        $historicalOrders,
                        $historicalOnTimeDeliveries,
                        $historicalLateDeliveries,
                        rand(5, 12) + (rand(0, 9) / 10),
                        rand(0, max(1, round($historicalOrders * 0.03))),
                        $historicalQualityScore,
                        $historicalOrders * rand(4000, 12000),
                        rand(45, 180) + (rand(0, 99) / 100),
                        rand(0, 2000),
                        rand(0, 3) + (rand(0, 9) / 10)
                    ]);
                }

                // Add some quality issues
                $issueTypes = ['defective', 'wrong_item', 'damaged', 'expired', 'quality'];
                $severities = ['low', 'medium', 'high'];

                for ($i = 0; $i < rand(0, 3); $i++) {
                    $issueStmt = $conn->prepare("
                        INSERT INTO supplier_quality_issues
                        (supplier_id, issue_type, severity, description, reported_date, resolved, resolution_notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    $issueType = $issueTypes[array_rand($issueTypes)];
                    $severity = $severities[array_rand($severities)];
                    $resolved = rand(0, 1);
                    $reportedDate = date('Y-m-d', strtotime('-' . rand(1, 90) . ' days'));
                    $resolvedDate = $resolved ? date('Y-m-d', strtotime($reportedDate . ' +' . rand(1, 14) . ' days')) : null;

                    $descriptions = [
                        'defective' => 'Product received with manufacturing defects',
                        'wrong_item' => 'Wrong product variant received',
                        'damaged' => 'Product damaged during shipping',
                        'expired' => 'Product received past expiry date',
                        'quality' => 'Product does not meet quality standards'
                    ];

                    $issueStmt->execute([
                        $supplier['id'],
                        $issueType,
                        $severity,
                        $descriptions[$issueType] . ' - Batch #' . rand(1000, 9999),
                        $reportedDate,
                        $resolved,
                        $resolved ? 'Issue resolved and replacement sent' : null
                    ]);
                }
            }

            // Silent success - data populated without console output

        } catch (PDOException $e) {
            // Silent error handling - log to PHP error log if needed
            error_log("Warning: Could not populate supplier performance data: " . $e->getMessage());
        }
    }

    // Create expense management tables
    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            parent_id INT DEFAULT NULL,
            color_code VARCHAR(7) DEFAULT '#6366f1',
            is_tax_deductible TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE SET NULL,
            INDEX idx_parent_id (parent_id),
            INDEX idx_is_active (is_active),
            INDEX idx_sort_order (sort_order)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_vendors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            tax_id VARCHAR(50),
            payment_terms VARCHAR(100),
            credit_limit DECIMAL(12, 2) DEFAULT 0,
            current_balance DECIMAL(12, 2) DEFAULT 0,
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_is_active (is_active)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_payment_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_is_active (is_active),
            INDEX idx_sort_order (sort_order)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            manager_id INT,
            budget_amount DECIMAL(12, 2) DEFAULT 0,
            budget_period ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_is_active (is_active),
            INDEX idx_sort_order (sort_order)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_number VARCHAR(50) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            category_id INT NOT NULL,
            subcategory_id INT,
            vendor_id INT,
            department_id INT,
            amount DECIMAL(12, 2) NOT NULL,
            tax_amount DECIMAL(12, 2) DEFAULT 0,
            total_amount DECIMAL(12, 2) NOT NULL,
            payment_method_id INT,
            payment_status ENUM('pending', 'paid', 'partial', 'overdue') DEFAULT 'pending',
            payment_date DATE,
            due_date DATE,
            expense_date DATE NOT NULL,
            is_tax_deductible TINYINT(1) DEFAULT 0,
            is_recurring TINYINT(1) DEFAULT 0,
            recurring_frequency ENUM('daily', 'weekly', 'monthly', 'quarterly', 'yearly'),
            recurring_end_date DATE,
            approval_status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
            approved_by INT,
            approved_at DATETIME,
            rejection_reason TEXT,
            receipt_file VARCHAR(500),
            notes TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES expense_categories(id),
            FOREIGN KEY (subcategory_id) REFERENCES expense_categories(id),
            FOREIGN KEY (vendor_id) REFERENCES expense_vendors(id),
            FOREIGN KEY (department_id) REFERENCES expense_departments(id),
            FOREIGN KEY (payment_method_id) REFERENCES expense_payment_methods(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_expense_number (expense_number),
            INDEX idx_category_id (category_id),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_department_id (department_id),
            INDEX idx_payment_status (payment_status),
            INDEX idx_approval_status (approval_status),
            INDEX idx_expense_date (expense_date),
            INDEX idx_created_by (created_by),
            INDEX idx_is_recurring (is_recurring)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(100),
            file_size INT,
            uploaded_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id),
            INDEX idx_expense_id (expense_id)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_id INT NOT NULL,
            approver_id INT NOT NULL,
            approval_level INT DEFAULT 1,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            comments TEXT,
            approved_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE,
            FOREIGN KEY (approver_id) REFERENCES users(id),
            INDEX idx_expense_id (expense_id),
            INDEX idx_approver_id (approver_id),
            INDEX idx_status (status)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS expense_budgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            department_id INT,
            category_id INT,
            budget_amount DECIMAL(12, 2) NOT NULL,
            spent_amount DECIMAL(12, 2) DEFAULT 0,
            budget_period ENUM('monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES expense_departments(id),
            FOREIGN KEY (category_id) REFERENCES expense_categories(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_department_id (department_id),
            INDEX idx_category_id (category_id),
            INDEX idx_budget_period (budget_period),
            INDEX idx_is_active (is_active)
        )
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS vendor_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            expense_id INT,
            payment_amount DECIMAL(12, 2) NOT NULL,
            payment_date DATE NOT NULL,
            payment_method_id INT,
            reference_number VARCHAR(100),
            notes TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vendor_id) REFERENCES expense_vendors(id),
            FOREIGN KEY (expense_id) REFERENCES expenses(id),
            FOREIGN KEY (payment_method_id) REFERENCES expense_payment_methods(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_vendor_id (vendor_id),
            INDEX idx_expense_id (expense_id),
            INDEX idx_payment_date (payment_date)
        )
    ");

    // Insert default expense categories
    $default_expense_categories = [
        ['Rent', 'Office and facility rent expenses', NULL, '#ef4444', 1, 1, 1],
        ['Utilities', 'Electricity, water, internet, phone', NULL, '#f59e0b', 1, 1, 2],
        ['Salaries', 'Employee salaries and wages', NULL, '#10b981', 0, 1, 3],
        ['Marketing', 'Advertising and promotional expenses', NULL, '#8b5cf6', 1, 1, 4],
        ['Supplies', 'Office supplies and materials', NULL, '#06b6d4', 1, 1, 5],
        ['Maintenance', 'Equipment and facility maintenance', NULL, '#84cc16', 1, 1, 6],
        ['Travel', 'Business travel expenses', NULL, '#f97316', 1, 1, 7],
        ['Insurance', 'Business insurance premiums', NULL, '#ec4899', 1, 1, 8],
        ['Professional Services', 'Legal, accounting, consulting', NULL, '#6366f1', 1, 1, 9],
        ['Equipment', 'Office equipment and furniture', NULL, '#14b8a6', 1, 1, 10],
        ['Software', 'Software licenses and subscriptions', NULL, '#f43f5e', 1, 1, 11],
        ['Other', 'Miscellaneous expenses', NULL, '#6b7280', 0, 1, 12]
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO expense_categories (name, description, parent_id, color_code, is_tax_deductible, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($default_expense_categories as $category) {
        $stmt->execute($category);
    }

    // Insert subcategories
    $subcategories = [
        ['Electricity', 'Electricity bills', 2, '#f59e0b', 1, 1, 1],
        ['Water', 'Water and sewage bills', 2, '#f59e0b', 1, 1, 2],
        ['Internet', 'Internet and phone services', 2, '#f59e0b', 1, 1, 3],
        ['Gas', 'Gas and heating expenses', 2, '#f59e0b', 1, 1, 4],
        ['Print Advertising', 'Newspaper, magazine ads', 4, '#8b5cf6', 1, 1, 1],
        ['Digital Marketing', 'Online advertising and SEO', 4, '#8b5cf6', 1, 1, 2],
        ['Social Media', 'Social media marketing', 4, '#8b5cf6', 1, 1, 3],
        ['Office Supplies', 'Paper, pens, stationery', 5, '#06b6d4', 1, 1, 1],
        ['Cleaning Supplies', 'Cleaning materials', 5, '#06b6d4', 1, 1, 2],
        ['Equipment Repair', 'Computer and equipment repairs', 6, '#84cc16', 1, 1, 1],
        ['Facility Maintenance', 'Building and facility repairs', 6, '#84cc16', 1, 1, 2],
        ['Airfare', 'Flight tickets', 7, '#f97316', 1, 1, 1],
        ['Hotel', 'Accommodation expenses', 7, '#f97316', 1, 1, 2],
        ['Meals', 'Business meals and entertainment', 7, '#f97316', 1, 1, 3],
        ['Transportation', 'Local transportation costs', 7, '#f97316', 1, 1, 4]
    ];

    foreach ($subcategories as $subcategory) {
        $stmt->execute($subcategory);
    }

    // Insert default payment methods
    $default_payment_methods = [
        ['Cash', 'Cash payments', 1, 1],
        ['Check', 'Check payments', 1, 2],
        ['Credit Card', 'Credit card payments', 1, 3],
        ['Bank Transfer', 'Bank transfers and wire transfers', 1, 4],
        ['Mobile Money', 'Mobile money payments', 1, 5],
        ['PayPal', 'PayPal payments', 1, 6]
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO expense_payment_methods (name, description, is_active, sort_order) VALUES (?, ?, ?, ?)");
    foreach ($default_payment_methods as $method) {
        $stmt->execute($method);
    }

    // Insert default departments
    $default_departments = [
        ['Administration', 'General administration and management', NULL, 50000, 'monthly'],
        ['Sales', 'Sales and marketing department', NULL, 30000, 'monthly'],
        ['Operations', 'Operations and production', NULL, 40000, 'monthly'],
        ['Finance', 'Finance and accounting', NULL, 25000, 'monthly'],
        ['IT', 'Information technology', NULL, 35000, 'monthly'],
        ['Human Resources', 'HR and personnel management', NULL, 20000, 'monthly']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO expense_departments (name, description, manager_id, budget_amount, budget_period) VALUES (?, ?, ?, ?, ?)");
    foreach ($default_departments as $department) {
        $stmt->execute($department);
    }

    // Add expense management permissions
    $expense_permissions = [
        ['manage_expenses', 'Manage all expenses'],
        ['create_expenses', 'Create new expenses'],
        ['edit_expenses', 'Edit existing expenses'],
        ['delete_expenses', 'Delete expenses'],
        ['approve_expenses', 'Approve expense requests'],
        ['view_expense_reports', 'View expense reports and analytics'],
        ['manage_expense_categories', 'Manage expense categories'],
        ['manage_expense_departments', 'Manage expense departments'],
        ['manage_expense_vendors', 'Manage expense vendors'],
        ['manage_expense_budgets', 'Manage expense budgets'],
        ['export_expenses', 'Export expense data']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description) VALUES (?, ?)");
    foreach ($expense_permissions as $permission) {
        $stmt->execute($permission);
    }

    // Assign expense permissions to Admin role
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 1, id FROM permissions WHERE name IN ('manage_expenses', 'create_expenses', 'edit_expenses', 'delete_expenses', 'approve_expenses', 'view_expense_reports', 'manage_expense_categories', 'manage_expense_departments', 'manage_expense_vendors', 'manage_expense_budgets', 'export_expenses')");
    $stmt->execute();

    // Assign limited expense permissions to Cashier role
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 2, id FROM permissions WHERE name IN ('create_expenses', 'view_expense_reports')");
    $stmt->execute();

    // Add expense settings
    $expense_settings = [
        ['expense_auto_approval', '0'],
        ['expense_approval_required', '1'],
        ['expense_max_amount_auto_approval', '1000'],
        ['expense_notification_email', ''],
        ['expense_receipt_required', '1'],
        ['expense_tax_deductible_default', '0'],
        ['expense_number_prefix', 'EXP'],
        ['expense_number_length', '6'],
        ['expense_currency', 'KES'],
        ['expense_decimal_places', '2']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($expense_settings as $setting) {
        $stmt->execute($setting);
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