<?php
$host = 'localhost';
$dbname = 'pos_system';
$username = 'root';
$password = '';

// Initialize connection status
$GLOBALS['db_connected'] = false;
$GLOBALS['db_error'] = '';

try {
    // Check if we're accessing from starter.php to avoid redirect loop
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $isStarterPage = ($currentScript === 'starter.php' || strpos($requestUri, 'starter.php') !== false);
    
    $conn = new PDO("mysql:host=$host", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Store connection in global scope for accessibility
    $GLOBALS['conn'] = $conn;

    // Store connection status for login page
    $GLOBALS['db_connected'] = true;

    // Create database if not exists
    $conn->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $conn->exec("USE `$dbname`");
    
    // Disable foreign key checks temporarily to avoid dependency issues
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Check if system is properly installed by checking for admin users
    try {
        $stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'Admin'");
        $result = $stmt->fetch();
        
        if ($result['count'] == 0 && !$isStarterPage) {
            // No admin users found, show friendly installer message
            showInstallerMessage();
            exit();
        }
    } catch (PDOException $tableError) {
        // Users table doesn't exist - this is a fresh installation
        if (!$isStarterPage) {
            showInstallerMessage();
            exit();
        }
    }

    // Create users table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('Admin', 'Cashier') NOT NULL,
            role_id INT DEFAULT NULL,
            employment_id VARCHAR(50) DEFAULT NULL UNIQUE,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Ensure users.role enum supports all roles we use
    try {
        $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Manager','Cashier','User') NOT NULL");
    } catch (PDOException $e) {
        // Table may already have a compatible enum; ignore if alter fails
        error_log('Role enum alter (users.role) skipped or failed: ' . $e->getMessage());
    }

    // Add employment_id field if it doesn't exist
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN employment_id VARCHAR(50) DEFAULT NULL UNIQUE");
    } catch (PDOException $e) {
        // Column may already exist; ignore if alter fails
        error_log('employment_id column add skipped or failed: ' . $e->getMessage());
    }

    // Add status field if it doesn't exist
    try {
        $conn->exec("ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'");
    } catch (PDOException $e) {
        // Column may already exist; ignore if alter fails
        error_log('status column add skipped or failed: ' . $e->getMessage());
    }


    // Create categories table first
    $conn->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Create brands table early (referenced by products)
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
    
    // Create suppliers table early (referenced by products)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
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
    
    // Create register_tills table early (referenced by sales)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS register_tills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            till_name VARCHAR(100) NOT NULL,
            till_code VARCHAR(20) UNIQUE,
            location VARCHAR(255),
            status ENUM('active', 'inactive') DEFAULT 'active',
            is_active TINYINT(1) DEFAULT 1,
            till_status ENUM('closed', 'opened') DEFAULT 'closed',
            current_user_id INT DEFAULT NULL,
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
            product_number VARCHAR(100) UNIQUE COMMENT 'Internal product number for tracking',
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
            INDEX idx_product_number (product_number),
            INDEX idx_product_type (product_type),
            INDEX idx_status (status),
            INDEX idx_brand_id (brand_id),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_barcode (barcode),
            INDEX idx_publication_status (publication_status),
            INDEX idx_block_reason (block_reason(100)),

            -- Auto BOM fields
            is_auto_bom_enabled TINYINT(1) DEFAULT 0 COMMENT 'Whether Auto BOM is enabled for this product',
            auto_bom_type ENUM('unit_conversion', 'repackaging', 'bulk_selling') DEFAULT NULL COMMENT 'Type of Auto BOM configuration',
            base_unit VARCHAR(50) DEFAULT 'each' COMMENT 'Base unit for Auto BOM calculations',
            base_quantity DECIMAL(10,3) DEFAULT 1 COMMENT 'Base quantity for Auto BOM calculations',
            product_family_id INT DEFAULT NULL COMMENT 'Reference to product family',
            INDEX idx_auto_bom_enabled (is_auto_bom_enabled),
            INDEX idx_product_family_id (product_family_id)
        )
    ");

    // Create product_families table for Auto BOM
    $conn->exec("
        CREATE TABLE IF NOT EXISTS product_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            family_name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            base_product_id INT NOT NULL COMMENT 'Reference to the base/main product',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (base_product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_family_name (family_name),
            INDEX idx_is_active (is_active)
        )
    ");

    // Create auto_bom_configs table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_family_id INT NOT NULL,
            product_id INT DEFAULT NULL COMMENT 'Direct product reference for auto BOM configurations',
            config_name VARCHAR(255) DEFAULT NULL COMMENT 'Configuration name for display purposes',
            base_product_id INT NOT NULL,
            base_unit VARCHAR(50) DEFAULT 'each' COMMENT 'Base unit for Auto BOM calculations',
            base_quantity DECIMAL(10,3) DEFAULT 1 COMMENT 'Base quantity for Auto BOM calculations',
            conversion_ratio DECIMAL(10,3) NOT NULL DEFAULT 1 COMMENT 'How many base units make one selling unit',
            min_stock_level INT DEFAULT 0,
            auto_reorder TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (product_family_id) REFERENCES product_families(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            FOREIGN KEY (base_product_id) REFERENCES products(id) ON DELETE CASCADE,
            INDEX idx_product_family_id (product_family_id),
            INDEX idx_product_id (product_id),
            INDEX idx_config_name (config_name),
            INDEX idx_base_product_id (base_product_id),
            INDEX idx_is_active (is_active)
        )
    ");

    // Create auto_bom_selling_units table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_selling_units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_id INT NOT NULL,
            auto_bom_config_id INT DEFAULT NULL COMMENT 'Alias for config_id for compatibility',
            unit_name VARCHAR(100) NOT NULL,
            unit_description TEXT,
            quantity_per_base DECIMAL(10,3) NOT NULL COMMENT 'How many of this unit per base unit',
            unit_quantity DECIMAL(10,3) DEFAULT 1 COMMENT 'Unit quantity for display purposes',
            selling_price DECIMAL(10,2) NOT NULL,
            cost_price DECIMAL(10,2) DEFAULT 0,
            sku_suffix VARCHAR(20) COMMENT 'Suffix for generating SKU',
            unit_sku VARCHAR(100) UNIQUE COMMENT 'Individual SKU for this selling unit',
            unit_barcode VARCHAR(50) UNIQUE COMMENT 'Individual barcode for this selling unit',
            pricing_strategy ENUM('fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid') DEFAULT 'fixed' COMMENT 'Pricing strategy for this unit',
            is_active TINYINT(1) DEFAULT 1,
            status ENUM('active', 'inactive') DEFAULT 'active' COMMENT 'Status of this selling unit',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (config_id) REFERENCES auto_bom_configs(id) ON DELETE CASCADE,
            FOREIGN KEY (auto_bom_config_id) REFERENCES auto_bom_configs(id) ON DELETE SET NULL,
            INDEX idx_config_id (config_id),
            INDEX idx_auto_bom_config_id (auto_bom_config_id),
            INDEX idx_unit_name (unit_name),
            INDEX idx_unit_sku (unit_sku),
            INDEX idx_unit_barcode (unit_barcode),
            INDEX idx_pricing_strategy (pricing_strategy),
            INDEX idx_is_active (is_active),
            INDEX idx_status (status)
        )
    ");

    // Create auto_bom_price_history table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_price_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            selling_unit_id INT NOT NULL,
            old_price DECIMAL(10,2),
            new_price DECIMAL(10,2) NOT NULL,
            changed_by INT NOT NULL,
            change_reason VARCHAR(255),
            effective_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_selling_unit_id (selling_unit_id),
            INDEX idx_effective_date (effective_date)
        )
    ");

    // Add foreign key constraint for product_family_id in products table
    try {
        $conn->exec("ALTER TABLE products ADD CONSTRAINT fk_products_product_family_id FOREIGN KEY (product_family_id) REFERENCES product_families(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Foreign key might already exist, continue silently
    }

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

    // Add email field to suppliers table if it doesn't exist
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM suppliers LIKE 'email'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec("ALTER TABLE suppliers ADD COLUMN email VARCHAR(255) AFTER contact_person");
            error_log("Added email field to suppliers table");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add email field to suppliers table: " . $e->getMessage());
    }

    // Add in-store pickup fields to suppliers table
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM suppliers LIKE 'pickup_available'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_available TINYINT(1) DEFAULT 0 COMMENT 'Whether in-store pickup is available'");
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_address TEXT COMMENT 'Pickup location address'");
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_hours VARCHAR(255) COMMENT 'Store pickup hours'");
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_instructions TEXT COMMENT 'Pickup instructions for customers'");
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_contact_person VARCHAR(100) COMMENT 'Person to contact for pickup'");
            $conn->exec("ALTER TABLE suppliers ADD COLUMN pickup_contact_phone VARCHAR(20) COMMENT 'Phone for pickup inquiries'");
            error_log("Added in-store pickup fields to suppliers table");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add pickup fields to suppliers table: " . $e->getMessage());
    }

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
            till_id INT DEFAULT NULL,
            customer_id INT DEFAULT NULL,
            customer_name VARCHAR(255) DEFAULT 'Walking Customer',
            customer_phone VARCHAR(20) DEFAULT '',
            customer_email VARCHAR(255) DEFAULT '',
            customer_address TEXT,
            customer_id_number VARCHAR(50) DEFAULT '',
            total_amount DECIMAL(10, 2) NOT NULL,
            subtotal DECIMAL(10, 2) DEFAULT 0,
            discount DECIMAL(10, 2) DEFAULT 0,
            discount_amount DECIMAL(10, 2) DEFAULT 0,
            tax_rate DECIMAL(5, 2) DEFAULT 0,
            tax_amount DECIMAL(10, 2) DEFAULT 0,
            final_amount DECIMAL(10, 2) NOT NULL,
            total_paid DECIMAL(10, 2) DEFAULT 0,
            change_due DECIMAL(10, 2) DEFAULT 0,
            payment_method VARCHAR(50) DEFAULT 'cash',
            split_payment TINYINT(1) DEFAULT 0,
            customer_notes TEXT,
            notes TEXT DEFAULT NULL COMMENT 'General notes for the sale transaction',
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (till_id) REFERENCES register_tills(id),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_till_id (till_id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_sale_date (sale_date),
            INDEX idx_payment_method (payment_method)
        )
    ");

    // Add till_id column to existing sales table if it doesn't exist
    try {
        $conn->exec("ALTER TABLE sales ADD COLUMN till_id INT DEFAULT NULL AFTER user_id");
        $conn->exec("ALTER TABLE sales ADD FOREIGN KEY (till_id) REFERENCES register_tills(id)");
    } catch (PDOException $e) {
        // Column might already exist, ignore error
    }

    // Add quotation_id column to existing sales table if it doesn't exist
    try {
        $conn->exec("ALTER TABLE sales ADD COLUMN quotation_id INT DEFAULT NULL AFTER till_id");
        $conn->exec("ALTER TABLE sales ADD FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Column might already exist, ignore error
    }

    // Add receipt_id column to existing sales table if it doesn't exist
    try {
        $conn->exec("ALTER TABLE sales ADD COLUMN receipt_id VARCHAR(50) DEFAULT NULL AFTER quotation_id");
    } catch (PDOException $e) {
        // Column might already exist, ignore error
    }

    // Create void transactions table for audit trail
    $conn->exec("\n        CREATE TABLE IF NOT EXISTS void_transactions (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            user_id INT NOT NULL,\n            till_id INT DEFAULT NULL,\n            void_type ENUM('product', 'cart', 'sale', 'held_transaction') NOT NULL,\n            product_id INT DEFAULT NULL,\n            product_name VARCHAR(255) DEFAULT NULL,\n            quantity DECIMAL(10,3) DEFAULT NULL,\n            unit_price DECIMAL(10,2) DEFAULT NULL,\n            total_amount DECIMAL(10,2) DEFAULT NULL,\n            void_reason TEXT,\n            void_notes TEXT,\n            voided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            FOREIGN KEY (user_id) REFERENCES users(id),\n            FOREIGN KEY (till_id) REFERENCES register_tills(id),\n            FOREIGN KEY (product_id) REFERENCES products(id),\n            INDEX idx_void_type (void_type),\n            INDEX idx_voided_at (voided_at),\n            INDEX idx_user_id (user_id)\n        )\n    ");

    // If an existing database has the old enum, attempt to migrate it safely
    try {
        // First check if the table exists and what the current enum values are
        $stmt = $conn->query("SHOW COLUMNS FROM void_transactions LIKE 'void_type'");
        $column_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($column_info) {
            $current_type = $column_info['Type'];
            // Check if 'held_transaction' is already in the enum
            if (strpos($current_type, 'held_transaction') === false) {
                // Need to update the enum to include held_transaction
                $conn->exec("ALTER TABLE void_transactions MODIFY COLUMN void_type ENUM('product','cart','sale','held_transaction') NOT NULL");
                error_log('Successfully updated void_transactions.void_type enum to include held_transaction');
            }
        }
    } catch (PDOException $e) {
        // Log the specific error for debugging
        error_log('Could not alter void_transactions.void_type enum to include held_transaction: ' . $e->getMessage());

        // Try to create a temporary fix by recreating the table if it's a critical error
        try {
            // Check if the error is due to data incompatibility
            if (strpos($e->getMessage(), 'Data truncated') !== false || strpos($e->getMessage(), 'Invalid enum value') !== false) {
                error_log('Attempting to fix void_transactions table enum values...');

                // First, backup any existing data that might have invalid enum values
                $conn->exec("UPDATE void_transactions SET void_type = 'sale' WHERE void_type NOT IN ('product', 'cart', 'sale')");

                // Now try the alter again
                $conn->exec("ALTER TABLE void_transactions MODIFY COLUMN void_type ENUM('product','cart','sale','held_transaction') NOT NULL");
                error_log('Successfully fixed and updated void_transactions.void_type enum');
            }
        } catch (PDOException $e2) {
            error_log('Final attempt to fix void_transactions.void_type enum failed: ' . $e2->getMessage());
        }
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_number VARCHAR(20) UNIQUE NOT NULL COMMENT 'Unique customer identifier',
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20),
            mobile VARCHAR(20),
            address TEXT,
            city VARCHAR(100),
            state VARCHAR(100),
            zip_code VARCHAR(20),
            country VARCHAR(100) DEFAULT 'USA',
            date_of_birth DATE,
            gender ENUM('male', 'female', 'other') DEFAULT NULL,
            customer_type ENUM('individual', 'business', 'vip', 'wholesale', 'walk_in') DEFAULT 'individual',
            company_name VARCHAR(255),
            tax_id VARCHAR(50),
            credit_limit DECIMAL(10, 2) DEFAULT 0,
            current_balance DECIMAL(10, 2) DEFAULT 0,
            loyalty_points INT DEFAULT 0,
            membership_status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
            membership_level VARCHAR(50) DEFAULT 'Bronze',
            preferred_payment_method VARCHAR(50),
            reward_program_active TINYINT(1) DEFAULT 1 COMMENT 'Whether customer is enrolled in reward program',
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");

    // Create held_transactions table for POS system
    $conn->exec("
        CREATE TABLE IF NOT EXISTS held_transactions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            till_id INT DEFAULT NULL COMMENT 'Till ID where transaction was held',
            cart_data TEXT NOT NULL COMMENT 'JSON encoded cart data with items, customer, and total',
            reason VARCHAR(255) DEFAULT NULL COMMENT 'Reason for holding the transaction',
            customer_reference VARCHAR(255) DEFAULT NULL COMMENT 'Customer name, phone, or reference',
            status ENUM('held', 'resumed', 'deleted', 'completed') DEFAULT 'held' COMMENT 'Transaction status',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When transaction was held',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last updated timestamp',

            -- Indexes for better performance
            INDEX idx_user_id (user_id),
            INDEX idx_till_id (till_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),

            -- Foreign key constraints
            CONSTRAINT fk_held_transactions_user_id
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_held_transactions_till_id
                FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE SET NULL
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
          COMMENT='Stores suspended POS transactions that can be resumed later'
    ");

    // Create default Walk-in Customer if it doesn't exist
    try {
        $walk_in_check = $conn->prepare("SELECT id FROM customers WHERE customer_number = 'WALK-IN-001' LIMIT 1");
        $walk_in_check->execute();

        if ($walk_in_check->rowCount() == 0) {
            // Get the first admin user ID for created_by
            $admin_user = $conn->prepare("SELECT id FROM users WHERE role = 'Admin' LIMIT 1");
            $admin_user->execute();
            $admin_id = $admin_user->fetch(PDO::FETCH_ASSOC)['id'] ?? 1;

            $walk_in_stmt = $conn->prepare("
                INSERT INTO customers (
                    customer_number, first_name, last_name, customer_type,
                    membership_status, membership_level, notes, created_by
                ) VALUES (
                    'WALK-IN-001', 'Walk-in', 'Customer', 'walk_in',
                    'active', 'Bronze', 'Default customer for walk-in purchases', ?
                )
            ");
            $walk_in_stmt->execute([$admin_id]);
        }
    } catch (Exception $e) {
        // Silently continue if there's an issue creating the walk-in customer
        // This prevents database setup failures
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT NULL,
            selling_unit_id INT DEFAULT NULL COMMENT 'Auto BOM selling unit ID',
            product_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Product name at time of sale',
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Unit price at time of sale',
            price DECIMAL(10, 2) NOT NULL DEFAULT 0,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Total price for this line item',
            is_auto_bom TINYINT(1) DEFAULT 0 COMMENT 'Whether this is an Auto BOM item',
            base_quantity_deducted DECIMAL(10,3) DEFAULT 0 COMMENT 'Base quantity deducted from inventory',
            FOREIGN KEY (sale_id) REFERENCES sales(id),
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
            FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE SET NULL,
            INDEX idx_selling_unit_id (selling_unit_id),
            INDEX idx_is_auto_bom (is_auto_bom)
        )
    ");

    // Create sale_payments table for split payments
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sale_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            reference VARCHAR(255) DEFAULT NULL COMMENT 'Transaction reference, last 4 digits, etc.',
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            INDEX idx_sale_id (sale_id),
            INDEX idx_payment_method (payment_method)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Ensure sales table has columns needed for checkout and split payments
    try {
        $stmt = $conn->query("DESCRIBE sales");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Add subtotal
        if (!in_array('subtotal', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN subtotal DECIMAL(10,2) DEFAULT 0 AFTER user_id");
        }
        // Add tax_rate
        if (!in_array('tax_rate', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN tax_rate DECIMAL(5,2) DEFAULT 0 AFTER subtotal");
        }
        // Add customer_notes
        if (!in_array('customer_notes', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_notes TEXT AFTER payment_method");
        }
        // Add split_payment flag
        if (!in_array('split_payment', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN split_payment TINYINT(1) DEFAULT 0 AFTER payment_method");
        }
        // Add total_paid
        if (!in_array('total_paid', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN total_paid DECIMAL(10,2) DEFAULT 0 AFTER final_amount");
        }
        // Add change_due
        if (!in_array('change_due', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN change_due DECIMAL(10,2) DEFAULT 0 AFTER total_paid");
        }
        // Add cash_received
        if (!in_array('cash_received', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN cash_received DECIMAL(10,2) DEFAULT NULL COMMENT 'Cash amount received'");
        }
        // Add change_amount
        if (!in_array('change_amount', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN change_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Change amount given'");
        }
        // Add amount_given
        if (!in_array('amount_given', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN amount_given DECIMAL(10,2) DEFAULT NULL COMMENT 'Total amount given by customer'");
        }
        // Add balance_due
        if (!in_array('balance_due', $columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN balance_due DECIMAL(10,2) DEFAULT 0 COMMENT 'Remaining balance due'");
        }
    } catch (PDOException $e) {
        error_log("Could not alter sales table for split payments: " . $e->getMessage());
    }

    // Add tax management columns to existing tables
    try {
        // Add tax_category_id to products table
        $stmt = $conn->query("DESCRIBE products");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('tax_category_id', $columns)) {
            $conn->exec("ALTER TABLE products ADD COLUMN tax_category_id INT DEFAULT NULL AFTER tax_rate");
            $conn->exec("ALTER TABLE products ADD CONSTRAINT fk_products_tax_category_id FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE products ADD INDEX idx_tax_category_id (tax_category_id)");
        }

        // Add tax exemption columns to customers table
        $stmt = $conn->query("DESCRIBE customers");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('tax_exempt', $columns)) {
            $conn->exec("ALTER TABLE customers ADD COLUMN tax_exempt TINYINT(1) DEFAULT 0 AFTER membership_level");
            $conn->exec("ALTER TABLE customers ADD COLUMN tax_exempt_reason VARCHAR(255) DEFAULT NULL AFTER tax_exempt");
            $conn->exec("ALTER TABLE customers ADD COLUMN tax_exempt_certificate VARCHAR(100) DEFAULT NULL AFTER tax_exempt_reason");
            $conn->exec("ALTER TABLE customers ADD INDEX idx_tax_exempt (tax_exempt)");
        }

        // Add customer_id to sales table
        $stmt = $conn->query("DESCRIBE sales");
        $sales_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('customer_id', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_id INT DEFAULT NULL AFTER user_id");
            $conn->exec("ALTER TABLE sales ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE sales ADD INDEX idx_customer_id (customer_id)");
        }

        // Add till_id column to sales table if it doesn't exist
        if (!in_array('till_id', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN till_id INT DEFAULT NULL AFTER customer_id");
            $conn->exec("ALTER TABLE sales ADD FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE sales ADD INDEX idx_till_id (till_id)");
        }

        // Ensure is_active column exists in register_tills table
        $stmt = $conn->query("DESCRIBE register_tills");
        $till_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('is_active', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER location");
            $conn->exec("UPDATE register_tills SET is_active = 1 WHERE is_active IS NULL");
        }

        // Add till_status column to register_tills table if it doesn't exist
        if (!in_array('till_status', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN till_status ENUM('closed', 'opened') DEFAULT 'closed' AFTER is_active");
            $conn->exec("UPDATE register_tills SET till_status = 'closed' WHERE till_status IS NULL");
        }

        // Add current_user_id column to register_tills table if it doesn't exist
        if (!in_array('current_user_id', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN current_user_id INT DEFAULT NULL AFTER till_status");
            $conn->exec("ALTER TABLE register_tills ADD FOREIGN KEY (current_user_id) REFERENCES users(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE register_tills ADD INDEX idx_current_user_id (current_user_id)");
        }
        
        // Add opening_balance column to register_tills table if it doesn't exist
        if (!in_array('opening_balance', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN opening_balance DECIMAL(15,2) DEFAULT 0.00 AFTER location");
        }
        
        // Add current_balance column to register_tills table if it doesn't exist
        if (!in_array('current_balance', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN current_balance DECIMAL(15,2) DEFAULT 0.00 AFTER opening_balance");
        }
        
        // Add assigned_user_id column to register_tills table if it doesn't exist
        if (!in_array('assigned_user_id', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN assigned_user_id INT DEFAULT NULL AFTER current_balance");
            $conn->exec("ALTER TABLE register_tills ADD FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE register_tills ADD INDEX idx_assigned_user_id (assigned_user_id)");
        }
        
        // Add created_at column to register_tills table if it doesn't exist
        if (!in_array('created_at', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active");
        }
        
        // Add updated_at column to register_tills table if it doesn't exist
        if (!in_array('updated_at', $till_columns)) {
            $conn->exec("ALTER TABLE register_tills ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }
    } catch (PDOException $e) {
        error_log("Could not add tax management columns: " . $e->getMessage());
    }

    // Add till_id column to held_transactions table if it doesn't exist
    try {
        $stmt = $conn->query("DESCRIBE held_transactions");
        $held_transactions_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('till_id', $held_transactions_columns)) {
            $conn->exec("ALTER TABLE held_transactions ADD COLUMN till_id INT DEFAULT NULL AFTER user_id");
            $conn->exec("ALTER TABLE held_transactions ADD FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE held_transactions ADD INDEX idx_till_id (till_id)");
        }
    } catch (PDOException $e) {
        error_log("Could not add till_id column to held_transactions table: " . $e->getMessage());
    }

        // Create roles table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            description TEXT,
            redirect_url VARCHAR(255) DEFAULT '../dashboard/dashboard.php' COMMENT 'Default page to redirect users after login',
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
            category VARCHAR(50) DEFAULT 'General',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Add category column to permissions table if it doesn't exist
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM permissions LIKE 'category'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec("ALTER TABLE permissions ADD COLUMN category VARCHAR(50) DEFAULT 'General' AFTER description");
            // Update existing permissions with appropriate categories
            $conn->exec("UPDATE permissions SET category = 'User Management' WHERE name LIKE '%user%' OR name LIKE '%manage_users%'");
            $conn->exec("UPDATE permissions SET category = 'Role Management' WHERE name LIKE '%role%' OR name LIKE '%permission%'");
            $conn->exec("UPDATE permissions SET category = 'Product Management' WHERE name LIKE '%product%' OR name LIKE '%inventory%'");
            $conn->exec("UPDATE permissions SET category = 'Sales & Transactions' WHERE name LIKE '%sale%' OR name LIKE '%transaction%' OR name LIKE '%pos%'");
            $conn->exec("UPDATE permissions SET category = 'Reports & Analytics' WHERE name LIKE '%report%' OR name LIKE '%analytic%' OR name LIKE '%view_%'");
            $conn->exec("UPDATE permissions SET category = 'System Settings' WHERE name LIKE '%setting%' OR name LIKE '%backup%' OR name LIKE '%system%'");
            error_log("Added category column to permissions table and categorized existing permissions");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add category column to permissions table: " . $e->getMessage());
    }

    // Add redirect_url column to roles table if it doesn't exist
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM roles LIKE 'redirect_url'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec("ALTER TABLE roles ADD COLUMN redirect_url VARCHAR(255) DEFAULT '../dashboard/dashboard.php' COMMENT 'Default page to redirect users after login' AFTER description");
            error_log("Added redirect_url column to roles table");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add redirect_url column to roles table: " . $e->getMessage());
    }

    // Create role_permission table
    $conn->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        UNIQUE KEY role_permission (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )");

    // Create permission_groups table for organizing permissions
    $conn->exec("
        CREATE TABLE IF NOT EXISTS permission_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_name VARCHAR(100) NOT NULL,
            group_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_permission_group (permission_name, group_name),
            INDEX idx_permission_name (permission_name),
            INDEX idx_group_name (group_name)
        )
    ");

    // Create menu_sections table for navigation control
    $conn->exec("
        CREATE TABLE IF NOT EXISTS menu_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_key VARCHAR(50) NOT NULL UNIQUE,
            section_name VARCHAR(100) NOT NULL,
            section_icon VARCHAR(50) DEFAULT 'bi-circle',
            section_description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Create available_pages table for dynamic page management
    $conn->exec("
        CREATE TABLE IF NOT EXISTS available_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_name VARCHAR(255) NOT NULL,
            page_url VARCHAR(500) NOT NULL,
            page_category VARCHAR(100) DEFAULT 'General',
            page_description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            is_admin_only TINYINT(1) DEFAULT 0,
            required_permission VARCHAR(100) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_page_url (page_url)
        )
    ");

    // Create role_menu_access table for role-based menu visibility
    $conn->exec("
        CREATE TABLE IF NOT EXISTS role_menu_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            menu_section_id INT NOT NULL,
            is_visible TINYINT(1) DEFAULT 1,
            is_priority TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY role_menu_section (role_id, menu_section_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (menu_section_id) REFERENCES menu_sections(id) ON DELETE CASCADE
        )
    ");

    // Initialize menu sections if they don't exist
    try {
        $stmt = $conn->query("SELECT COUNT(*) as count FROM menu_sections");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            // Insert default menu sections
            $menu_sections = [
                ['quotations', 'Quotations', 'bi-file-earmark-text', 'Create and manage customer quotations', 2],
                ['customer_crm', 'Customer CRM', 'bi-people', 'Customer relationship management and loyalty programs', 3],
                ['analytics', 'Analytics', 'bi-graph-up', 'Comprehensive analytics and reporting dashboard', 4],
                ['sales', 'Sales Management', 'bi-graph-up', 'Sales dashboard, analytics, and management tools', 5],
                ['inventory', 'Inventory', 'bi-boxes', 'Manage products, categories, brands, suppliers, and inventory', 6],
                ['shelf_labels', 'Shelf Labels', 'bi-tags', 'Generate and manage shelf labels for products', 7],
                ['expiry', 'Expiry Management', 'bi-clock-history', 'Track and manage product expiry dates', 8],
                ['bom', 'Bill of Materials', 'bi-file-earmark-text', 'Create and manage bills of materials and production', 9],
                ['finance', 'Finance', 'bi-calculator', 'Financial reports, budgets, and analysis', 10],
                ['expenses', 'Expense Management', 'bi-cash-stack', 'Track and manage business expenses', 11],
                ['admin', 'Administration', 'bi-shield', 'User management, settings, and system administration', 12]
            ];

            $stmt = $conn->prepare("
                INSERT INTO menu_sections (section_key, section_name, section_icon, section_description, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($menu_sections as $section) {
                $stmt->execute($section);
            }
        }

        // Ensure all required sections exist (for existing databases)
        $required_sections = [
            ['pos_management', 'POS Management', 'bi-cash-register', 'Till management, cash drops, and POS operations', 2],
            ['quotations', 'Quotations', 'bi-file-earmark-text', 'Create and manage customer quotations', 3],
            ['customer_crm', 'Customer CRM', 'bi-people', 'Customer relationship management and loyalty programs', 4],
            ['analytics', 'Analytics', 'bi-graph-up', 'Comprehensive analytics and reporting dashboard', 5],
            ['reports', 'Reports', 'bi-graph-up', 'Comprehensive reporting and analytics dashboard', 6],
            ['sales', 'Sales Management', 'bi-graph-up', 'Sales dashboard, analytics, and management tools', 7],
            ['inventory', 'Inventory', 'bi-boxes', 'Manage products, categories, brands, suppliers, and inventory', 8],
            ['shelf_labels', 'Shelf Labels', 'bi-tags', 'Generate and manage shelf labels for products', 9],
            ['expiry', 'Expiry Management', 'bi-clock-history', 'Track and manage product expiry dates', 10],
            ['bom', 'Bill of Materials', 'bi-file-earmark-text', 'Create and manage bills of materials and production', 11],
            ['finance', 'Finance', 'bi-calculator', 'Financial reports, budgets, and analysis', 12],
            ['expenses', 'Expense Management', 'bi-cash-stack', 'Track and manage business expenses', 13],
            ['admin', 'Administration', 'bi-shield', 'User management, settings, and system administration', 14]
        ];

        $stmt = $conn->prepare("
            INSERT IGNORE INTO menu_sections (section_key, section_name, section_icon, section_description, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($required_sections as $section) {
            $stmt->execute($section);
        }

        // Update existing sections with new sort order
        $stmt = $conn->prepare("
            UPDATE menu_sections
            SET section_name = ?, section_icon = ?, section_description = ?, sort_order = ?
            WHERE section_key = ?
        ");

        foreach ($required_sections as $section) {
            $stmt->execute([$section[1], $section[2], $section[3], $section[4], $section[0]]);
        }

    } catch (PDOException $e) {

        error_log("Warning: Could not initialize menu sections: " . $e->getMessage());
    }

    // Ensure 'dashboard' exists as a menu section so its visibility can be role-assigned
    try {
        $stmt = $conn->prepare("INSERT IGNORE INTO menu_sections (section_key, section_name, section_icon, section_description, sort_order)
                                VALUES ('dashboard', 'Dashboard', 'bi-speedometer2', 'Main dashboard overview', 1)");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Ensure dashboard menu section failed: " . $e->getMessage());
    }

    // Add menu management permissions
    try {
        $menu_permissions = [
            ['manage_menu_sections', 'Manage Menu Sections', 'Create, edit, and delete menu sections'],
            ['create_menu_sections', 'Create Menu Sections', 'Create new menu sections'],
            ['edit_menu_sections', 'Edit Menu Sections', 'Edit existing menu sections'],
            ['delete_menu_sections', 'Delete Menu Sections', 'Delete menu sections'],
            ['manage_menu_content', 'Manage Menu Content', 'Add and modify menu content items'],
            ['assign_menu_roles', 'Assign Menu to Roles', 'Assign menu sections to roles and set visibility'],
            ['view_all_menus', 'View All Menus', 'View all menu sections regardless of role assignment']
        ];

        foreach ($menu_permissions as $permission) {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO permissions (name, description, category)
                VALUES (?, ?, 'Menu Management')
            ");
            $stmt->execute($permission);
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add menu management permissions: " . $e->getMessage());
    }

    // Create settings table
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL,
        setting_value TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY setting_key (setting_key)
    )");

    // Ensure support email default exists
    try {
        $conn->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('support_email', 'support@thiarara.co.ke')");
    } catch (PDOException $e) {
        // ignore if table not ready yet in some flows
    }

    // Create tax_categories table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tax_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_is_active (is_active)
        )
    ");

    // Create tax_rates table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tax_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tax_category_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            rate DECIMAL(5, 4) NOT NULL COMMENT 'Tax rate as decimal (0.10 = 10%)',
            rate_percentage DECIMAL(5, 2) NOT NULL COMMENT 'Tax rate as percentage for display',
            description TEXT,
            effective_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            is_compound TINYINT(1) DEFAULT 0 COMMENT 'Whether this tax is calculated on top of other taxes',
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_tax_category_id (tax_category_id),
            INDEX idx_effective_date (effective_date),
            INDEX idx_end_date (end_date),
            INDEX idx_is_active (is_active),
            INDEX idx_is_compound (is_compound)
        )
    ");

    // Create tax_exemptions table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tax_exemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT DEFAULT NULL,
            product_id INT DEFAULT NULL,
            tax_category_id INT DEFAULT NULL,
            exemption_type ENUM('customer', 'product', 'category') NOT NULL,
            exemption_reason VARCHAR(255),
            certificate_number VARCHAR(100),
            effective_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (tax_category_id) REFERENCES tax_categories(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_customer_id (customer_id),
            INDEX idx_product_id (product_id),
            INDEX idx_tax_category_id (tax_category_id),
            INDEX idx_exemption_type (exemption_type),
            INDEX idx_effective_date (effective_date),
            INDEX idx_end_date (end_date),
            INDEX idx_is_active (is_active)
        )
    ");

    // Create sale_taxes table for detailed tax tracking
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sale_taxes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            tax_rate_id INT NOT NULL,
            tax_category_name VARCHAR(100) NOT NULL,
            tax_name VARCHAR(100) NOT NULL,
            tax_rate DECIMAL(5, 4) NOT NULL,
            taxable_amount DECIMAL(10, 2) NOT NULL,
            tax_amount DECIMAL(10, 2) NOT NULL,
            is_compound TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id),
            INDEX idx_sale_id (sale_id),
            INDEX idx_tax_rate_id (tax_rate_id)
        )
    ");

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
        ip_address VARCHAR(45) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

    // Create security_logs table for security event tracking
    $conn->exec("CREATE TABLE IF NOT EXISTS security_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(100) NOT NULL,
        details TEXT,
        severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_type (event_type),
        INDEX idx_severity (severity),
        INDEX idx_created_at (created_at)
    )");


    // Create login_attempts table for security monitoring
    $conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(100) NOT NULL, -- username
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        attempt_type ENUM('username') DEFAULT 'username',
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
        supplier_invoice_number VARCHAR(100) DEFAULT NULL,
        received_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_order_number (order_number),
        INDEX idx_supplier_id (supplier_id),
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_order_date (order_date),
        INDEX idx_invoice_number (invoice_number),
        UNIQUE KEY unique_invoice_number (invoice_number)
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

        if (!in_array('received_by', $columns)) {
            $conn->exec("ALTER TABLE inventory_orders ADD COLUMN received_by INT DEFAULT NULL AFTER supplier_invoice_number");
            error_log("Added received_by column to inventory_orders");
        }

        // Add index for invoice_number if it doesn't exist
        try {
            $conn->exec("ALTER TABLE inventory_orders ADD INDEX idx_invoice_number (invoice_number)");
        } catch (PDOException $e) {
            // Index might already exist, that's okay
        }

        // Add unique constraint for invoice_number if it doesn't exist
        try {
            $conn->exec("ALTER TABLE inventory_orders ADD UNIQUE KEY unique_invoice_number (invoice_number)");
            error_log("Added unique constraint for invoice_number");
        } catch (PDOException $e) {
            // Constraint might already exist, that's okay
            error_log("Invoice number unique constraint might already exist: " . $e->getMessage());
        }

        // Add foreign key constraint for received_by if it doesn't exist
        try {
            $conn->exec("ALTER TABLE inventory_orders ADD CONSTRAINT fk_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL");
            error_log("Added foreign key constraint for received_by");
        } catch (PDOException $e) {
            // Constraint might already exist, that's okay
            error_log("Received_by foreign key constraint might already exist: " . $e->getMessage());
        }

    } catch (PDOException $e) {
        // Table might not exist yet, that's okay
        error_log("Could not add invoice columns to inventory_orders: " . $e->getMessage());
    }

    // Create inventory_invoice_attachments table for invoice file attachments
    $conn->exec("CREATE TABLE IF NOT EXISTS inventory_invoice_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES inventory_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_order_id (order_id),
        INDEX idx_uploaded_by (uploaded_by)
    )");


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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES inventory_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        INDEX idx_order_id (order_id),
        INDEX idx_product_id (product_id),
        INDEX idx_status (status)
    )");


    // Add updated_at column if it doesn't exist
    try {
        $conn->exec("ALTER TABLE inventory_order_items ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Could not add updated_at column: " . $e->getMessage());
    }

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
                expiry_tracking_number VARCHAR(20) UNIQUE COMMENT 'Format: EXPT:000001',
                approval_status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
                submitted_by INT COMMENT 'User who submitted for approval',
                approved_by INT COMMENT 'User who approved',
                submitted_at DATETIME,
                approved_at DATETIME,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_product_id (product_id),
                INDEX idx_expiry_date (expiry_date),
                INDEX idx_batch_number (batch_number),
                INDEX idx_status (status),
                INDEX idx_alert_sent (alert_sent),
                INDEX idx_supplier_id (supplier_id),
                INDEX idx_expiry_tracking_number (expiry_tracking_number),
                INDEX idx_approval_status (approval_status)
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
                alert_type ENUM('sms', 'dashboard', 'system') NOT NULL,
                alert_days_before INT NOT NULL,
                alert_date DATETIME NOT NULL,
                recipient_user_id INT,
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
                alert_types VARCHAR(255) DEFAULT 'dashboard' COMMENT 'Comma-separated alert types',
                enable_sms_alerts TINYINT(1) DEFAULT 0,
                enable_dashboard_alerts TINYINT(1) DEFAULT 1,
                enable_system_alerts TINYINT(1) DEFAULT 1,
                sms_frequency ENUM('immediate', 'daily', 'weekly') DEFAULT 'immediate',
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

        // Assign till permissions to Admin role
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                               SELECT 1, id FROM permissions WHERE name IN ('open_till', 'close_till', 'drop_cash', 'view_till_reports', 'view_till_amounts')");
        $stmt->execute();

        // Assign limited till permissions to Cashier role (no view amounts)
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                               SELECT 2, id FROM permissions WHERE name IN ('open_till', 'close_till', 'drop_cash', 'view_till_reports')");
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

    // Add enhanced user management fields to users table
    $enhancedUserFields = [
        'first_name' => "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER username",
        'last_name' => "ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name",
        'phone' => "ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER username",
        'address' => "ALTER TABLE users ADD COLUMN address TEXT DEFAULT NULL AFTER phone",
        'avatar' => "ALTER TABLE users ADD COLUMN avatar VARCHAR(500) DEFAULT NULL AFTER address",
        'status' => "ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active' AFTER avatar",
        'date_of_birth' => "ALTER TABLE users ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER status",
        'hire_date' => "ALTER TABLE users ADD COLUMN hire_date DATE DEFAULT NULL AFTER date_of_birth",
        'department' => "ALTER TABLE users ADD COLUMN department VARCHAR(100) DEFAULT NULL AFTER hire_date",
        'employee_id' => "ALTER TABLE users ADD COLUMN employee_id VARCHAR(50) DEFAULT NULL AFTER department",
        'manager_id' => "ALTER TABLE users ADD COLUMN manager_id INT DEFAULT NULL AFTER employee_id",
        'updated_at' => "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
    ];

    foreach ($enhancedUserFields as $field => $sql) {
        $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE '$field'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            try {
                $conn->exec($sql);
            } catch (PDOException $e) {
                error_log("Could not add field $field to users table: " . $e->getMessage());
            }
        }
    }

    // Add foreign key for manager_id
    try {
        $conn->exec("ALTER TABLE users ADD CONSTRAINT fk_users_manager_id FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Foreign key might already exist
    }

    // Add created_by and updated_by columns to auto_bom_configs table
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_configs LIKE 'created_by'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if (!$result) {
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN created_by INT DEFAULT NULL AFTER is_active");
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN updated_by INT DEFAULT NULL AFTER created_by");
            $conn->exec("ALTER TABLE auto_bom_configs ADD CONSTRAINT fk_auto_bom_configs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE auto_bom_configs ADD CONSTRAINT fk_auto_bom_configs_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL");
            error_log("Added created_by and updated_by columns to auto_bom_configs table");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add created_by/updated_by columns to auto_bom_configs table: " . $e->getMessage());
    }

    // Add indexes for user management
    $userIndexes = [
        "CREATE INDEX idx_users_first_name ON users (first_name)",
        "CREATE INDEX idx_users_last_name ON users (last_name)",
        "CREATE INDEX idx_users_phone ON users (phone)",
        "CREATE INDEX idx_users_status ON users (status)",
        "CREATE INDEX idx_users_employee_id ON users (employee_id)",
        "CREATE INDEX idx_users_manager_id ON users (manager_id)",
        "CREATE INDEX idx_users_department ON users (department)"
    ];

    foreach ($userIndexes as $indexSql) {
        try {
            $conn->exec($indexSql);
        } catch (PDOException $e) {
            // Index might already exist
        }
    }

    // Insert default roles
    $conn->exec("INSERT IGNORE INTO roles (id, name, description) VALUES (1, 'Admin', 'Full access to the system')");
    $conn->exec("INSERT IGNORE INTO roles (id, name, description) VALUES (2, 'Cashier', 'Limited access for cashier operations')");

    // Insert default permissions
    $permissions = [
        // Dashboard & General
        ['view_dashboard', 'View dashboard', 'General'],

        // Product Management - Comprehensive Permissions
        ['create_products', 'Create new products', 'Product Management'],
        ['edit_products', 'Edit existing products', 'Product Management'],
        ['delete_products', 'Delete products', 'Product Management'],
        ['view_products', 'View products list and details', 'Product Management'],
        ['publish_products', 'Publish products to make them available for sale', 'Product Management'],
        ['approve_products', 'Approve product submissions and changes', 'Product Management'],
        ['draft_products', 'Create and manage product drafts', 'Product Management'],
        ['schedule_products', 'Schedule product publication dates', 'Product Management'],
        ['manage_product_categories', 'Manage product categories', 'Product Management'],
        ['manage_product_variants', 'Manage product variants (size, color, etc.)', 'Product Management'],
        ['manage_product_pricing', 'Manage product prices and cost information', 'Product Management'],
        ['manage_product_inventory', 'Manage product stock levels and inventory', 'Product Management'],
        ['manage_product_images', 'Upload and manage product images', 'Product Management'],
        ['import_products', 'Import products from files/external sources', 'Product Management'],
        ['export_products', 'Export product data', 'Product Management'],
        ['bulk_edit_products', 'Perform bulk operations on products', 'Product Management'],
        ['manage_product_attributes', 'Manage custom product attributes', 'Product Management'],
        ['manage_product_tags', 'Manage product tags and search keywords', 'Product Management'],
        ['manage_product_barcodes', 'Generate and manage product barcodes', 'Product Management'],
        ['manage_product_suppliers', 'Associate products with suppliers', 'Product Management'],
        ['manage_product_brands', 'Manage product brands', 'Product Management'],
        ['view_product_performance', 'View product sales and performance analytics', 'Product Management'],
        ['manage_product_reviews', 'Manage product reviews and ratings', 'Product Management'],
        ['manage_product_discounts', 'Manage product-specific discounts and sales', 'Product Management'],
        ['manage_expiry_dates', 'Manage product expiry dates and alerts', 'Product Management'],

        // Category Management
        ['manage_categories', 'Add, edit, delete categories', 'Product Management'],

        // Sales & Transactions - Comprehensive Permissions
        ['manage_sales', 'View sales history and details', 'Sales & Transactions'],
        ['process_sales', 'Process sales transactions', 'Sales & Transactions'],

        // Cart Management
        ['view_cart', 'View shopping cart contents', 'Sales & Transactions'],
        ['edit_cart', 'Edit items in shopping cart', 'Sales & Transactions'],
        ['manage_cart', 'Full cart management including add, edit, remove items', 'Sales & Transactions'],
        ['clear_cart', 'Clear all items from cart', 'Sales & Transactions'],
        ['apply_cart_discounts', 'Apply discounts to cart items', 'Sales & Transactions'],
        ['modify_cart_prices', 'Modify prices in cart for special cases', 'Sales & Transactions'],
        ['split_cart_items', 'Split cart items across multiple transactions', 'Sales & Transactions'],

        // Held Transactions Management
        ['view_held_transactions', 'View list of held transactions', 'Sales & Transactions'],
        ['create_held_transactions', 'Create and hold transactions for later', 'Sales & Transactions'],
        ['resume_held_transactions', 'Resume previously held transactions', 'Sales & Transactions'],
        ['cancel_held_transactions', 'Cancel held transactions', 'Sales & Transactions'],
        ['delete_held_transactions', 'Delete held transactions permanently', 'Sales & Transactions'],
        ['manage_held_transactions', 'Full management of held transactions', 'Sales & Transactions'],
        ['remove_held', 'Remove items from held transactions', 'Sales & Transactions'],
        ['transfer_held_transactions', 'Transfer held transactions to other users', 'Sales & Transactions'],

        // Sales Processing and Checkout
        ['process_checkout', 'Process checkout and payment', 'Sales & Transactions'],
        ['apply_discounts', 'Apply discounts to sales', 'Sales & Transactions'],
        ['override_prices', 'Override item prices during sale', 'Sales & Transactions'],
        ['void_sales', 'Void completed sales transactions', 'Sales & Transactions'],
        ['refund_sales', 'Process refunds for sales', 'Sales & Transactions'],
        ['exchange_items', 'Process item exchanges', 'Sales & Transactions'],
        ['suspend_sales', 'Suspend active sales transactions', 'Sales & Transactions'],
        ['complete_sales', 'Complete and finalize sales', 'Sales & Transactions'],

        // Customer Order Management
        ['manage_customer_orders', 'Full customer order management', 'Sales & Transactions'],
        ['view_customer_orders', 'View customer order details', 'Sales & Transactions'],
        ['create_customer_orders', 'Create new customer orders', 'Sales & Transactions'],
        ['edit_customer_orders', 'Edit existing customer orders', 'Sales & Transactions'],
        ['delete_customer_orders', 'Delete customer orders', 'Sales & Transactions'],
        ['approve_customer_orders', 'Approve customer orders for processing', 'Sales & Transactions'],
        ['cancel_customer_orders', 'Cancel customer orders', 'Sales & Transactions'],
        ['fulfill_customer_orders', 'Mark orders as fulfilled', 'Sales & Transactions'],
        ['track_customer_orders', 'Track order status and updates', 'Sales & Transactions'],

        // Payment Processing
        ['process_payments', 'Process customer payments', 'Sales & Transactions'],
        ['manage_payment_methods', 'Manage available payment methods', 'Sales & Transactions'],
        ['handle_split_payments', 'Handle split payments across methods', 'Sales & Transactions'],
        ['process_card_payments', 'Process credit/debit card payments', 'Sales & Transactions'],
        ['process_cash_payments', 'Process cash payments', 'Sales & Transactions'],
        ['process_mobile_payments', 'Process mobile money payments', 'Sales & Transactions'],
        ['issue_change', 'Issue change for cash payments', 'Sales & Transactions'],
        ['refund_payments', 'Process payment refunds', 'Sales & Transactions'],
        ['void_payments', 'Void processed payments', 'Sales & Transactions'],

        // Sales Reports and Analytics
        ['view_sales_reports', 'View detailed sales reports', 'Sales & Transactions'],
        ['generate_sales_reports', 'Generate custom sales reports', 'Sales & Transactions'],
        ['export_sales_data', 'Export sales data and reports', 'Sales & Transactions'],
        ['view_sales_analytics', 'View sales analytics and insights', 'Sales & Transactions'],
        ['view_daily_sales', 'View daily sales summaries', 'Sales & Transactions'],
        ['view_monthly_sales', 'View monthly sales reports', 'Sales & Transactions'],
        ['view_sales_by_product', 'View sales performance by product', 'Sales & Transactions'],
        ['view_sales_by_category', 'View sales performance by category', 'Sales & Transactions'],
        ['view_sales_by_payment_method', 'View sales by payment method', 'Sales & Transactions'],
        ['view_top_selling_products', 'View top selling products reports', 'Sales & Transactions'],
        ['view_sales_trends', 'View sales trends and patterns', 'Sales & Transactions'],
        ['view_customer_sales_history', 'View sales history for specific customers', 'Sales & Transactions'],

        // Transaction Management
        ['view_transaction_history', 'View transaction history and details', 'Sales & Transactions'],
        ['manage_transaction_logs', 'Manage transaction logging and auditing', 'Sales & Transactions'],
        ['view_transaction_logs', 'View transaction logs and audit trails', 'Sales & Transactions'],
        ['audit_transaction_logs', 'Audit and review transaction logs', 'Sales & Transactions'],
        ['export_transaction_data', 'Export transaction data for external use', 'Sales & Transactions'],
        ['search_transactions', 'Search and filter transactions', 'Sales & Transactions'],
        ['reconcile_transactions', 'Reconcile transaction records', 'Sales & Transactions'],


        // Point of Sale (POS) Settings
        ['manage_pos_settings', 'Manage POS system settings', 'Sales & Transactions'],
        ['configure_pos_settings', 'Configure POS interface and behavior', 'Sales & Transactions'],
        ['view_pos_settings', 'View current POS configuration', 'Sales & Transactions'],
        ['customize_pos_layout', 'Customize POS screen layout', 'Sales & Transactions'],
        ['manage_pos_shortcuts', 'Manage POS keyboard shortcuts', 'Sales & Transactions'],
        ['manage_pos_users', 'Manage users with POS access', 'Sales & Transactions'],

        // Cash Drawer Management
        ['manage_cash_drawer', 'Full cash drawer management', 'Sales & Transactions'],
        ['open_cash_drawer', 'Open cash drawer for transactions', 'Sales & Transactions'],
        ['close_cash_drawer', 'Close and secure cash drawer', 'Sales & Transactions'],
        ['view_cash_drawer_balance', 'View current cash drawer balance', 'Sales & Transactions'],
        ['count_cash_drawer', 'Perform cash drawer counts', 'Sales & Transactions'],
        ['reconcile_cash_drawer', 'Reconcile cash drawer with sales', 'Sales & Transactions'],
        ['reset_cash_drawer', 'Reset cash drawer for new shift', 'Sales & Transactions'],
        ['view_cash_drawer_logs', 'View cash drawer activity logs', 'Sales & Transactions'],
        ['audit_cash_drawer', 'Audit cash drawer transactions', 'Sales & Transactions'],
        ['transfer_cash_drawer_funds', 'Transfer funds between cash drawers', 'Sales & Transactions'],

        // Shift Management
        ['manage_shift_management', 'Full shift management system', 'Sales & Transactions'],
        ['start_shift', 'Start a new work shift', 'Sales & Transactions'],
        ['end_shift', 'End current work shift', 'Sales & Transactions'],
        ['view_shift_reports', 'View shift performance reports', 'Sales & Transactions'],
        ['manage_shift_transfers', 'Manage transfers between shifts', 'Sales & Transactions'],
        ['transfer_shift_funds', 'Transfer funds between shifts', 'Sales & Transactions'],
        ['reconcile_shift_funds', 'Reconcile shift funds and sales', 'Sales & Transactions'],
        ['view_shift_history', 'View historical shift data', 'Sales & Transactions'],
        ['approve_shift_changes', 'Approve shift-related changes', 'Sales & Transactions'],

        // Tax Management
        ['manage_sales_taxes', 'Manage sales tax calculations and rates', 'Sales & Transactions'],
        ['calculate_sales_taxes', 'Calculate taxes for sales transactions', 'Sales & Transactions'],
        ['view_sales_taxes', 'View tax breakdown for sales', 'Sales & Transactions'],
        ['override_tax_rates', 'Override tax rates for special cases', 'Sales & Transactions'],
        ['view_tax_reports', 'View tax-related reports and summaries', 'Sales & Transactions'],
        ['manage_tax_exemptions', 'Manage tax exemptions for customers', 'Sales & Transactions'],

        // Customer Management in Sales Context
        ['manage_sales_customers', 'Manage customer information during sales', 'Sales & Transactions'],
        ['view_customer_purchase_history', 'View customer purchase history during sales', 'Sales & Transactions'],
        ['apply_customer_discounts', 'Apply customer-specific discounts', 'Sales & Transactions'],
        ['manage_customer_loyalty', 'Manage customer loyalty points during sales', 'Sales & Transactions'],
        ['create_customer_accounts', 'Create customer accounts during checkout', 'Sales & Transactions'],
        ['update_customer_info', 'Update customer information during transactions', 'Sales & Transactions'],

        // Inventory Integration
        ['view_inventory_during_sale', 'View inventory levels during sales process', 'Sales & Transactions'],
        ['reserve_inventory', 'Reserve inventory for pending sales', 'Sales & Transactions'],
        ['check_inventory_availability', 'Check product availability during sales', 'Sales & Transactions'],
        ['manage_backorders', 'Manage backorders during sales process', 'Sales & Transactions'],
        ['handle_out_of_stock', 'Handle out-of-stock situations during sales', 'Sales & Transactions'],

        // Advanced Sales Features
        ['manage_sales_promotions', 'Manage sales promotions and campaigns', 'Sales & Transactions'],
        ['apply_bulk_discounts', 'Apply bulk purchase discounts', 'Sales & Transactions'],
        ['manage_price_overrides', 'Manage price override permissions and limits', 'Sales & Transactions'],
        ['handle_sales_exceptions', 'Handle exceptional sales situations', 'Sales & Transactions'],
        ['manage_sales_approvals', 'Manage multi-level sales approvals', 'Sales & Transactions'],
        ['view_sales_dashboard', 'View comprehensive sales dashboard', 'Sales & Transactions'],
        ['manage_sales_goals', 'Set and manage sales targets and goals', 'Sales & Transactions'],
        ['track_sales_performance', 'Track individual and team sales performance', 'Sales & Transactions'],

        // Transaction Security and Compliance
        ['audit_sales_transactions', 'Audit sales transactions for compliance', 'Sales & Transactions'],
        ['view_transaction_security_logs', 'View security logs for transactions', 'Sales & Transactions'],
        ['manage_transaction_limits', 'Set transaction limits and restrictions', 'Sales & Transactions'],
        ['handle_suspicious_transactions', 'Handle and flag suspicious transactions', 'Sales & Transactions'],
        ['comply_with_sales_regulations', 'Ensure compliance with sales regulations', 'Sales & Transactions'],

        // Integration and API
        ['sync_sales_data', 'Synchronize sales data with external systems', 'Sales & Transactions'],
        ['export_sales_to_accounting', 'Export sales data to accounting systems', 'Sales & Transactions'],
        ['integrate_payment_gateways', 'Integrate with external payment gateways', 'Sales & Transactions'],
        ['manage_sales_api', 'Manage sales-related API access', 'Sales & Transactions'],
        ['automate_sales_processes', 'Automate repetitive sales processes', 'Sales & Transactions'],

        // Till Management Permissions
        ['open_till', 'Open till at start of shift', 'Till Management'],
        ['close_till', 'Close till at end of shift', 'Till Management'],
        ['drop_cash', 'Perform cash drops from till', 'Till Management'],
        ['view_till_reports', 'View till reports and reconciliation', 'Till Management'],
        ['view_till_amounts', 'View monetary amounts in till operations', 'Till Management'],

        // Customer Management - Comprehensive Permissions
        ['view_customers', 'View customer accounts and profiles', 'Customer Management'],
        ['create_customers', 'Create new customer accounts', 'Customer Management'],
        ['edit_customers', 'Edit existing customer accounts', 'Customer Management'],
        ['delete_customers', 'Delete customer accounts', 'Customer Management'],
        ['manage_customers', 'Full customer management access', 'Customer Management'],
        ['activate_customers', 'Activate and deactivate customer accounts', 'Customer Management'],
        ['suspend_customers', 'Suspend and unsuspend customer accounts', 'Customer Management'],

        // Customer Profile Management
        ['view_customer_profiles', 'View detailed customer profiles and information', 'Customer Management'],
        ['edit_customer_profiles', 'Edit customer profile information', 'Customer Management'],
        ['manage_customer_personal_info', 'Manage customer personal information (name, phone, address)', 'Customer Management'],
        ['manage_customer_business_info', 'Manage customer business details (company, tax ID, etc.)', 'Customer Management'],
        ['view_customer_purchase_history', 'View customer purchase history and transaction records', 'Customer Management'],

        // Customer Account Management
        ['manage_customer_credit', 'Manage customer credit limits and balances', 'Customer Management'],
        ['view_customer_credit_history', 'View customer credit and payment history', 'Customer Management'],
        ['manage_customer_loyalty', 'Manage customer loyalty points and membership levels', 'Customer Management'],
        ['manage_customer_memberships', 'Manage customer membership status and benefits', 'Customer Management'],

        // Customer Communication
        ['manage_customer_communications', 'Manage customer communication preferences and history', 'Customer Management'],
        ['export_customer_data', 'Export customer data for external use', 'Customer Management'],
        ['import_customers', 'Import customer data from external sources', 'Customer Management'],
        ['bulk_manage_customers', 'Perform bulk operations on customer accounts', 'Customer Management'],

        // User Management - Comprehensive Permissions
        ['view_users', 'View user accounts and profiles', 'User Management'],
        ['create_users', 'Create new user accounts', 'User Management'],
        ['edit_users', 'Edit existing user accounts', 'User Management'],
        ['delete_users', 'Delete user accounts', 'User Management'],
        ['manage_users', 'Full user management access', 'User Management'],
        ['activate_users', 'Activate and deactivate user accounts', 'User Management'],
        ['suspend_users', 'Suspend and unsuspend user accounts', 'User Management'],
        ['reset_user_passwords', 'Reset user passwords', 'User Management'],
        ['unlock_user_accounts', 'Unlock locked user accounts', 'User Management'],

        // User Profile Management
        ['view_user_profiles', 'View detailed user profiles and information', 'User Management'],
        ['edit_user_profiles', 'Edit user profile information', 'User Management'],
        ['manage_user_avatars', 'Upload and manage user profile pictures', 'User Management'],
        ['manage_user_personal_info', 'Manage personal information (name, phone, address)', 'User Management'],
        ['manage_user_employment_info', 'Manage employment details (hire date, department, etc.)', 'User Management'],
        ['view_user_employment_history', 'View user employment and role history', 'User Management'],

        // User Account Security
        ['manage_user_security', 'Manage user account security settings', 'User Management'],
        ['view_user_login_history', 'View user login history and activity', 'User Management'],
        ['manage_user_sessions', 'Manage active user sessions', 'User Management'],
        ['force_user_logout', 'Force logout users from their sessions', 'User Management'],
        ['manage_user_2fa', 'Manage two-factor authentication for users', 'User Management'],
        ['view_failed_login_attempts', 'View failed login attempts and security alerts', 'User Management'],
        ['manage_account_lockouts', 'Manage account lockout policies and unlock accounts', 'User Management'],

        // User Role Assignment
        ['assign_user_roles', 'Assign roles to user accounts', 'User Management'],
        ['revoke_user_roles', 'Remove roles from user accounts', 'User Management'],
        ['view_user_role_assignments', 'View user role assignments and permissions', 'User Management'],
        ['manage_user_role_history', 'View and manage user role change history', 'User Management'],
        ['temporary_role_elevation', 'Grant temporary elevated roles to users', 'User Management'],

        // User Department and Hierarchy
        ['manage_user_departments', 'Assign users to departments and manage department structure', 'User Management'],
        ['view_user_hierarchy', 'View organizational user hierarchy', 'User Management'],
        ['assign_user_managers', 'Assign managers and supervisors to users', 'User Management'],
        ['manage_team_assignments', 'Manage team and group assignments', 'User Management'],

        // User Permissions Management
        ['view_user_permissions', 'View effective permissions for users', 'User Management'],
        ['assign_direct_user_permissions', 'Assign permissions directly to users (bypass roles)', 'User Management'],
        ['manage_user_permission_overrides', 'Override role permissions for specific users', 'User Management'],
        ['audit_user_permissions', 'Audit and review user permission assignments', 'User Management'],

        // User Import/Export
        ['import_users', 'Import users from external files or systems', 'User Management'],
        ['export_users', 'Export user data and information', 'User Management'],
        ['bulk_create_users', 'Create multiple users in bulk operations', 'User Management'],
        ['bulk_update_users', 'Update multiple users in bulk operations', 'User Management'],
        ['bulk_delete_users', 'Delete multiple users in bulk operations', 'User Management'],

        // User Analytics and Reporting
        ['view_user_reports', 'View user analytics and reports', 'User Management'],
        ['generate_user_reports', 'Generate custom user reports', 'User Management'],
        ['view_user_activity_reports', 'View user activity and usage reports', 'User Management'],
        ['view_user_performance_metrics', 'View user performance and productivity metrics', 'User Management'],
        ['analyze_user_behavior', 'Analyze user behavior patterns and trends', 'User Management'],

        // User Communication
        ['send_user_notifications', 'Send notifications and messages to users', 'User Management'],
        ['manage_user_announcements', 'Send announcements and system messages to users', 'User Management'],

        // User Compliance and Audit
        ['audit_user_activities', 'View user activity logs and audit trails', 'User Management'],
        ['manage_user_compliance', 'Ensure user accounts comply with policies', 'User Management'],
        ['generate_compliance_reports', 'Generate user compliance and audit reports', 'User Management'],
        ['manage_user_data_retention', 'Manage user data retention and deletion policies', 'User Management'],
        ['handle_user_data_requests', 'Handle user data access and deletion requests', 'User Management'],

        // Advanced User Features
        ['manage_user_preferences', 'Manage user system preferences and settings', 'User Management'],
        ['configure_user_dashboards', 'Configure personalized user dashboards', 'User Management'],
        ['manage_user_api_access', 'Manage user API keys and access tokens', 'User Management'],
        ['manage_user_integrations', 'Manage user third-party integrations', 'User Management'],
        ['impersonate_users', 'Log in as other users for support purposes', 'User Management'],

        // User System Administration
        ['configure_user_settings', 'Configure user management system settings', 'User Management'],
        ['manage_user_templates', 'Create and manage user account templates', 'User Management'],
        ['backup_user_data', 'Backup and restore user data', 'User Management'],
        ['migrate_user_data', 'Migrate user data between systems', 'User Management'],
        ['cleanup_inactive_users', 'Clean up and archive inactive user accounts', 'User Management'],

        // Role Management - Comprehensive Permissions
        ['view_roles', 'View roles and their configurations', 'Role Management'],
        ['create_roles', 'Create new roles', 'Role Management'],
        ['edit_roles', 'Edit existing roles', 'Role Management'],
        ['delete_roles', 'Delete roles', 'Role Management'],
        ['manage_roles', 'Full role management access', 'Role Management'],
        ['activate_roles', 'Activate and deactivate roles', 'Role Management'],
        ['duplicate_roles', 'Duplicate existing roles', 'Role Management'],

        // Role Permission Management
        ['view_permissions', 'View all available permissions', 'Role Management'],
        ['assign_permissions', 'Assign permissions to roles', 'Role Management'],
        ['revoke_permissions', 'Remove permissions from roles', 'Role Management'],
        ['manage_role_permissions', 'Full permission assignment management', 'Role Management'],
        ['bulk_assign_permissions', 'Bulk assign permissions to multiple roles', 'Role Management'],
        ['copy_role_permissions', 'Copy permissions from one role to another', 'Role Management'],
        ['validate_role_permissions', 'Validate role permission configurations', 'Role Management'],

        // Permission Category Management
        ['view_permission_categories', 'View permission categories and organization', 'Role Management'],
        ['manage_permission_categories', 'Organize permissions into categories', 'Role Management'],
        ['create_permission_categories', 'Create new permission categories', 'Role Management'],
        ['edit_permission_categories', 'Edit existing permission categories', 'Role Management'],
        ['delete_permission_categories', 'Delete permission categories', 'Role Management'],

        // Custom Permission Management
        ['create_custom_permissions', 'Create custom permissions for specific needs', 'Role Management'],
        ['edit_custom_permissions', 'Edit custom permission definitions', 'Role Management'],
        ['delete_custom_permissions', 'Delete custom permissions', 'Role Management'],
        ['manage_permission_descriptions', 'Manage permission names and descriptions', 'Role Management'],

        // Role Hierarchy Management
        ['manage_role_hierarchy', 'Create and manage role hierarchies', 'Role Management'],
        ['view_role_hierarchy', 'View role hierarchy and relationships', 'Role Management'],
        ['assign_parent_roles', 'Assign parent roles to create hierarchies', 'Role Management'],
        ['inherit_role_permissions', 'Manage permission inheritance between roles', 'Role Management'],
        ['override_inherited_permissions', 'Override inherited permissions', 'Role Management'],

        // Role Assignment Management
        ['assign_roles_to_users', 'Assign roles to users', 'Role Management'],
        ['revoke_roles_from_users', 'Remove roles from users', 'Role Management'],
        ['view_user_roles', 'View which roles are assigned to users', 'Role Management'],
        ['manage_multiple_user_roles', 'Assign multiple roles to single users', 'Role Management'],
        ['bulk_assign_user_roles', 'Bulk assign roles to multiple users', 'Role Management'],
        ['temporary_role_assignment', 'Assign temporary roles with expiration dates', 'Role Management'],

        // Role Analytics and Reporting
        ['view_role_usage_reports', 'View role usage and assignment reports', 'Role Management'],
        ['view_permission_usage_reports', 'View permission usage across roles', 'Role Management'],
        ['generate_role_reports', 'Generate custom role and permission reports', 'Role Management'],
        ['export_role_data', 'Export role and permission data', 'Role Management'],
        ['analyze_role_effectiveness', 'Analyze role effectiveness and optimization', 'Role Management'],
        ['view_role_conflicts', 'View and resolve role permission conflicts', 'Role Management'],
        ['audit_role_changes', 'View role and permission change audit logs', 'Role Management'],

        // Role Security Management
        ['manage_sensitive_roles', 'Manage high-privilege and sensitive roles', 'Role Management'],
        ['approve_role_changes', 'Approve role and permission changes', 'Role Management'],
        ['review_role_assignments', 'Review and validate role assignments', 'Role Management'],
        ['manage_role_restrictions', 'Set restrictions and limitations on roles', 'Role Management'],
        ['enforce_role_policies', 'Enforce role-based security policies', 'Role Management'],
        ['validate_role_compliance', 'Validate role compliance with policies', 'Role Management'],

        // Role Templates and Presets
        ['create_role_templates', 'Create role templates for common use cases', 'Role Management'],
        ['edit_role_templates', 'Edit existing role templates', 'Role Management'],
        ['delete_role_templates', 'Delete role templates', 'Role Management'],
        ['apply_role_templates', 'Apply role templates to create new roles', 'Role Management'],
        ['manage_role_presets', 'Manage predefined role configurations', 'Role Management'],
        ['import_role_templates', 'Import role templates from external sources', 'Role Management'],
        ['export_role_templates', 'Export role templates for reuse', 'Role Management'],

        // Advanced Role Features
        ['manage_dynamic_roles', 'Manage roles that change based on conditions', 'Role Management'],
        ['manage_contextual_permissions', 'Manage permissions that vary by context', 'Role Management'],
        ['manage_time_based_roles', 'Manage roles with time-based restrictions', 'Role Management'],
        ['manage_location_based_roles', 'Manage roles with location-based restrictions', 'Role Management'],
        ['manage_conditional_permissions', 'Set up conditional permission logic', 'Role Management'],
        ['manage_role_workflows', 'Create workflows for role approval and assignment', 'Role Management'],

        // Role Integration and API
        ['integrate_external_roles', 'Integrate with external role management systems', 'Role Management'],
        ['sync_role_systems', 'Synchronize roles with external systems', 'Role Management'],
        ['manage_role_api', 'Manage role management API access', 'Role Management'],
        ['import_roles_from_ldap', 'Import roles and permissions from LDAP/AD', 'Role Management'],
        ['export_roles_to_external', 'Export role data to external systems', 'Role Management'],

        // Role System Administration
        ['configure_role_settings', 'Configure role management system settings', 'Role Management'],
        ['manage_role_defaults', 'Set default roles for new users', 'Role Management'],
        ['backup_role_data', 'Backup and restore role and permission data', 'Role Management'],
        ['migrate_role_data', 'Migrate roles between systems or versions', 'Role Management'],
        ['optimize_role_performance', 'Optimize role checking and permission validation', 'Role Management'],
        ['troubleshoot_role_issues', 'Diagnose and fix role-related problems', 'Role Management'],

        // Role Documentation and Training
        ['document_roles', 'Create and maintain role documentation', 'Role Management'],
        ['manage_role_help_content', 'Manage help and training content for roles', 'Role Management'],
        ['create_role_training_materials', 'Create training materials for role usage', 'Role Management'],
        ['manage_role_onboarding', 'Manage role-based user onboarding processes', 'Role Management'],

        // Inventory Management - Comprehensive Permissions
        ['view_inventory', 'View inventory levels and stock information', 'Inventory Management'],
        ['manage_inventory', 'Full inventory management access', 'Inventory Management'],
        ['edit_inventory', 'Edit inventory levels and product details', 'Inventory Management'],
        ['adjust_inventory', 'Make inventory adjustments and corrections', 'Inventory Management'],
        ['transfer_inventory', 'Transfer inventory between locations', 'Inventory Management'],
        ['reserve_inventory', 'Reserve inventory for orders and allocations', 'Inventory Management'],

        // Stock Management
        ['view_stock_levels', 'View current stock levels across all locations', 'Inventory Management'],
        ['manage_stock_levels', 'Manage and update stock levels', 'Inventory Management'],
        ['manage_stock_adjustments', 'Make inventory adjustments and corrections', 'Inventory Management'],
        ['approve_stock_adjustments', 'Approve stock adjustment requests', 'Inventory Management'],
        ['view_stock_movements', 'View stock movement history and transactions', 'Inventory Management'],
        ['manage_reorder_points', 'Set and manage product reorder points', 'Inventory Management'],
        ['manage_safety_stock', 'Manage safety stock levels', 'Inventory Management'],

        // Purchase Orders
        ['view_purchase_orders', 'View purchase orders and details', 'Inventory Management'],
        ['create_purchase_orders', 'Create new purchase orders', 'Inventory Management'],
        ['edit_purchase_orders', 'Edit existing purchase orders', 'Inventory Management'],
        ['delete_purchase_orders', 'Delete purchase orders', 'Inventory Management'],
        ['approve_purchase_orders', 'Approve purchase orders for processing', 'Inventory Management'],
        ['send_purchase_orders', 'Send purchase orders to suppliers', 'Inventory Management'],
        ['cancel_purchase_orders', 'Cancel purchase orders', 'Inventory Management'],
        ['duplicate_purchase_orders', 'Duplicate existing purchase orders', 'Inventory Management'],

        // Inventory Receiving
        ['receive_inventory', 'Receive and process inventory deliveries', 'Inventory Management'],
        ['partial_receive_inventory', 'Process partial inventory receipts', 'Inventory Management'],
        ['verify_received_inventory', 'Verify received inventory against orders', 'Inventory Management'],
        ['reject_received_inventory', 'Reject received inventory items', 'Inventory Management'],
        ['manage_receiving_discrepancies', 'Handle receiving discrepancies', 'Inventory Management'],

        // Inventory Locations
        ['manage_inventory_locations', 'Manage warehouse locations and zones', 'Inventory Management'],
        ['view_inventory_locations', 'View inventory location information', 'Inventory Management'],
        ['transfer_between_locations', 'Transfer inventory between locations', 'Inventory Management'],
        ['manage_location_capacity', 'Manage location storage capacity', 'Inventory Management'],

        // Inventory Counting & Audits
        ['perform_inventory_counts', 'Perform physical inventory counts', 'Inventory Management'],
        ['manage_cycle_counts', 'Manage cycle counting schedules', 'Inventory Management'],
        ['approve_inventory_adjustments', 'Approve inventory count adjustments', 'Inventory Management'],
        ['view_inventory_variance_reports', 'View inventory variance reports', 'Inventory Management'],
        ['conduct_inventory_audits', 'Conduct inventory audits', 'Inventory Management'],

        // Inventory Forecasting & Planning
        ['view_demand_forecasting', 'View inventory demand forecasts', 'Inventory Management'],
        ['manage_demand_forecasting', 'Manage demand forecasting parameters', 'Inventory Management'],
        ['manage_seasonal_adjustments', 'Manage seasonal inventory adjustments', 'Inventory Management'],
        ['optimize_inventory_levels', 'Use inventory optimization tools', 'Inventory Management'],

        // Inventory Reporting & Analytics
        ['view_inventory_reports', 'View inventory reports and analytics', 'Inventory Management'],
        ['generate_inventory_reports', 'Generate custom inventory reports', 'Inventory Management'],
        ['export_inventory_data', 'Export inventory data and reports', 'Inventory Management'],
        ['view_inventory_analytics', 'View advanced inventory analytics', 'Inventory Management'],
        ['view_abc_analysis', 'View ABC analysis reports', 'Inventory Management'],
        ['view_inventory_turnover', 'View inventory turnover analysis', 'Inventory Management'],
        ['view_dead_stock_reports', 'View dead stock and obsolete inventory reports', 'Inventory Management'],

        // Inventory Valuation
        ['manage_inventory_valuation', 'Manage inventory valuation methods', 'Inventory Management'],
        ['view_inventory_valuation', 'View inventory valuation reports', 'Inventory Management'],
        ['adjust_inventory_costs', 'Adjust inventory cost values', 'Inventory Management'],
        ['manage_costing_methods', 'Manage inventory costing methods (FIFO, LIFO, etc.)', 'Inventory Management'],

        // Serial Numbers & Lot Tracking
        ['manage_serial_numbers', 'Manage serial number tracking', 'Inventory Management'],
        ['track_lot_numbers', 'Track lot numbers and batch information', 'Inventory Management'],
        ['manage_expiry_tracking', 'Manage expiry date tracking', 'Inventory Management'],
        ['trace_inventory_history', 'Trace inventory item history', 'Inventory Management'],

        // Low Stock & Alerts
        ['manage_low_stock_alerts', 'Manage low stock alert settings', 'Inventory Management'],
        ['view_low_stock_alerts', 'View low stock alerts and notifications', 'Inventory Management'],
        ['manage_overstock_alerts', 'Manage overstock alert settings', 'Inventory Management'],
        ['auto_reorder_management', 'Manage automatic reorder functionality', 'Inventory Management'],

        // Inventory Integration
        ['sync_inventory_systems', 'Synchronize with external inventory systems', 'Inventory Management'],
        ['import_inventory_data', 'Import inventory data from external sources', 'Inventory Management'],
        ['export_inventory_feeds', 'Export inventory feeds to external systems', 'Inventory Management'],
        ['manage_inventory_api', 'Manage inventory API access and integrations', 'Inventory Management'],

        // Advanced Inventory Features
        ['manage_backorders', 'Manage backorder processing', 'Inventory Management'],
        ['manage_pre_orders', 'Manage pre-order inventory allocation', 'Inventory Management'],
        ['manage_drop_shipping', 'Manage drop shipping inventory', 'Inventory Management'],
        ['manage_consignment_inventory', 'Manage consignment inventory tracking', 'Inventory Management'],
        ['manage_kitting', 'Manage inventory kitting and bundling', 'Inventory Management'],

        // Inventory System Administration
        ['configure_inventory_settings', 'Configure inventory system settings', 'Inventory Management'],
        ['manage_inventory_categories', 'Manage inventory categorization', 'Inventory Management'],
        ['manage_inventory_templates', 'Create and manage inventory templates', 'Inventory Management'],
        ['backup_inventory_data', 'Backup and restore inventory data', 'Inventory Management'],
        ['audit_inventory_changes', 'View inventory change logs and audit trails', 'Inventory Management'],

        // Returns Management - Comprehensive Permissions
        ['view_returns', 'View return history and details', 'Returns Management'],
        ['create_returns', 'Create new product return requests', 'Returns Management'],
        ['edit_returns', 'Edit existing return requests', 'Returns Management'],
        ['delete_returns', 'Delete return requests', 'Returns Management'],
        ['manage_returns', 'Full returns management access', 'Returns Management'],
        ['cancel_returns', 'Cancel return requests', 'Returns Management'],
        ['duplicate_returns', 'Duplicate existing return requests', 'Returns Management'],

        // Return Authorization & Approval
        ['approve_returns', 'Approve product returns for processing', 'Returns Management'],
        ['reject_returns', 'Reject return requests', 'Returns Management'],
        ['authorize_returns', 'Authorize returns without full approval process', 'Returns Management'],
        ['review_return_requests', 'Review and evaluate return requests', 'Returns Management'],
        ['expedite_returns', 'Expedite high-priority return processing', 'Returns Management'],

        // Return Processing
        ['process_returns', 'Process approved returns', 'Returns Management'],
        ['receive_returned_items', 'Receive and inspect returned items', 'Returns Management'],
        ['inspect_returned_items', 'Inspect condition of returned items', 'Returns Management'],
        ['evaluate_return_condition', 'Evaluate and categorize return item conditions', 'Returns Management'],
        ['accept_returned_items', 'Accept returned items into inventory', 'Returns Management'],
        ['reject_returned_items', 'Reject returned items', 'Returns Management'],

        // Return Reasons & Categories
        ['manage_return_reasons', 'Manage return reason codes and categories', 'Returns Management'],
        ['view_return_reasons', 'View return reason analysis', 'Returns Management'],
        ['categorize_returns', 'Categorize returns by type and reason', 'Returns Management'],

        // Return Item Management
        ['manage_return_items', 'Manage individual items in return requests', 'Returns Management'],
        ['partial_return_processing', 'Process partial returns', 'Returns Management'],
        ['split_return_orders', 'Split return orders into multiple shipments', 'Returns Management'],
        ['consolidate_returns', 'Consolidate multiple returns from same customer', 'Returns Management'],

        // Return Shipping & Logistics
        ['manage_return_shipping', 'Manage return shipping and logistics', 'Returns Management'],
        ['generate_return_labels', 'Generate return shipping labels', 'Returns Management'],
        ['track_return_shipments', 'Track return shipment status', 'Returns Management'],
        ['manage_return_carriers', 'Manage return shipping carriers', 'Returns Management'],
        ['calculate_return_shipping_costs', 'Calculate return shipping costs', 'Returns Management'],

        // Return Refunds & Credits
        ['process_return_refunds', 'Process refunds for returned items', 'Returns Management'],
        ['issue_store_credit', 'Issue store credit for returns', 'Returns Management'],
        ['manage_return_exchanges', 'Manage product exchanges', 'Returns Management'],
        ['calculate_return_value', 'Calculate return value and refund amounts', 'Returns Management'],
        ['apply_return_fees', 'Apply restocking or processing fees', 'Returns Management'],
        ['waive_return_fees', 'Waive return fees and charges', 'Returns Management'],

        // Return Inventory Management
        ['return_to_inventory', 'Return items back to available inventory', 'Returns Management'],
        ['quarantine_returned_items', 'Quarantine returned items for inspection', 'Returns Management'],
        ['dispose_returned_items', 'Dispose of damaged or unsellable returned items', 'Returns Management'],
        ['restock_returned_items', 'Restock returned items to inventory', 'Returns Management'],
        ['manage_return_inventory_locations', 'Manage return inventory locations', 'Returns Management'],

        // Return Quality Control
        ['perform_return_quality_checks', 'Perform quality checks on returned items', 'Returns Management'],
        ['certify_returned_items', 'Certify returned items as sellable', 'Returns Management'],
        ['grade_return_conditions', 'Grade and categorize return item conditions', 'Returns Management'],
        ['manage_return_warranties', 'Manage warranty claims for returned items', 'Returns Management'],

        // Return Analytics & Reporting
        ['view_return_reports', 'View return analytics and reports', 'Returns Management'],
        ['generate_return_reports', 'Generate custom return reports', 'Returns Management'],
        ['export_return_data', 'Export return data and analytics', 'Returns Management'],
        ['view_return_trends', 'View return trends and patterns', 'Returns Management'],
        ['analyze_return_reasons', 'Analyze return reasons and patterns', 'Returns Management'],
        ['view_return_costs', 'View return processing costs and impact', 'Returns Management'],
        ['monitor_return_fraud', 'Monitor for fraudulent return activities', 'Returns Management'],

        // Return Customer Management
        ['manage_return_customers', 'Manage customer return profiles and history', 'Returns Management'],
        ['view_customer_return_history', 'View individual customer return history', 'Returns Management'],
        ['flag_return_customers', 'Flag customers with suspicious return patterns', 'Returns Management'],
        ['manage_return_policies', 'Manage return policies and customer communications', 'Returns Management'],

        // Return Documentation & Communication
        ['manage_return_attachments', 'Manage return documentation and attachments', 'Returns Management'],
        ['generate_return_documentation', 'Generate return receipts and documentation', 'Returns Management'],
        ['manage_return_correspondence', 'Manage customer correspondence regarding returns', 'Returns Management'],

        // Advanced Return Features
        ['bulk_process_returns', 'Process multiple returns in bulk', 'Returns Management'],
        ['automated_return_processing', 'Manage automated return processing rules', 'Returns Management'],
        ['cross_reference_returns', 'Cross-reference returns with original orders', 'Returns Management'],
        ['manage_return_exceptions', 'Handle exceptional return cases', 'Returns Management'],

        // Return System Administration
        ['configure_return_settings', 'Configure return system settings', 'Returns Management'],
        ['manage_return_workflows', 'Manage return processing workflows', 'Returns Management'],
        ['manage_return_templates', 'Create and manage return document templates', 'Returns Management'],
        ['backup_return_data', 'Backup and restore return data', 'Returns Management'],
        ['audit_return_changes', 'View return change logs and audit trails', 'Returns Management'],

        // Return Integration
        ['sync_return_systems', 'Synchronize with external return systems', 'Returns Management'],
        ['integrate_return_carriers', 'Integrate with shipping carriers for returns', 'Returns Management'],
        ['manage_return_api', 'Manage return API access and integrations', 'Returns Management'],

        // Basic Supplier Management
        ['view_suppliers', 'View supplier information and listings', 'Supplier Management'],
        ['manage_suppliers', 'Add, edit, delete suppliers', 'Supplier Management'],
        ['create_suppliers', 'Create new suppliers', 'Supplier Management'],
        ['edit_suppliers', 'Edit existing supplier information', 'Supplier Management'],
        ['delete_suppliers', 'Delete suppliers from system', 'Supplier Management'],
        ['activate_suppliers', 'Activate inactive suppliers', 'Supplier Management'],
        ['deactivate_suppliers', 'Deactivate active suppliers', 'Supplier Management'],
        ['import_suppliers', 'Import suppliers from external files', 'Supplier Management'],
        ['export_suppliers', 'Export supplier data', 'Supplier Management'],
        ['bulk_manage_suppliers', 'Perform bulk operations on suppliers', 'Supplier Management'],
        ['duplicate_suppliers', 'Duplicate existing suppliers', 'Supplier Management'],

        // Supplier Contact Management
        ['manage_supplier_contacts', 'Manage supplier contact information', 'Supplier Management'],
        ['add_supplier_contacts', 'Add new contacts for suppliers', 'Supplier Management'],
        ['edit_supplier_contacts', 'Edit supplier contact details', 'Supplier Management'],
        ['delete_supplier_contacts', 'Remove supplier contacts', 'Supplier Management'],
        ['view_supplier_contact_history', 'View supplier contact interaction history', 'Supplier Management'],

        // Supplier Performance Management
        ['view_supplier_performance', 'View supplier performance metrics and dashboards', 'Supplier Management'],
        ['manage_supplier_performance', 'Manage supplier performance tracking and metrics', 'Supplier Management'],
        ['create_performance_reviews', 'Create supplier performance reviews', 'Supplier Management'],
        ['edit_performance_reviews', 'Edit supplier performance reviews', 'Supplier Management'],
        ['approve_performance_reviews', 'Approve supplier performance evaluations', 'Supplier Management'],
        ['view_performance_trends', 'View supplier performance trends and analytics', 'Supplier Management'],
        ['set_performance_targets', 'Set performance targets and KPIs for suppliers', 'Supplier Management'],
        ['manage_performance_scorecards', 'Manage supplier performance scorecards', 'Supplier Management'],
        ['generate_performance_reports', 'Generate supplier performance reports', 'Supplier Management'],
        ['benchmark_supplier_performance', 'Compare and benchmark supplier performance', 'Supplier Management'],

        // Supplier Quality Management
        ['view_supplier_quality', 'View supplier quality metrics and issues', 'Supplier Management'],
        ['manage_supplier_quality', 'Manage supplier quality tracking and issues', 'Supplier Management'],
        ['create_quality_issues', 'Create quality issue reports for suppliers', 'Supplier Management'],
        ['edit_quality_issues', 'Edit supplier quality issue details', 'Supplier Management'],
        ['resolve_quality_issues', 'Resolve and close supplier quality issues', 'Supplier Management'],
        ['track_quality_improvements', 'Track supplier quality improvement initiatives', 'Supplier Management'],
        ['manage_quality_audits', 'Manage supplier quality audits and inspections', 'Supplier Management'],
        ['approve_quality_actions', 'Approve corrective actions for quality issues', 'Supplier Management'],
        ['view_quality_trends', 'View supplier quality trends and patterns', 'Supplier Management'],
        ['manage_quality_certifications', 'Manage supplier quality certifications', 'Supplier Management'],

        // Supplier BOM Management
        ['manage_bom_suppliers', 'Manage supplier assignments for BOM components', 'Supplier Management'],
        ['view_bom_suppliers', 'View BOM supplier assignments and relationships', 'Supplier Management'],
        ['assign_bom_suppliers', 'Assign suppliers to BOM components', 'Supplier Management'],
        ['optimize_bom_suppliers', 'Optimize supplier selections for BOMs', 'Supplier Management'],
        ['manage_supplier_alternates', 'Manage alternate suppliers for BOM components', 'Supplier Management'],
        ['track_bom_supplier_costs', 'Track costs for BOM suppliers', 'Supplier Management'],
        ['analyze_bom_supplier_performance', 'Analyze BOM supplier performance', 'Supplier Management'],

        // Supplier Cost Management
        ['view_supplier_costs', 'View supplier cost information and history', 'Supplier Management'],
        ['manage_supplier_costs', 'Manage supplier cost tracking and analysis', 'Supplier Management'],
        ['negotiate_supplier_prices', 'Access supplier price negotiation tools', 'Supplier Management'],
        ['approve_cost_changes', 'Approve supplier cost changes and adjustments', 'Supplier Management'],
        ['track_cost_history', 'Track historical supplier cost changes', 'Supplier Management'],
        ['compare_supplier_costs', 'Compare costs across multiple suppliers', 'Supplier Management'],
        ['manage_volume_discounts', 'Manage volume-based supplier discounts', 'Supplier Management'],
        ['optimize_supplier_costs', 'Use cost optimization tools and analysis', 'Supplier Management'],
        ['forecast_supplier_costs', 'Forecast future supplier costs and trends', 'Supplier Management'],

        // Supplier Contract Management
        ['view_supplier_contracts', 'View supplier contracts and agreements', 'Supplier Management'],
        ['manage_supplier_contracts', 'Create and manage supplier contracts', 'Supplier Management'],
        ['create_supplier_contracts', 'Create new supplier contracts', 'Supplier Management'],
        ['edit_supplier_contracts', 'Edit existing supplier contracts', 'Supplier Management'],
        ['approve_supplier_contracts', 'Approve supplier contracts and agreements', 'Supplier Management'],
        ['renew_supplier_contracts', 'Process supplier contract renewals', 'Supplier Management'],
        ['terminate_supplier_contracts', 'Terminate supplier contracts', 'Supplier Management'],
        ['track_contract_compliance', 'Track supplier contract compliance', 'Supplier Management'],
        ['manage_contract_terms', 'Manage supplier contract terms and conditions', 'Supplier Management'],
        ['alert_contract_expiry', 'Manage contract expiry alerts and notifications', 'Supplier Management'],

        // Supplier Document Management
        ['view_supplier_documents', 'View supplier documents and attachments', 'Supplier Management'],
        ['manage_supplier_documents', 'Upload and manage supplier documents', 'Supplier Management'],
        ['upload_supplier_documents', 'Upload documents to supplier records', 'Supplier Management'],
        ['delete_supplier_documents', 'Delete supplier documents', 'Supplier Management'],
        ['organize_supplier_documents', 'Organize and categorize supplier documents', 'Supplier Management'],
        ['track_document_versions', 'Track supplier document versions and history', 'Supplier Management'],
        ['manage_document_approvals', 'Manage supplier document approval workflows', 'Supplier Management'],
        ['track_document_expiry', 'Track supplier document expiration dates', 'Supplier Management'],
        ['share_supplier_documents', 'Share supplier documents with team members', 'Supplier Management'],

        // Supplier Communication
        ['communicate_with_suppliers', 'Send messages and communications to suppliers', 'Supplier Management'],
        ['manage_supplier_messages', 'Manage supplier communication history', 'Supplier Management'],
        ['schedule_supplier_meetings', 'Schedule and manage supplier meetings', 'Supplier Management'],
        ['track_supplier_interactions', 'Track all supplier interactions and communications', 'Supplier Management'],
        ['manage_supplier_feedback', 'Manage supplier feedback and surveys', 'Supplier Management'],
        ['broadcast_to_suppliers', 'Send broadcast messages to multiple suppliers', 'Supplier Management'],

        // Supplier Onboarding & Setup
        ['onboard_new_suppliers', 'Manage new supplier onboarding process', 'Supplier Management'],
        ['verify_supplier_credentials', 'Verify supplier credentials and documentation', 'Supplier Management'],
        ['setup_supplier_accounts', 'Set up new supplier accounts and access', 'Supplier Management'],
        ['train_suppliers', 'Provide training and resources to suppliers', 'Supplier Management'],
        ['manage_supplier_profiles', 'Manage detailed supplier profile information', 'Supplier Management'],
        ['validate_supplier_information', 'Validate and verify supplier information', 'Supplier Management'],

        // Supplier Financial Management
        ['view_supplier_financials', 'View supplier financial information and health', 'Supplier Management'],
        ['manage_supplier_payments', 'Manage supplier payment processes', 'Supplier Management'],
        ['track_supplier_invoices', 'Track and manage supplier invoices', 'Supplier Management'],
        ['manage_payment_terms', 'Manage supplier payment terms and conditions', 'Supplier Management'],
        ['process_supplier_payments', 'Process payments to suppliers', 'Supplier Management'],
        ['manage_supplier_credit', 'Manage supplier credit limits and terms', 'Supplier Management'],
        ['analyze_supplier_spending', 'Analyze spending patterns with suppliers', 'Supplier Management'],
        ['forecast_supplier_payments', 'Forecast future supplier payment obligations', 'Supplier Management'],
        ['manage_supplier_disputes', 'Manage payment and billing disputes with suppliers', 'Supplier Management'],

        // Supplier Risk Management
        ['assess_supplier_risk', 'Assess and evaluate supplier risks', 'Supplier Management'],
        ['manage_supplier_risk', 'Manage supplier risk mitigation strategies', 'Supplier Management'],
        ['monitor_supplier_health', 'Monitor supplier financial and operational health', 'Supplier Management'],
        ['create_risk_assessments', 'Create supplier risk assessment reports', 'Supplier Management'],
        ['track_risk_indicators', 'Track key supplier risk indicators', 'Supplier Management'],
        ['manage_risk_mitigation', 'Manage supplier risk mitigation plans', 'Supplier Management'],
        ['alert_high_risk_suppliers', 'Receive alerts for high-risk suppliers', 'Supplier Management'],

        // Supplier Compliance Management
        ['manage_supplier_compliance', 'Manage supplier compliance requirements', 'Supplier Management'],
        ['track_compliance_status', 'Track supplier compliance status', 'Supplier Management'],
        ['audit_supplier_compliance', 'Conduct supplier compliance audits', 'Supplier Management'],
        ['manage_compliance_documents', 'Manage supplier compliance documentation', 'Supplier Management'],
        ['track_certifications', 'Track supplier certifications and licenses', 'Supplier Management'],
        ['monitor_regulatory_changes', 'Monitor regulatory changes affecting suppliers', 'Supplier Management'],
        ['ensure_compliance_standards', 'Ensure suppliers meet compliance standards', 'Supplier Management'],

        // Supplier Analytics & Reporting
        ['view_supplier_analytics', 'View supplier analytics and insights', 'Supplier Management'],
        ['generate_supplier_reports', 'Generate comprehensive supplier reports', 'Supplier Management'],
        ['export_supplier_analytics', 'Export supplier analytics and data', 'Supplier Management'],
        ['analyze_supplier_trends', 'Analyze supplier performance trends', 'Supplier Management'],
        ['benchmark_suppliers', 'Benchmark suppliers against industry standards', 'Supplier Management'],
        ['create_supplier_dashboards', 'Create custom supplier dashboards', 'Supplier Management'],
        ['schedule_supplier_reports', 'Schedule automated supplier reports', 'Supplier Management'],

        // Advanced Supplier Features
        ['manage_supplier_categories', 'Manage supplier categories and classifications', 'Supplier Management'],
        ['setup_supplier_hierarchies', 'Set up supplier organizational hierarchies', 'Supplier Management'],
        ['manage_supplier_relationships', 'Manage complex supplier relationships', 'Supplier Management'],
        ['integrate_supplier_systems', 'Integrate with supplier systems and portals', 'Supplier Management'],
        ['automate_supplier_processes', 'Automate supplier management processes', 'Supplier Management'],
        ['manage_supplier_workflows', 'Manage supplier approval and process workflows', 'Supplier Management'],

        // Supplier System Administration
        ['configure_supplier_settings', 'Configure supplier management system settings', 'Supplier Management'],
        ['manage_supplier_templates', 'Manage supplier document and communication templates', 'Supplier Management'],
        ['backup_supplier_data', 'Backup and restore supplier data', 'Supplier Management'],
        ['audit_supplier_changes', 'View supplier change logs and audit trails', 'Supplier Management'],
        ['migrate_supplier_data', 'Migrate supplier data between systems', 'Supplier Management'],
        ['optimize_supplier_database', 'Optimize supplier database performance', 'Supplier Management'],

        // Reports & Analytics
        ['view_sales_reports', 'View sales reports and analytics', 'Reports & Analytics'],
        ['view_inventory_reports', 'View inventory reports', 'Reports & Analytics'],
        ['view_financial_reports', 'View financial reports', 'Reports & Analytics'],
        ['export_reports', 'Export report data', 'Reports & Analytics'],

        // System Settings - Comprehensive Permissions
        ['view_settings', 'View system settings and configurations', 'System Settings'],
        ['manage_settings', 'Full system settings management access', 'System Settings'],
        ['edit_general_settings', 'Edit general system settings', 'System Settings'],
        ['configure_company_settings', 'Configure company information and branding', 'System Settings'],
        ['manage_currency_settings', 'Manage currency and financial settings', 'System Settings'],
        ['configure_tax_settings', 'Configure tax rates and tax-related settings', 'System Settings'],

        // POS Settings
        ['manage_pos_settings', 'Manage point-of-sale system configurations', 'System Settings'],
        ['configure_payment_methods', 'Configure accepted payment methods', 'System Settings'],
        ['manage_barcode_settings', 'Configure barcode generation and scanning settings', 'System Settings'],

        // Communication Settings
        ['manage_notification_settings', 'Manage system notification preferences', 'System Settings'],
        ['configure_sms_settings', 'Configure SMS gateway and messaging settings', 'System Settings'],

        // Security and Authentication Settings
        ['configure_security_settings', 'Configure system security and authentication settings', 'System Settings'],
        ['manage_password_policies', 'Set password strength and policy requirements', 'System Settings'],
        ['configure_session_settings', 'Configure user session timeouts and security', 'System Settings'],
        ['manage_login_security', 'Configure login attempt limits and lockout policies', 'System Settings'],
        ['configure_2fa_settings', 'Configure two-factor authentication settings', 'System Settings'],
        ['manage_ip_restrictions', 'Manage IP address access restrictions', 'System Settings'],

        // Inventory and Stock Settings
        ['configure_inventory_settings', 'Configure inventory management system settings', 'System Settings'],
        ['manage_stock_alert_settings', 'Configure low stock and reorder alert settings', 'System Settings'],
        ['configure_auto_reorder_settings', 'Configure automatic reorder system settings', 'System Settings'],
        ['manage_product_numbering', 'Configure product SKU and numbering schemes', 'System Settings'],

        // Order Management Settings
        ['configure_order_settings', 'Configure purchase order system settings', 'System Settings'],
        ['manage_order_numbering', 'Configure order numbering and formatting', 'System Settings'],
        ['configure_order_workflows', 'Configure order approval and processing workflows', 'System Settings'],
        ['manage_supplier_settings', 'Configure supplier management settings', 'System Settings'],

        // Return Management Settings
        ['configure_return_settings', 'Configure return processing system settings', 'System Settings'],
        ['manage_return_policies', 'Configure return policies and rules', 'System Settings'],
        ['configure_return_workflows', 'Configure return approval and processing workflows', 'System Settings'],

        // User and Role Settings
        ['configure_user_settings', 'Configure user management system settings', 'System Settings'],
        ['manage_default_roles', 'Configure default roles for new users', 'System Settings'],
        ['configure_user_registration', 'Configure user registration and onboarding settings', 'System Settings'],
        ['manage_user_session_settings', 'Configure user session and activity settings', 'System Settings'],

        // Backup and Maintenance Settings
        ['configure_backup_settings', 'Configure automatic backup schedules and settings', 'System Settings'],
        ['manage_backup_retention', 'Configure backup retention and cleanup policies', 'System Settings'],
        ['configure_maintenance_mode', 'Enable and configure system maintenance mode', 'System Settings'],
        ['manage_system_cleanup', 'Configure automatic system cleanup and archiving', 'System Settings'],

        // Performance and Optimization Settings
        ['configure_performance_settings', 'Configure system performance and optimization settings', 'System Settings'],
        ['manage_cache_settings', 'Configure caching and performance optimization', 'System Settings'],
        ['configure_database_settings', 'Configure database performance and maintenance settings', 'System Settings'],
        ['manage_log_settings', 'Configure system logging and log retention', 'System Settings'],

        // Integration and API Settings
        ['configure_api_settings', 'Configure API access and integration settings', 'System Settings'],
        ['manage_external_integrations', 'Manage third-party service integrations', 'System Settings'],
        ['configure_webhook_settings', 'Configure webhooks and external notifications', 'System Settings'],
        ['manage_sync_settings', 'Configure data synchronization settings', 'System Settings'],

        // Theme and Appearance Settings
        ['configure_theme_settings', 'Configure system theme and appearance', 'System Settings'],
        ['manage_ui_customization', 'Manage user interface customization options', 'System Settings'],
        ['configure_branding_settings', 'Configure company branding and logos', 'System Settings'],
        ['manage_color_schemes', 'Manage system color schemes and themes', 'System Settings'],

        // Localization and Regional Settings
        ['configure_localization_settings', 'Configure language and localization settings', 'System Settings'],
        ['manage_timezone_settings', 'Configure timezone and date/time format settings', 'System Settings'],
        ['configure_regional_formats', 'Configure regional number and currency formats', 'System Settings'],
        ['manage_language_packs', 'Manage language packs and translations', 'System Settings'],

        // Reporting and Analytics Settings
        ['configure_reporting_settings', 'Configure reporting system settings', 'System Settings'],
        ['manage_analytics_settings', 'Configure analytics and tracking settings', 'System Settings'],
        ['configure_dashboard_settings', 'Configure default dashboard layouts and widgets', 'System Settings'],
        ['manage_report_scheduling', 'Configure automated report generation and delivery', 'System Settings'],

        // License and Compliance Settings
        ['manage_license_settings', 'Manage system license and activation settings', 'System Settings'],
        ['configure_compliance_settings', 'Configure regulatory compliance settings', 'System Settings'],
        ['manage_audit_settings', 'Configure audit logging and compliance tracking', 'System Settings'],
        ['configure_data_retention', 'Configure data retention and archival policies', 'System Settings'],

        // Advanced System Configuration
        ['configure_advanced_settings', 'Configure advanced system settings', 'System Settings'],
        ['manage_feature_flags', 'Enable and disable system features and modules', 'System Settings'],
        ['configure_system_limits', 'Configure system resource limits and quotas', 'System Settings'],
        ['manage_custom_fields', 'Configure custom fields and data structures', 'System Settings'],

        // System Monitoring and Health
        ['view_system_status', 'View system health and status information', 'System Settings'],
        ['monitor_system_performance', 'Monitor system performance metrics', 'System Settings'],
        ['manage_system_alerts', 'Configure system health alerts and notifications', 'System Settings'],
        ['view_system_logs', 'View system logs and error reports', 'System Settings'],

        // Emergency and Recovery Settings
        ['configure_emergency_settings', 'Configure emergency access and recovery settings', 'System Settings'],
        ['manage_disaster_recovery', 'Configure disaster recovery and backup procedures', 'System Settings'],
        ['configure_failover_settings', 'Configure system failover and redundancy settings', 'System Settings'],
        ['manage_emergency_contacts', 'Configure emergency contact information', 'System Settings'],

        // Module-Specific Settings
        ['configure_bom_settings', 'Configure BOM system settings', 'System Settings'],
        ['configure_auto_bom_settings', 'Configure Auto BOM system settings', 'System Settings'],
        ['configure_expiry_settings', 'Configure product expiry tracking settings', 'System Settings'],
        ['configure_expense_settings', 'Configure expense management settings', 'System Settings'],
        ['configure_role_settings', 'Configure role management system settings', 'System Settings'],

        // Export and Import Settings
        ['export_system_settings', 'Export system configuration settings', 'System Settings'],
        ['import_system_settings', 'Import system configuration settings', 'System Settings'],
        ['backup_system_configuration', 'Create backups of system configuration', 'System Settings'],
        ['restore_system_configuration', 'Restore system configuration from backups', 'System Settings']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description, category) VALUES (:name, :description, :category)");
    foreach ($permissions as $permission) {
        $stmt->bindParam(':name', $permission[0]);
        $stmt->bindParam(':description', $permission[1]);
        $stmt->bindParam(':category', $permission[2]);
        $stmt->execute();
    }

    // Add quotation permissions
    $quotation_permissions = [
        ['name' => 'manage_quotations', 'description' => 'Full access to create, edit, and delete quotations', 'category' => 'Quotations'],
        ['name' => 'create_quotations', 'description' => 'Create new quotations', 'category' => 'Quotations'],
        ['name' => 'view_quotations', 'description' => 'View existing quotations', 'category' => 'Quotations'],
        ['name' => 'edit_quotations', 'description' => 'Edit existing quotations', 'category' => 'Quotations'],
        ['name' => 'delete_quotations', 'description' => 'Delete quotations', 'category' => 'Quotations'],
        ['name' => 'approve_quotations', 'description' => 'Approve or reject quotations', 'category' => 'Quotations'],
        ['name' => 'convert_quotations_to_sales', 'description' => 'Convert approved quotations to sales', 'category' => 'Quotations']
    ];

    foreach ($quotation_permissions as $permission) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO permissions (name, description, category, created_at, updated_at)
                VALUES (:name, :description, :category, NOW(), NOW())
                ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category), updated_at = NOW()
            ");
            $stmt->execute($permission);
        } catch (Exception $e) {
            // Permission might already exist
            error_log("Quotation permission '{$permission['name']}' creation warning: " . $e->getMessage());
        }
    }

    // Clear existing role permissions for default roles to avoid conflicts
    $conn->exec("DELETE FROM role_permissions WHERE role_id IN (1, 2)");

    // Assign permissions to Admin role (all permissions)
    $admin_role_id = 1;
    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id)
                            SELECT :role_id, id FROM permissions");
    $stmt->bindParam(':role_id', $admin_role_id);
    $stmt->execute();

    // Assign permissions to Cashier role (minimal, POS-focused)
    $cashier_role_id = 2;
    $cashier_permissions = [
        'view_dashboard',      // allow landing on dashboard
        'process_sales',       // POS access
        'view_customers',      // customer lookup for POS
        'create_quotations',   // create quotations
        'view_quotations',     // view quotations
        'edit_quotations'      // edit quotations
        // Add more only if needed: 'view_held_transactions','create_held_transactions','resume_held_transactions'
    ];
    $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id)
                            SELECT :role_id, id FROM permissions WHERE name IN ('" . implode("','", $cashier_permissions) . "')");
    $stmt->bindParam(':role_id', $cashier_role_id);
    $stmt->execute();

    // Return reasons will be created by users as needed
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

    // Ensure cashier role redirects to POS by default
    try {
        $conn->exec("UPDATE roles SET redirect_url = '../pos/sale.php' WHERE name = 'Cashier' AND (redirect_url IS NULL OR redirect_url = '../dashboard/dashboard.php')");
    } catch (PDOException $e) {
        error_log('Redirect URL update for Cashier skipped: ' . $e->getMessage());
    }

    // Seed role_menu_access defaults for Admin and Cashier
    try {
        // Build menu sections map
        $sections = [];
        $stmt = $conn->query("SELECT id, section_key FROM menu_sections WHERE is_active = 1");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sections[$row['section_key']] = (int)$row['id'];
        }

        // Prepared insert (INSERT IGNORE to avoid duplicates)
        $ins = $conn->prepare("INSERT IGNORE INTO role_menu_access (role_id, menu_section_id, is_visible, is_priority) VALUES (:role, :menu, :visible, :priority)");

        // Admin: see all, prioritized
        foreach ($sections as $menuId) {
            $ins->execute([':role' => 1, ':menu' => $menuId, ':visible' => 1, ':priority' => 1]);
        }

        // Cashier: hide all by default, explicitly enable Customer CRM only
        foreach ($sections as $key => $menuId) {
            $visible = ($key === 'customer_crm') ? 1 : 0;
            $priority = 0;
            $ins->execute([':role' => 2, ':menu' => $menuId, ':visible' => $visible, ':priority' => $priority]);
        }
    } catch (PDOException $e) {
        error_log('Seeding role_menu_access failed: ' . $e->getMessage());
    }


    // Add tax management permissions
    $tax_permissions = [
        ['manage_taxes', 'Manage tax categories and rates', 'Tax Management'],
        ['view_tax_reports', 'View tax reports and analytics', 'Tax Management'],
        ['manage_tax_exemptions', 'Manage tax exemptions for customers and products', 'Tax Management'],
        ['configure_tax_settings', 'Configure tax calculation settings', 'Tax Management']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description, category) VALUES (?, ?, ?)");
    foreach ($tax_permissions as $permission) {
        $stmt->execute($permission);
    }

    // Assign tax permissions to Admin role
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 1, id FROM permissions WHERE name IN ('manage_taxes', 'view_tax_reports', 'manage_tax_exemptions', 'configure_tax_settings')");
    $stmt->execute();

    // Add supplier_block_note field to suppliers table if it doesn't exist
    $stmt = $conn->prepare("SHOW COLUMNS FROM suppliers LIKE 'supplier_block_note'");
    $stmt->execute();
    $result = $stmt->fetch();

    if (!$result) {
        $conn->exec("ALTER TABLE suppliers ADD COLUMN supplier_block_note TEXT COMMENT 'Required note when supplier is blocked/deactivated'");
    }

    // Standardize product_families table structure
    try {
        // Check if product_families table exists and what columns it has
        $stmt = $conn->query("SHOW COLUMNS FROM product_families");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If table has is_active but not status, add status column
        if (in_array('is_active', $columns) && !in_array('status', $columns)) {
            $conn->exec("ALTER TABLE product_families ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active' AFTER description");
            // Copy is_active values to status
            $conn->exec("UPDATE product_families SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END");
            // Add index for status
            $conn->exec("CREATE INDEX idx_families_status ON product_families (status)");
        }
        
        // If table has family_name but not name, add name column
        if (in_array('family_name', $columns) && !in_array('name', $columns)) {
            $conn->exec("ALTER TABLE product_families ADD COLUMN name VARCHAR(255) NOT NULL AFTER id");
            // Copy family_name values to name
            $conn->exec("UPDATE product_families SET name = family_name");
            // Add index for name
            $conn->exec("CREATE INDEX idx_families_name ON product_families (name)");
        }
    } catch (PDOException $e) {
        // Table might not exist or other issues, continue silently
    }

    // Add new product fields to existing products table
    $productFields = [
        'description' => "ALTER TABLE products ADD COLUMN description TEXT AFTER name",
        'sku' => "ALTER TABLE products ADD COLUMN sku VARCHAR(100) UNIQUE AFTER category_id",
        'product_number' => "ALTER TABLE products ADD COLUMN product_number VARCHAR(100) UNIQUE COMMENT 'Internal product number for tracking' AFTER sku",
        'product_type' => "ALTER TABLE products ADD COLUMN product_type ENUM('physical', 'digital', 'service', 'subscription') DEFAULT 'physical' AFTER product_number",
        'barcode' => "ALTER TABLE products ADD COLUMN barcode VARCHAR(50) UNIQUE DEFAULT NULL AFTER reorder_point",
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
        "CREATE INDEX idx_sku ON products (sku)",
        "CREATE INDEX idx_product_type ON products (product_type)",
        "CREATE INDEX idx_status ON products (status)",
        "CREATE INDEX idx_brand ON products (brand)",
        "CREATE INDEX idx_brand_id ON products (brand_id)",
        "CREATE INDEX idx_supplier_id ON products (supplier_id)"
    ];

    foreach ($indexes as $indexSql) {
        try {
            $conn->exec($indexSql);
        } catch (PDOException $e) {
            // Index might already exist, continue
            continue;
        }
    }

    // Add indexes for new product fields
    $productIndexes = [
        "ALTER TABLE products ADD INDEX idx_product_number (product_number)",
        "ALTER TABLE products ADD INDEX idx_product_type (product_type)",
        "ALTER TABLE products ADD INDEX idx_barcode (barcode)"
    ];

    foreach ($productIndexes as $indexSql) {
        try {
            $conn->exec($indexSql);
        } catch (PDOException $e) {
            // Index might already exist, continue silently
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
        ['company_website', ''],
        ['company_logo', ''],
        ['currency_symbol', 'KES'],
        ['currency_position', 'before'],
        ['currency_decimal_places', '2'],
        ['tax_rate', '16'],
        ['tax_name', 'VAT'],
        ['tax_registration_number', ''],
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
        ['order_auto_approval', '1'],
        ['order_reminder_days', '3'],
        ['order_expiry_days', '30'],
        ['order_auto_approve', '1'],
        ['order_require_approval', '0'],
        ['order_notification_sms', '0'],
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
        ['return_allow_attachments', '1'],
        ['return_max_attachment_size', '5242880'],
        ['return_allowed_file_types', 'jpg,jpeg,png,pdf,doc,docx'],
        ['return_auto_update_inventory', '1'],

        // Add expiry tracker settings
        ['expiry_alert_enabled', '1'],
        ['expiry_default_alert_days', '30'],
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

    // Note: Automatic demo data generation removed to prevent fake performance metrics

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
            UNIQUE KEY unique_category_name_parent (name, parent_id),
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
            UNIQUE KEY unique_payment_method_name (name),
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

    // Fix foreign key constraint if it references wrong table
    try {
        // Check if expense_approvals table exists and has wrong foreign key
        $check_stmt = $conn->query("
            SELECT
                CONSTRAINT_NAME,
                REFERENCED_TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'expense_approvals'
            AND REFERENCED_TABLE_NAME IS NOT NULL
            AND REFERENCED_TABLE_NAME != 'expenses'
        ");
        $wrong_constraints = $check_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Drop wrong foreign key constraints
        foreach ($wrong_constraints as $constraint) {
            if ($constraint['REFERENCED_TABLE_NAME'] === 'expense_entries') {
                $conn->exec("ALTER TABLE expense_approvals DROP FOREIGN KEY {$constraint['CONSTRAINT_NAME']}");
            }
        }

        // Ensure correct foreign key constraint exists
        $fk_check = $conn->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'expense_approvals'
            AND REFERENCED_TABLE_NAME = 'expenses'
            AND COLUMN_NAME = 'expense_id'
        ");

        if ($fk_check->rowCount() == 0) {
            $conn->exec("
                ALTER TABLE expense_approvals
                ADD CONSTRAINT fk_expense_approvals_expense_id
                FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE
            ");
        }
    } catch (Exception $e) {
        // Silently continue if constraint fix fails
        error_log("Constraint fix warning: " . $e->getMessage());
    }

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

    // Insert default expense categories only if they don't exist
    $stmt = $conn->query("SELECT COUNT(*) as count FROM expense_categories");
    $existing_categories = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Expense categories will be created by users as needed


    // Payment methods will be created by users as needed

    // Departments will be created by users as needed

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

    // CLEANUP: Remove old/deprecated permissions and fix categories
    try {
        // Remove old manage_products permission that conflicts with new granular permissions
        $stmt = $conn->prepare("DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE name = 'manage_products')");
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM permissions WHERE name = 'manage_products'");
        $stmt->execute();

        // Fix permission categories that might be incorrectly assigned
        $category_fixes = [
            // Move inventory-specific permissions to Inventory Management
            "UPDATE permissions SET category = 'Inventory Management' WHERE name = 'manage_inventory' AND category != 'Inventory Management'",
            "UPDATE permissions SET category = 'Inventory Management' WHERE name = 'manage_production_orders' AND category != 'Inventory Management'",

            // Ensure all product-related permissions are in Product Management
            "UPDATE permissions SET category = 'Product Management' WHERE name LIKE '%product%' AND category != 'Product Management'",
            "UPDATE permissions SET category = 'Product Management' WHERE name = 'manage_categories' AND category != 'Product Management'",

            // Fix other categories
            "UPDATE permissions SET category = 'User Management' WHERE name LIKE '%user%' AND category != 'User Management'",
            "UPDATE permissions SET category = 'Role Management' WHERE name LIKE '%role%' AND category != 'Role Management'",
            "UPDATE permissions SET category = 'Sales & Transactions' WHERE name LIKE '%sale%' AND category != 'Sales & Transactions'",
            "UPDATE permissions SET category = 'Returns Management' WHERE name LIKE '%return%' AND category != 'Returns Management'",
            "UPDATE permissions SET category = 'Supplier Management' WHERE name LIKE '%supplier%' AND category != 'Supplier Management'",
            "UPDATE permissions SET category = 'Reports & Analytics' WHERE name LIKE '%report%' AND category != 'Reports & Analytics'",
            "UPDATE permissions SET category = 'System Settings' WHERE name LIKE '%setting%' AND category != 'System Settings'"
        ];

        foreach ($category_fixes as $fix) {
            $conn->exec($fix);
        }

        // Update categories for specific BOM and expense permissions
        $specific_category_updates = [
            ['manage_boms', 'BOM Management'],
            ['view_boms', 'BOM Management'],
            ['approve_boms', 'BOM Management'],
            ['manage_production_orders', 'BOM Management'],
            ['view_production_reports', 'BOM Management'],
            ['manage_bom_costing', 'BOM Management'],

            ['manage_auto_boms', 'Auto BOM Management'],
            ['view_auto_boms', 'Auto BOM Management'],
            ['manage_auto_bom_pricing', 'Auto BOM Management'],
            ['view_auto_bom_pricing', 'Auto BOM Management'],
            ['view_auto_bom_reports', 'Auto BOM Management'],

            ['manage_expenses', 'Expense Management'],
            ['create_expenses', 'Expense Management'],
            ['edit_expenses', 'Expense Management'],
            ['delete_expenses', 'Expense Management'],
            ['approve_expenses', 'Expense Management'],
            ['view_expense_reports', 'Expense Management'],
            ['manage_expense_categories', 'Expense Management'],
            ['manage_expense_departments', 'Expense Management'],
            ['manage_expense_vendors', 'Expense Management'],
            ['manage_expense_budgets', 'Expense Management'],
            ['export_expenses', 'Expense Management'],

            ['manage_expiry_tracker', 'Expiry Management'],
            ['view_expiry_alerts', 'Expiry Management'],
            ['handle_expired_items', 'Expiry Management'],
            ['configure_expiry_alerts', 'Expiry Management']
        ];

        $category_update_stmt = $conn->prepare("UPDATE permissions SET category = ? WHERE name = ?");
        foreach ($specific_category_updates as $update) {
            $category_update_stmt->execute([$update[1], $update[0]]);
        }

        // Clean up any duplicate permissions (keep the first one)
        $conn->exec("
            DELETE p1 FROM permissions p1
            INNER JOIN permissions p2
            WHERE p1.id > p2.id AND p1.name = p2.name
        ");

        // Re-assign all permissions to Admin role to ensure nothing is missing
        $conn->exec("DELETE FROM role_permissions WHERE role_id = 1");
        $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions");
        $stmt->execute();

        error_log("Permission cleanup completed successfully");

    } catch (PDOException $e) {
        // Log cleanup error but don't fail the entire database initialization
        error_log("Warning: Permission cleanup failed: " . $e->getMessage());
    }

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

    // Create BOM (Bill of Materials) tables
    $conn->exec("
        CREATE TABLE IF NOT EXISTS bom_headers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bom_number VARCHAR(50) NOT NULL UNIQUE,
            product_id INT NOT NULL COMMENT 'Finished product this BOM creates',
            name VARCHAR(255) NOT NULL,
            description TEXT,
            version INT DEFAULT 1,
            status ENUM('draft', 'active', 'obsolete', 'archived') DEFAULT 'draft',
            total_cost DECIMAL(12, 2) DEFAULT 0 COMMENT 'Calculated total cost of all components',
            labor_cost DECIMAL(10, 2) DEFAULT 0 COMMENT 'Additional labor costs',
            overhead_cost DECIMAL(10, 2) DEFAULT 0 COMMENT 'Manufacturing overhead costs',
            total_quantity INT DEFAULT 1 COMMENT 'Quantity this BOM produces',
            unit_of_measure VARCHAR(50) DEFAULT 'each',
            effective_date DATE DEFAULT NULL,
            expiry_date DATE DEFAULT NULL,
            created_by INT NOT NULL,
            approved_by INT DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_bom_number (bom_number),
            INDEX idx_product_id (product_id),
            INDEX idx_status (status),
            INDEX idx_version (version),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS bom_components (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bom_id INT NOT NULL,
            component_product_id INT NOT NULL COMMENT 'Raw material/component product',
            quantity_required DECIMAL(10, 3) NOT NULL COMMENT 'Quantity needed per BOM unit',
            unit_of_measure VARCHAR(50) DEFAULT 'each',
            waste_percentage DECIMAL(5, 2) DEFAULT 0 COMMENT 'Expected waste/loss percentage',
            quantity_with_waste DECIMAL(10, 3) DEFAULT 0 COMMENT 'Quantity including waste',
            unit_cost DECIMAL(10, 2) DEFAULT 0 COMMENT 'Cost per unit of component',
            total_cost DECIMAL(10, 2) DEFAULT 0 COMMENT 'Total cost for this component',
            supplier_id INT DEFAULT NULL COMMENT 'Preferred supplier for this component',
            is_alternative TINYINT(1) DEFAULT 0 COMMENT 'Is this an alternative component',
            alternative_group VARCHAR(100) DEFAULT NULL COMMENT 'Group alternatives belong to',
            sequence_number INT DEFAULT 0 COMMENT 'Manufacturing sequence order',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
            FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            INDEX idx_bom_id (bom_id),
            INDEX idx_component_product_id (component_product_id),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_alternative_group (alternative_group),
            INDEX idx_sequence_number (sequence_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS bom_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bom_id INT NOT NULL,
            version_number INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            total_cost DECIMAL(12, 2) DEFAULT 0,
            labor_cost DECIMAL(10, 2) DEFAULT 0,
            overhead_cost DECIMAL(10, 2) DEFAULT 0,
            total_quantity INT DEFAULT 1,
            effective_date DATE DEFAULT NULL,
            created_by INT NOT NULL,
            change_reason TEXT COMMENT 'Reason for creating this version',
            is_current TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_bom_version (bom_id, version_number),
            INDEX idx_bom_id (bom_id),
            INDEX idx_version_number (version_number),
            INDEX idx_is_current (is_current),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS bom_production_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            production_order_number VARCHAR(50) NOT NULL UNIQUE,
            bom_id INT NOT NULL,
            quantity_to_produce INT NOT NULL,
            status ENUM('planned', 'in_progress', 'completed', 'cancelled', 'on_hold') DEFAULT 'planned',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            planned_start_date DATETIME DEFAULT NULL,
            actual_start_date DATETIME DEFAULT NULL,
            planned_completion_date DATETIME DEFAULT NULL,
            actual_completion_date DATETIME DEFAULT NULL,
            total_material_cost DECIMAL(12, 2) DEFAULT 0,
            total_labor_cost DECIMAL(12, 2) DEFAULT 0,
            total_overhead_cost DECIMAL(12, 2) DEFAULT 0,
            total_production_cost DECIMAL(12, 2) DEFAULT 0,
            created_by INT NOT NULL,
            assigned_to INT DEFAULT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_production_order_number (production_order_number),
            INDEX idx_bom_id (bom_id),
            INDEX idx_status (status),
            INDEX idx_priority (priority),
            INDEX idx_created_by (created_by),
            INDEX idx_assigned_to (assigned_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS bom_production_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            production_order_id INT NOT NULL,
            component_product_id INT NOT NULL,
            required_quantity DECIMAL(10, 3) NOT NULL,
            allocated_quantity DECIMAL(10, 3) DEFAULT 0,
            used_quantity DECIMAL(10, 3) DEFAULT 0,
            unit_cost DECIMAL(10, 2) DEFAULT 0,
            total_cost DECIMAL(10, 2) DEFAULT 0,
            supplier_id INT DEFAULT NULL,
            status ENUM('pending', 'allocated', 'issued', 'returned', 'consumed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (production_order_id) REFERENCES bom_production_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            INDEX idx_production_order_id (production_order_id),
            INDEX idx_component_product_id (component_product_id),
            INDEX idx_status (status),
            INDEX idx_supplier_id (supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add BOM-related fields to products table if they don't exist
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'is_bom'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            $conn->exec("ALTER TABLE products ADD COLUMN is_bom TINYINT(1) DEFAULT 0 COMMENT 'Whether this product has a BOM'");
            $conn->exec("ALTER TABLE products ADD COLUMN bom_id INT DEFAULT NULL COMMENT 'Reference to the active BOM'");
            $conn->exec("ALTER TABLE products ADD COLUMN production_lead_time INT DEFAULT 0 COMMENT 'Lead time in days for production'");
            $conn->exec("ALTER TABLE products ADD COLUMN minimum_order_quantity INT DEFAULT 1 COMMENT 'Minimum quantity for production order'");
            $conn->exec("ALTER TABLE products ADD COLUMN production_batch_size INT DEFAULT 1 COMMENT 'Standard batch size for production'");

            // Add foreign key constraint
            $conn->exec("ALTER TABLE products ADD CONSTRAINT fk_products_bom_id FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE SET NULL");

            // Add indexes
            $conn->exec("CREATE INDEX idx_is_bom ON products (is_bom)");
            $conn->exec("CREATE INDEX idx_bom_id ON products (bom_id)");
        }
    } catch (PDOException $e) {
        // Column might already exist, continue silently
        error_log("Warning: Could not add BOM fields to products table: " . $e->getMessage());
    }

    // Insert comprehensive BOM Management permissions
    $bom_permissions = [
        // Core BOM Operations
        ['create_boms', 'Create new Bill of Materials', 'BOM Management'],
        ['edit_boms', 'Edit existing Bill of Materials', 'BOM Management'],
        ['delete_boms', 'Delete Bill of Materials', 'BOM Management'],
        ['view_boms', 'View Bill of Materials and production information', 'BOM Management'],
        ['activate_boms', 'Activate/deactivate Bill of Materials', 'BOM Management'],
        ['approve_boms', 'Approve BOM changes and production orders', 'BOM Management'],
        ['clone_boms', 'Clone existing Bill of Materials', 'BOM Management'],

        // BOM Version Control
        ['manage_bom_versions', 'Create and manage BOM versions', 'BOM Management'],
        ['view_bom_versions', 'View BOM version history', 'BOM Management'],
        ['compare_bom_versions', 'Compare different BOM versions', 'BOM Management'],
        ['rollback_bom_versions', 'Rollback to previous BOM versions', 'BOM Management'],

        // BOM Components Management
        ['manage_bom_components', 'Add, edit, and remove BOM components', 'BOM Management'],
        ['view_bom_components', 'View BOM component details', 'BOM Management'],
        ['manage_bom_alternatives', 'Manage alternative components in BOMs', 'BOM Management'],
        ['calculate_bom_requirements', 'Calculate material requirements for BOMs', 'BOM Management'],

        // BOM Costing and Pricing
        ['manage_bom_costing', 'Manage BOM costing and pricing calculations', 'BOM Management'],
        ['view_bom_costing', 'View BOM cost breakdowns and analysis', 'BOM Management'],
        ['update_bom_costs', 'Update BOM component costs', 'BOM Management'],
        ['manage_bom_labor_costs', 'Manage labor costs for BOMs', 'BOM Management'],
        ['manage_bom_overhead_costs', 'Manage overhead costs for BOMs', 'BOM Management'],
        ['calculate_bom_margins', 'Calculate profit margins for BOMs', 'BOM Management'],

        // Production Orders
        ['create_production_orders', 'Create new production orders from BOMs', 'BOM Management'],
        ['edit_production_orders', 'Edit existing production orders', 'BOM Management'],
        ['delete_production_orders', 'Delete production orders', 'BOM Management'],
        ['manage_production_orders', 'Create and manage production orders', 'BOM Management'],
        ['view_production_orders', 'View production order details and status', 'BOM Management'],
        ['approve_production_orders', 'Approve production orders for execution', 'BOM Management'],
        ['start_production_orders', 'Start production order execution', 'BOM Management'],
        ['complete_production_orders', 'Mark production orders as completed', 'BOM Management'],
        ['cancel_production_orders', 'Cancel production orders', 'BOM Management'],
        ['assign_production_orders', 'Assign production orders to team members', 'BOM Management'],

        // Production Order Materials
        ['allocate_production_materials', 'Allocate materials to production orders', 'BOM Management'],
        ['issue_production_materials', 'Issue materials for production', 'BOM Management'],
        ['return_production_materials', 'Return unused materials from production', 'BOM Management'],
        ['track_production_consumption', 'Track actual material consumption', 'BOM Management'],

        // BOM Analytics and Reporting
        ['view_bom_reports', 'Access BOM analytics and reports', 'BOM Management'],
        ['view_production_reports', 'View production and BOM reports', 'BOM Management'],
        ['view_bom_performance', 'View BOM efficiency and performance metrics', 'BOM Management'],
        ['view_bom_profitability', 'View BOM profitability analysis', 'BOM Management'],
        ['export_bom_reports', 'Export BOM reports and data', 'BOM Management'],
        ['view_material_requirements', 'View material requirement planning reports', 'BOM Management'],
        ['view_production_capacity', 'View production capacity and scheduling reports', 'BOM Management'],

        // BOM Quality Control
        ['manage_bom_quality_control', 'Manage BOM quality control standards', 'BOM Management'],
        ['view_bom_quality_metrics', 'View BOM quality control metrics', 'BOM Management'],
        ['manage_bom_specifications', 'Manage BOM product specifications', 'BOM Management'],

        // BOM System Administration
        ['configure_bom_settings', 'Configure BOM system settings', 'BOM Management'],
        ['manage_bom_templates', 'Create and manage BOM templates', 'BOM Management'],
        ['import_boms', 'Import BOM data from external sources', 'BOM Management'],
        ['export_boms', 'Export BOM configurations and data', 'BOM Management'],
        ['audit_bom_changes', 'View BOM change logs and audit trails', 'BOM Management'],
        ['backup_bom_data', 'Backup and restore BOM data', 'BOM Management'],

        // BOM Integration
        ['sync_bom_inventory', 'Synchronize BOM components with inventory', 'BOM Management'],
        ['integrate_bom_purchasing', 'Integrate BOMs with purchasing system', 'BOM Management'],
        ['manage_bom_suppliers', 'Manage supplier assignments for BOM components', 'BOM Management'],

        // Advanced BOM Features
        ['manage_multilevel_boms', 'Manage multi-level/nested BOMs', 'BOM Management'],
        ['manage_bom_routings', 'Manage production routings and sequences', 'BOM Management'],
        ['optimize_bom_costs', 'Use BOM cost optimization tools', 'BOM Management'],
        ['simulate_bom_scenarios', 'Run BOM cost and production simulations', 'BOM Management']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description, category) VALUES (?, ?, ?)");
    foreach ($bom_permissions as $permission) {
        $stmt->bindParam(1, $permission[0]);
        $stmt->bindParam(2, $permission[1]);
        $stmt->bindParam(3, $permission[2]);
        $stmt->execute();
    }

    // Assign ALL BOM Management permissions to Admin role (role_id = 1)
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 1, id FROM permissions WHERE category = 'BOM Management'");
    $stmt->execute();

    // Assign limited BOM permissions to Cashier role (role_id = 2)
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 2, id FROM permissions WHERE name IN ('view_boms', 'view_bom_components', 'view_bom_costing', 'view_production_orders', 'view_production_reports', 'view_bom_reports')");
    $stmt->execute();

    // Add BOM settings
    $bom_settings = [
        ['bom_number_prefix', 'BOM'],
        ['bom_number_length', '6'],
        ['bom_number_separator', '-'],
        ['bom_auto_numbering', '1'],
        ['bom_default_version', '1'],
        ['bom_cost_calculation_method', 'standard'], // standard, fifo, lifo, average
        ['bom_include_overhead', '1'],
        ['bom_include_labor', '1'],
        ['bom_default_waste_percentage', '5'],
        ['bom_production_order_prefix', 'PROD'],
        ['bom_production_order_length', '6'],
        ['bom_auto_calculate_costs', '1'],
        ['bom_enable_version_control', '1'],
        ['bom_approval_required', '1'],
        ['bom_default_lead_time', '7'], // days
        ['bom_currency', 'KES']
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($bom_settings as $setting) {
        $stmt->execute($setting);
    }

    // Create Auto BOM tables
    $conn->exec("
        CREATE TABLE IF NOT EXISTS product_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            base_unit VARCHAR(50) NOT NULL,
            default_pricing_strategy ENUM('fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid') DEFAULT 'fixed',
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            config_name VARCHAR(255) NOT NULL,
            product_family_id INT DEFAULT NULL,
            base_product_id INT NOT NULL,
            base_unit VARCHAR(50) DEFAULT 'each',
            base_quantity DECIMAL(10,3) DEFAULT 1,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (product_family_id) REFERENCES product_families(id) ON DELETE SET NULL,
            FOREIGN KEY (base_product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_product_id (product_id),
            INDEX idx_base_product_id (base_product_id),
            INDEX idx_product_family_id (product_family_id),
            INDEX idx_is_active (is_active),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_selling_units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auto_bom_config_id INT NOT NULL,
            unit_name VARCHAR(100) NOT NULL,
            unit_quantity DECIMAL(10,3) NOT NULL,
            unit_sku VARCHAR(100) UNIQUE,
            unit_barcode VARCHAR(50) UNIQUE,
            pricing_strategy ENUM('fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid') DEFAULT 'fixed',
            fixed_price DECIMAL(10,2) DEFAULT NULL,
            markup_percentage DECIMAL(5,2) DEFAULT 0,
            min_profit_margin DECIMAL(5,2) DEFAULT 0,
            market_price DECIMAL(10,2) DEFAULT NULL,
            dynamic_base_price DECIMAL(10,2) DEFAULT NULL,
            stock_level_threshold INT DEFAULT NULL,
            demand_multiplier DECIMAL(3,2) DEFAULT 1.0,
            hybrid_primary_strategy ENUM('fixed', 'cost_based', 'market_based') DEFAULT 'fixed',
            hybrid_threshold_value DECIMAL(10,2) DEFAULT NULL,
            hybrid_fallback_strategy ENUM('fixed', 'cost_based', 'market_based') DEFAULT 'cost_based',
            priority INT DEFAULT 0,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (auto_bom_config_id) REFERENCES auto_bom_configs(id) ON DELETE CASCADE,
            INDEX idx_auto_bom_config_id (auto_bom_config_id),
            INDEX idx_pricing_strategy (pricing_strategy),
            INDEX idx_status (status),
            INDEX idx_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS auto_bom_price_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            selling_unit_id INT NOT NULL,
            old_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            change_reason VARCHAR(255) DEFAULT 'manual_update',
            changed_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,

            INDEX idx_selling_unit_id (selling_unit_id),
            INDEX idx_created_at (created_at),
            INDEX idx_changed_by (changed_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add foreign key constraint for product_family_id in products table
    try {
        $conn->exec("ALTER TABLE products ADD CONSTRAINT fk_products_product_family FOREIGN KEY (product_family_id) REFERENCES product_families(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Foreign key might already exist, continue silently
    }

    // Add index for is_auto_bom_enabled
    try {
        $conn->exec("ALTER TABLE products ADD INDEX idx_auto_bom_enabled (is_auto_bom_enabled)");
    } catch (PDOException $e) {
        // Index might already exist, continue silently
    }

    // Create Auto BOM permissions - Comprehensive and Granular
    $auto_bom_permissions = [
        // Core Auto BOM Management
        ['create_auto_boms', 'Create new Auto BOM configurations', 'Auto BOM Management'],
        ['edit_auto_boms', 'Edit existing Auto BOM configurations', 'Auto BOM Management'],
        ['delete_auto_boms', 'Delete Auto BOM configurations', 'Auto BOM Management'],
        ['view_auto_boms', 'View Auto BOM configurations and units', 'Auto BOM Management'],
        ['activate_auto_boms', 'Activate/deactivate Auto BOM configurations', 'Auto BOM Management'],
        ['approve_auto_boms', 'Approve Auto BOM configurations for production use', 'Auto BOM Management'],

        // Auto BOM Configuration Management
        ['manage_auto_bom_configs', 'Manage Auto BOM configuration settings', 'Auto BOM Management'],
        ['clone_auto_bom_configs', 'Clone existing Auto BOM configurations', 'Auto BOM Management'],
        ['version_auto_bom_configs', 'Create and manage Auto BOM configuration versions', 'Auto BOM Management'],
        ['export_auto_bom_configs', 'Export Auto BOM configuration data', 'Auto BOM Management'],
        ['import_auto_bom_configs', 'Import Auto BOM configuration data', 'Auto BOM Management'],

        // Product Family Management
        ['manage_product_families', 'Create, edit, and manage product families', 'Auto BOM Management'],
        ['view_product_families', 'View product families and their configurations', 'Auto BOM Management'],
        ['assign_product_families', 'Assign products to product families', 'Auto BOM Management'],

        // Auto BOM Selling Units
        ['create_auto_bom_units', 'Create new Auto BOM selling units', 'Auto BOM Management'],
        ['edit_auto_bom_units', 'Edit existing Auto BOM selling units', 'Auto BOM Management'],
        ['delete_auto_bom_units', 'Delete Auto BOM selling units', 'Auto BOM Management'],
        ['view_auto_bom_units', 'View Auto BOM selling units', 'Auto BOM Management'],
        ['manage_auto_bom_unit_skus', 'Generate and manage SKUs for Auto BOM units', 'Auto BOM Management'],
        ['manage_auto_bom_unit_barcodes', 'Generate and manage barcodes for Auto BOM units', 'Auto BOM Management'],

        // Auto BOM Pricing Management
        ['view_auto_bom_pricing', 'View pricing configurations for Auto BOM units', 'Auto BOM Management'],
        ['edit_auto_bom_pricing', 'Edit pricing for Auto BOM units', 'Auto BOM Management'],
        ['manage_auto_bom_pricing_strategies', 'Configure pricing strategies (fixed, cost-based, dynamic, etc.)', 'Auto BOM Management'],
        ['view_auto_bom_price_history', 'View Auto BOM price change history', 'Auto BOM Management'],
        ['manage_auto_bom_markup', 'Manage markup percentages and profit margins', 'Auto BOM Management'],
        ['manage_auto_bom_discounts', 'Manage Auto BOM unit discounts and promotions', 'Auto BOM Management'],
        ['bulk_update_auto_bom_pricing', 'Perform bulk price updates on Auto BOM units', 'Auto BOM Management'],

        // Auto BOM Inventory Management
        ['view_auto_bom_inventory', 'View inventory levels for Auto BOM units', 'Auto BOM Management'],
        ['manage_auto_bom_inventory', 'Manage inventory tracking for Auto BOM units', 'Auto BOM Management'],
        ['track_auto_bom_stock_levels', 'Monitor and track Auto BOM unit stock levels', 'Auto BOM Management'],
        ['manage_auto_bom_reorder_points', 'Set and manage reorder points for Auto BOM units', 'Auto BOM Management'],

        // Auto BOM Analytics and Reporting
        ['view_auto_bom_reports', 'Access Auto BOM analytics and reports', 'Auto BOM Management'],
        ['view_auto_bom_performance', 'View Auto BOM unit sales and performance metrics', 'Auto BOM Management'],
        ['view_auto_bom_profitability', 'View Auto BOM unit profitability analysis', 'Auto BOM Management'],
        ['export_auto_bom_reports', 'Export Auto BOM reports and analytics', 'Auto BOM Management'],
        ['view_auto_bom_conversion_rates', 'View base product to selling unit conversion analytics', 'Auto BOM Management'],

        // Auto BOM System Administration
        ['configure_auto_bom_settings', 'Configure Auto BOM system settings', 'Auto BOM Management'],
        ['manage_auto_bom_templates', 'Create and manage Auto BOM configuration templates', 'Auto BOM Management'],
        ['audit_auto_bom_changes', 'View Auto BOM change logs and audit trails', 'Auto BOM Management'],
        ['backup_auto_bom_data', 'Backup and restore Auto BOM configuration data', 'Auto BOM Management'],

        // Auto BOM Integration
        ['sync_auto_bom_inventory', 'Synchronize Auto BOM units with main inventory system', 'Auto BOM Management'],
        ['integrate_auto_bom_pos', 'Integrate Auto BOM units with POS system', 'Auto BOM Management'],
        ['manage_auto_bom_api', 'Manage Auto BOM API access and integrations', 'Auto BOM Management'],
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description, category) VALUES (?, ?, ?)");
    foreach ($auto_bom_permissions as $permission) {
        $stmt->bindParam(1, $permission[0]);
        $stmt->bindParam(2, $permission[1]);
        $stmt->bindParam(3, $permission[2]);
        $stmt->execute();
    }

    // Assign ALL Auto BOM permissions to Admin role (role_id = 1)
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 1, id FROM permissions WHERE category = 'Auto BOM Management'");
    $stmt->execute();

    // Assign limited Auto BOM permissions to Cashier role (role_id = 2)
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                           SELECT 2, id FROM permissions WHERE name IN ('view_auto_boms', 'view_auto_bom_units', 'view_auto_bom_pricing', 'view_auto_bom_reports')");
    $stmt->execute();

    // Database initialized silently

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

    // Migration: Ensure is_tax_deductible column exists in expense_categories table
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM expense_categories LIKE 'is_tax_deductible'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            // Add missing is_tax_deductible column
            $conn->exec("ALTER TABLE expense_categories ADD COLUMN is_tax_deductible TINYINT(1) DEFAULT 0 AFTER color_code");

            // Update existing categories with appropriate tax deductible status
            $tax_deductible_categories = [
                'Rent', 'Utilities', 'Marketing', 'Supplies', 'Maintenance',
                'Travel', 'Insurance', 'Professional Services', 'Equipment',
                'Software', 'Office Supplies', 'Cleaning Supplies', 'Equipment Repair',
                'Facility Maintenance', 'Airfare', 'Hotel', 'Transportation'
            ];

            // Set tax deductible for business-related categories
            $placeholders = "'" . implode("','", $tax_deductible_categories) . "'";
            $conn->exec("UPDATE expense_categories SET is_tax_deductible = 1 WHERE name IN ($placeholders)");

            error_log("Migration completed: Added is_tax_deductible column to expense_categories table");
        }
    } catch (PDOException $e) {
        // Log migration error but don't fail the entire database initialization
        error_log("Warning: Could not add is_tax_deductible column to expense_categories: " . $e->getMessage());
    }

    // Migration: Ensure credit_limit and current_balance columns exist in expense_vendors table
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM expense_vendors LIKE 'credit_limit'");
        $stmt->execute();
        $credit_limit_result = $stmt->fetch();

        $stmt = $conn->prepare("SHOW COLUMNS FROM expense_vendors LIKE 'current_balance'");
        $stmt->execute();
        $current_balance_result = $stmt->fetch();

        if (!$credit_limit_result) {
            // Add missing credit_limit column
            $conn->exec("ALTER TABLE expense_vendors ADD COLUMN credit_limit DECIMAL(12, 2) DEFAULT 0 AFTER payment_terms");
            error_log("Migration completed: Added credit_limit column to expense_vendors table");
        }

        if (!$current_balance_result) {
            // Add missing current_balance column
            $conn->exec("ALTER TABLE expense_vendors ADD COLUMN current_balance DECIMAL(12, 2) DEFAULT 0 AFTER credit_limit");
            error_log("Migration completed: Added current_balance column to expense_vendors table");
        }
    } catch (PDOException $e) {
        // Log migration error but don't fail the entire database initialization
        error_log("Warning: Could not add credit_limit/current_balance columns to expense_vendors: " . $e->getMessage());
    }

    // Enhanced migration logic to ensure database compatibility
    try {
        // Check and update sale_items table for Auto BOM compatibility
        $stmt = $conn->query("DESCRIBE sale_items");
        $sale_items_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Add missing columns to sale_items table if they don't exist
        $missing_sale_items_columns = [
            'selling_unit_id' => 'INT DEFAULT NULL COMMENT "Auto BOM selling unit ID"',
            'product_name' => 'VARCHAR(255) NOT NULL DEFAULT "" COMMENT "Product name at time of sale"',
            'unit_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "Unit price at time of sale"',
            'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "Price per unit at time of sale"',
            'total_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "Total price for this line item"',
            'is_auto_bom' => 'TINYINT(1) DEFAULT 0 COMMENT "Whether this is an Auto BOM item"',
            'base_quantity_deducted' => 'DECIMAL(10,3) DEFAULT 0 COMMENT "Base quantity deducted from inventory"'
        ];

        foreach ($missing_sale_items_columns as $column_name => $column_def) {
            if (!in_array($column_name, $sale_items_columns)) {
                $conn->exec("ALTER TABLE sale_items ADD COLUMN $column_name $column_def");
                error_log("Added missing column: sale_items.$column_name");
            }
        }

        // Add foreign key for selling_unit_id if it doesn't exist
        try {
            $conn->exec("ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_selling_unit_id FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE SET NULL");
        } catch (PDOException $e) {
            // Foreign key might already exist, continue silently
        }

        // Add indexes for better performance
        try {
            $conn->exec("CREATE INDEX idx_sale_items_selling_unit ON sale_items(selling_unit_id)");
        } catch (PDOException $e) {
            // Index might already exist, continue silently
        }
        try {
            $conn->exec("CREATE INDEX idx_sale_items_auto_bom ON sale_items(is_auto_bom)");
        } catch (PDOException $e) {
            // Index might already exist, continue silently
        }

        // Update existing sale_items data
        $conn->exec("
            UPDATE sale_items si
            JOIN products p ON si.product_id = p.id
            SET si.product_name = p.name
            WHERE si.product_name = '' OR si.product_name IS NULL
        ");

        $conn->exec("
            UPDATE sale_items
            SET unit_price = price,
                total_price = (price * quantity)
            WHERE unit_price = 0 OR total_price = 0
        ");

        // Check and update sales table for cash handling
        $stmt = $conn->query("DESCRIBE sales");
        $sales_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing_sales_columns = [
            'cash_received' => 'DECIMAL(10,2) DEFAULT NULL COMMENT "Cash amount received"',
            'change_amount' => 'DECIMAL(10,2) DEFAULT 0 COMMENT "Change amount given"',
            'amount_given' => 'DECIMAL(10,2) DEFAULT NULL COMMENT "Total amount given by customer"',
            'balance_due' => 'DECIMAL(10,2) DEFAULT 0 COMMENT "Remaining balance due"'
        ];

        foreach ($missing_sales_columns as $column_name => $column_def) {
            if (!in_array($column_name, $sales_columns)) {
                $conn->exec("ALTER TABLE sales ADD COLUMN $column_name $column_def");
                error_log("Added missing column: sales.$column_name");
            }
        }

        // Ensure customer_phone column exists in sales table
        if (!in_array('customer_phone', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(20) DEFAULT '' AFTER customer_name");
            error_log("Added missing column: sales.customer_phone");
        }

        // Ensure customer_email column exists in sales table
        if (!in_array('customer_email', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_email VARCHAR(255) DEFAULT '' AFTER customer_phone");
            error_log("Added missing column: sales.customer_email");
        }

        // Ensure customer_id column exists in sales table
        if (!in_array('customer_id', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_id INT DEFAULT NULL AFTER till_id");
            $conn->exec("ALTER TABLE sales ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL");
            $conn->exec("ALTER TABLE sales ADD INDEX idx_customer_id (customer_id)");
            error_log("Added missing column: sales.customer_id");
        }

        // Ensure discount column exists in sales table
        if (!in_array('discount', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN discount DECIMAL(10,2) DEFAULT 0 AFTER total_amount");
            error_log("Added missing column: sales.discount");
        }

        // Ensure discount_amount column exists in sales table (for backward compatibility)
        if (!in_array('discount_amount', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0 AFTER discount");
            error_log("Added missing column: sales.discount_amount");
        }

        // Ensure subtotal column exists in sales table
        if (!in_array('subtotal', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN subtotal DECIMAL(10,2) DEFAULT 0 AFTER total_amount");
            error_log("Added missing column: sales.subtotal");
        }

        // Ensure tax_rate column exists in sales table
        if (!in_array('tax_rate', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN tax_rate DECIMAL(5,2) DEFAULT 0 AFTER subtotal");
            error_log("Added missing column: sales.tax_rate");
        }

        // Ensure split_payment column exists in sales table
        if (!in_array('split_payment', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN split_payment TINYINT(1) DEFAULT 0 AFTER payment_method");
            error_log("Added missing column: sales.split_payment");
        }

        // Ensure customer_notes column exists in sales table
        if (!in_array('customer_notes', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN customer_notes TEXT AFTER split_payment");
            error_log("Added missing column: sales.customer_notes");
        }

        // Ensure total_paid column exists in sales table
        if (!in_array('total_paid', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN total_paid DECIMAL(10,2) DEFAULT 0 AFTER final_amount");
            error_log("Added missing column: sales.total_paid");
        }

        // Ensure change_due column exists in sales table
        if (!in_array('change_due', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN change_due DECIMAL(10,2) DEFAULT 0 AFTER total_paid");
            error_log("Added missing column: sales.change_due");
        }

        // Ensure notes column exists in sales table
        if (!in_array('notes', $sales_columns)) {
            $conn->exec("ALTER TABLE sales ADD COLUMN notes TEXT DEFAULT NULL COMMENT 'General notes for the sale transaction' AFTER customer_notes");
            error_log("Added missing column: sales.notes");
        }

        // Add performance indexes for sales table
        try {
            $conn->exec("CREATE INDEX idx_sales_cash_received ON sales(cash_received)");
        } catch (PDOException $e) {
            // Index might already exist, continue silently
        }

        // Update price column in sale_items to have default value
        if (in_array('price', $sale_items_columns)) {
            try {
                $conn->exec("ALTER TABLE sale_items MODIFY COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0");
            } catch (Exception $e) {
                // Column modification might fail, continue silently
                error_log("Warning: Could not modify price column: " . $e->getMessage());
            }
        }

        // Update product_id column in sale_items to allow NULL values
        if (in_array('product_id', $sale_items_columns)) {
            try {
                // Check if column already allows NULL
                $checkStmt = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'product_id'");
                $columnInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($columnInfo && $columnInfo['Null'] === 'NO') {
                    error_log("product_id column does not allow NULL, applying migration...");

                    // First, drop the existing foreign key constraint
                    $conn->exec("ALTER TABLE sale_items DROP FOREIGN KEY IF EXISTS sale_items_ibfk_2");
                    error_log("Dropped existing foreign key constraint on sale_items.product_id");

                    // Modify the column to allow NULL values
                    $conn->exec("ALTER TABLE sale_items MODIFY COLUMN product_id INT NULL");
                    error_log("Updated sale_items.product_id to allow NULL values");

                    // Recreate the foreign key constraint with ON DELETE SET NULL
                    $conn->exec("ALTER TABLE sale_items ADD CONSTRAINT fk_sale_items_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");
                    error_log("Recreated foreign key constraint on sale_items.product_id with ON DELETE SET NULL");
                } else {
                    error_log("product_id column already allows NULL values");
                }
            } catch (Exception $e) {
                // Column modification might fail, continue silently
                error_log("Warning: Could not modify product_id column: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        // Log migration errors but don't fail database initialization
        error_log("Database migration warning: " . $e->getMessage());
    }

    // Migration: Add expiry tracking and approval fields to product_expiry_dates table
    try {
        // Check if expiry_tracking_number column exists
        $stmt = $conn->prepare("SHOW COLUMNS FROM product_expiry_dates LIKE 'expiry_tracking_number'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            error_log("Adding expiry tracking and approval fields to product_expiry_dates table...");
            
            // Add expiry_tracking_number column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN expiry_tracking_number VARCHAR(20) UNIQUE COMMENT 'Format: EXPT:000001'");
            error_log("Added expiry_tracking_number column");
            
            // Add approval_status column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN approval_status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft'");
            error_log("Added approval_status column");
            
            // Add submitted_by column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN submitted_by INT COMMENT 'User who submitted for approval'");
            error_log("Added submitted_by column");
            
            // Add approved_by column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN approved_by INT COMMENT 'User who approved'");
            error_log("Added approved_by column");
            
            // Add submitted_at column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN submitted_at DATETIME");
            error_log("Added submitted_at column");
            
            // Add approved_at column
            $conn->exec("ALTER TABLE product_expiry_dates ADD COLUMN approved_at DATETIME");
            error_log("Added approved_at column");
            
            // Add indexes
            $conn->exec("ALTER TABLE product_expiry_dates ADD INDEX idx_expiry_tracking_number (expiry_tracking_number)");
            $conn->exec("ALTER TABLE product_expiry_dates ADD INDEX idx_approval_status (approval_status)");
            error_log("Added indexes for expiry tracking and approval fields");
            
            // Generate tracking numbers for existing records
            $stmt = $conn->query("SELECT id FROM product_expiry_dates WHERE expiry_tracking_number IS NULL ORDER BY id");
            $existing_records = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($existing_records)) {
                $update_stmt = $conn->prepare("UPDATE product_expiry_dates SET expiry_tracking_number = ? WHERE id = ?");
                $counter = 1;
                
                foreach ($existing_records as $record_id) {
                    $tracking_number = 'EXPT:' . str_pad($counter, 6, '0', STR_PAD_LEFT);
                    $update_stmt->execute([$tracking_number, $record_id]);
                    $counter++;
                }
                
                error_log("Generated tracking numbers for " . count($existing_records) . " existing records");
            }
            
            error_log("Migration completed: Added expiry tracking and approval fields to product_expiry_dates table");
        } else {
            error_log("Expiry tracking fields already exist in product_expiry_dates table");
        }
    } catch (PDOException $e) {
        // Log migration error but don't fail the entire database initialization
        error_log("Warning: Could not add expiry tracking fields to product_expiry_dates: " . $e->getMessage());
    }

    // Migration: Add approve_expiry_items permission
    try {
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = 'approve_expiry_items'");
        $stmt->execute();
        $result = $stmt->fetch();

        if (!$result) {
            error_log("Adding approve_expiry_items permission...");
            
            $conn->exec("
                INSERT INTO permissions (name, description, category) 
                VALUES ('approve_expiry_items', 'Approve expiry date items for inventory management', 'Inventory')
            ");
            
            error_log("Added approve_expiry_items permission");
        } else {
            error_log("approve_expiry_items permission already exists");
        }
    } catch (PDOException $e) {
        error_log("Warning: Could not add approve_expiry_items permission: " . $e->getMessage());
    }



    // Create quotations table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS quotations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            quotation_number VARCHAR(50) UNIQUE NOT NULL,
            customer_id INT,
            customer_name VARCHAR(255),
            customer_phone VARCHAR(50),
            customer_address TEXT,
            user_id INT NOT NULL,
            subtotal DECIMAL(10,2) DEFAULT 0.00,
            tax_amount DECIMAL(10,2) DEFAULT 0.00,
            discount_amount DECIMAL(10,2) DEFAULT 0.00,
            final_amount DECIMAL(10,2) DEFAULT 0.00,
            quotation_status ENUM('draft', 'sent', 'approved', 'rejected', 'expired', 'converted') DEFAULT 'draft',
            valid_until DATE,
            notes TEXT,
            terms TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_id (customer_id),
            INDEX idx_user_id (user_id),
            INDEX idx_quotation_status (quotation_status),
            INDEX idx_valid_until (valid_until),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Update quotation_status ENUM to include 'converted' if needed
    try {
        $conn->exec("ALTER TABLE quotations MODIFY COLUMN quotation_status ENUM('draft', 'sent', 'approved', 'rejected', 'expired', 'converted') DEFAULT 'draft'");
    } catch (Exception $e) {
        // ENUM might already be updated, continue silently
        error_log("Quotation status ENUM update: " . $e->getMessage());
    }

    // Create quotation_items table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS quotation_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            quotation_id INT NOT NULL,
            product_id INT,
            product_name VARCHAR(255) NOT NULL,
            product_sku VARCHAR(100),
            quantity DECIMAL(10,3) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_quotation_id (quotation_id),
            INDEX idx_product_id (product_id),
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
        )
    ");

    // Add quotation settings
    $conn->exec("
        INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('quotation_auto_generate', '1'),
        ('quotation_prefix', 'QUO'),
        ('quotation_suffix', ''),
        ('quotation_length', '6'),
        ('quotation_format', 'prefix-date-number'),
        ('quotation_valid_days', '30'),
        ('quotation_terms', 'This quotation is valid for 30 days from the date of issue. Prices are subject to change without notice.'),
        ('quotation_footer', 'Thank you for your business!')
    ");

    // Add Employee ID settings
    $conn->exec("
        INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('employee_id_auto_generate', '0'),
        ('employee_id_prefix', 'EMP'),
        ('employee_id_suffix', ''),
        ('employee_id_number_length', '4'),
        ('employee_id_start_number', '1'),
        ('employee_id_separator', '-'),
        ('employee_id_include_year', '0'),
        ('employee_id_include_month', '0'),
        ('employee_id_reset_counter_yearly', '0'),
        ('employee_id_current_counter', '0')
    ");

    // Add user_id field to users table for 4-digit unique user IDs
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'user_id'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE users ADD COLUMN user_id VARCHAR(4) UNIQUE AFTER id");
            $conn->exec("CREATE INDEX idx_users_user_id ON users(user_id)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add user_id field to users table: " . $e->getMessage());
    }

    // Check if product_id column exists in auto_bom_configs
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_configs LIKE 'product_id'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN product_id INT DEFAULT NULL AFTER product_family_id");
            $conn->exec("ALTER TABLE auto_bom_configs ADD INDEX idx_product_id (product_id)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add product_id field to auto_bom_configs table: " . $e->getMessage());
    }

    // Check if config_name column exists in auto_bom_configs
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_configs LIKE 'config_name'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN config_name VARCHAR(255) DEFAULT NULL AFTER product_id");
            $conn->exec("ALTER TABLE auto_bom_configs ADD INDEX idx_config_name (config_name)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add config_name field to auto_bom_configs table: " . $e->getMessage());
    }

    // Check if base_unit column exists in auto_bom_configs
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_configs LIKE 'base_unit'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN base_unit VARCHAR(50) DEFAULT 'each' AFTER base_product_id");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add base_unit field to auto_bom_configs table: " . $e->getMessage());
    }

    // Check if base_quantity column exists in auto_bom_configs
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_configs LIKE 'base_quantity'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_configs ADD COLUMN base_quantity DECIMAL(10,3) DEFAULT 1 AFTER base_unit");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add base_quantity field to auto_bom_configs table: " . $e->getMessage());
    }

    // Check if auto_bom_config_id column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'auto_bom_config_id'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN auto_bom_config_id INT DEFAULT NULL AFTER config_id");
            $conn->exec("UPDATE auto_bom_selling_units SET auto_bom_config_id = config_id WHERE auto_bom_config_id IS NULL");
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_auto_bom_config_id (auto_bom_config_id)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add auto_bom_config_id field to auto_bom_selling_units table: " . $e->getMessage());
    }

    // Check if pricing_strategy column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'pricing_strategy'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN pricing_strategy ENUM('fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid') DEFAULT 'fixed' AFTER sku_suffix");
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_pricing_strategy (pricing_strategy)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add pricing_strategy field to auto_bom_selling_units table: " . $e->getMessage());
    }

    // Check if status column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'status'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active' AFTER is_active");
            $conn->exec("UPDATE auto_bom_selling_units SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END WHERE status = 'active'");
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_status (status)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add status field to auto_bom_selling_units table: " . $e->getMessage());
    }

    // Check if unit_quantity column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'unit_quantity'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN unit_quantity DECIMAL(10,3) DEFAULT 1 AFTER quantity_per_base");
            $conn->exec("UPDATE auto_bom_selling_units SET unit_quantity = quantity_per_base WHERE unit_quantity = 1");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add unit_quantity field to auto_bom_selling_units table: " . $e->getMessage());
    }

    // Check if unit_sku column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'unit_sku'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN unit_sku VARCHAR(100) UNIQUE AFTER unit_quantity");
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_unit_sku (unit_sku)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add unit_sku field to auto_bom_selling_units table: " . $e->getMessage());
    }

    // Check if unit_barcode column exists in auto_bom_selling_units
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM auto_bom_selling_units LIKE 'unit_barcode'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD COLUMN unit_barcode VARCHAR(50) UNIQUE AFTER unit_sku");
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_unit_barcode (unit_barcode)");
        }
    } catch (PDOException $e) {
        // Column might already exist
        error_log("Warning: Could not add unit_barcode field to auto_bom_selling_units table: " . $e->getMessage());
    }


    // ========================================
    // BUDGET MANAGEMENT SYSTEM TABLES
    // ========================================

    // Create budget_categories table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            parent_id INT DEFAULT NULL,
            color VARCHAR(7) DEFAULT '#6366f1',
            icon VARCHAR(50) DEFAULT 'bi-folder',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES budget_categories(id) ON DELETE SET NULL
        )
    ");

    // Create budgets table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            budget_type ENUM('monthly', 'quarterly', 'yearly', 'custom') DEFAULT 'monthly',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total_budget_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_actual_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) DEFAULT 'KES',
            status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
            approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by INT DEFAULT NULL,
            approved_at TIMESTAMP NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

    // Create budget_items table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_id INT NOT NULL,
            category_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            budgeted_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            actual_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            variance_amount DECIMAL(15,2) GENERATED ALWAYS AS (actual_amount - budgeted_amount) STORED,
            variance_percentage DECIMAL(8,2) GENERATED ALWAYS AS (
                CASE
                    WHEN budgeted_amount = 0 THEN NULL
                    ELSE ((actual_amount - budgeted_amount) / budgeted_amount) * 100
                END
            ) STORED,
            priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            notes TEXT,
            is_recurring BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE RESTRICT
        )
    ");

    // Create budget_transactions table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_id INT NOT NULL,
            budget_item_id INT NOT NULL,
            transaction_type ENUM('expense', 'revenue', 'adjustment') DEFAULT 'expense',
            amount DECIMAL(15,2) NOT NULL,
            transaction_date DATE NOT NULL,
            description TEXT,
            reference_type ENUM('expense', 'sale', 'manual', 'other') DEFAULT 'manual',
            reference_id INT DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
            FOREIGN KEY (budget_item_id) REFERENCES budget_items(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // Create budget_alerts table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_id INT NOT NULL,
            budget_item_id INT DEFAULT NULL,
            alert_type ENUM('threshold_warning', 'threshold_critical', 'overspent', 'underspent', 'deadline_approaching') NOT NULL,
            threshold_percentage DECIMAL(5,2) DEFAULT NULL,
            message TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            triggered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_for_user INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
            FOREIGN KEY (budget_item_id) REFERENCES budget_items(id) ON DELETE CASCADE,
            FOREIGN KEY (created_for_user) REFERENCES users(id) ON DELETE SET NULL
        )
    ");

    // Create budget_settings table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            setting_type ENUM('boolean', 'integer', 'decimal', 'string', 'json') DEFAULT 'string',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Create budget_templates table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            budget_type ENUM('monthly', 'quarterly', 'yearly', 'custom') DEFAULT 'monthly',
            created_by INT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_name (name),
            INDEX idx_budget_type (budget_type),
            INDEX idx_is_active (is_active),
            INDEX idx_created_by (created_by)
        )
    ");

    // Create budget_template_items table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS budget_template_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            category_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT,
            budgeted_amount DECIMAL(15,2) DEFAULT 0,
            percentage DECIMAL(5,2) DEFAULT 0 COMMENT 'Percentage of total budget if applicable',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (template_id) REFERENCES budget_templates(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE CASCADE,
            INDEX idx_template_id (template_id),
            INDEX idx_category_id (category_id)
        )
    ");

    // Budget categories will be created by users as needed

    // Budget settings will be configured by users as needed

    // Create indexes for better performance
    try {
        $conn->exec("CREATE INDEX idx_budgets_status ON budgets(status)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }
    try {
        $conn->exec("CREATE INDEX idx_budgets_dates ON budgets(start_date, end_date)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }
    try {
        $conn->exec("CREATE INDEX idx_budget_items_budget ON budget_items(budget_id)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }
    try {
        $conn->exec("CREATE INDEX idx_budget_transactions_budget ON budget_transactions(budget_id)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }
    try {
        $conn->exec("CREATE INDEX idx_budget_transactions_date ON budget_transactions(transaction_date)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }
    try {
        $conn->exec("CREATE INDEX idx_budget_alerts_active ON budget_alerts(is_active, is_read)");
    } catch (PDOException $e) {
        // Index might already exist, ignore error
    }

    // ========================================
    // FINANCE DASHBOARD PERMISSIONS
    // ========================================

    // Add finance dashboard permissions
    $conn->exec("
        INSERT IGNORE INTO permissions (name, description) VALUES
        ('view_finance', 'Access to Finance Dashboard and financial reports'),
        ('manage_budgets', 'Create, edit, and delete budgets'),
        ('view_budgets', 'View budget information and reports'),
        ('approve_budgets', 'Approve or reject budget requests'),
        ('manage_budget_categories', 'Manage budget categories'),
        ('view_financial_reports', 'Access to financial reports and analytics'),
        ('manage_budget_settings', 'Configure budget management settings')
    ");

    // Assign finance permissions to Admin role
    $conn->exec("
        INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT 1, id FROM permissions WHERE name IN (
            'view_finance', 'manage_budgets', 'view_budgets', 'approve_budgets',
            'manage_budget_categories', 'view_financial_reports', 'manage_budget_settings'
        )
    ");

    // Assign limited finance permissions to Manager role (if exists)
    $conn->exec("
        INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT 3, id FROM permissions WHERE name IN (
            'view_finance', 'view_budgets', 'view_financial_reports'
        )
    ");

    // Add finance permissions to permission groups
    $conn->exec("
        INSERT IGNORE INTO permission_groups (permission_name, group_name) VALUES
        ('view_finance', 'Finance Management'),
        ('manage_budgets', 'Finance Management'),
        ('view_budgets', 'Finance Management'),
        ('approve_budgets', 'Finance Management'),
        ('manage_budget_categories', 'Finance Management'),
        ('view_financial_reports', 'Finance Management'),
        ('manage_budget_settings', 'Finance Management')
    ");


    // Add security logs permissions
    $conn->exec("
        INSERT IGNORE INTO permissions (name, description, category) VALUES
        ('view_security_logs', 'View security logs and system events', 'Security'),
        ('manage_security_logs', 'Manage security logs and system monitoring', 'Security'),
        ('export_security_logs', 'Export security logs for analysis', 'Security')
    ");

    // Assign security logs permissions to Admin role
    $conn->exec("
        INSERT IGNORE INTO role_permissions (role_id, permission_id)
        SELECT 1, id FROM permissions WHERE name IN (
            'view_security_logs', 'manage_security_logs', 'export_security_logs'
        )
    ");

    // Add security logs permissions to permission groups
    $conn->exec("
        INSERT IGNORE INTO permission_groups (permission_name, group_name) VALUES
        ('view_security_logs', 'Security Management'),
        ('manage_security_logs', 'Security Management'),
        ('export_security_logs', 'Security Management')
    ");

    // Remove sales section from menu_sections if it exists (run every time)
    try {
        $conn->exec("DELETE FROM menu_sections WHERE section_key = 'sales'");
        $conn->exec("DELETE rma FROM role_menu_access rma
                     JOIN menu_sections ms ON rma.menu_section_id = ms.id
                     WHERE ms.section_key = 'sales'");
    } catch (Exception $e) {
        // Ignore errors if tables don't exist yet
    }

    // Create payment types table for reconciliation
    $conn->exec("
        CREATE TABLE IF NOT EXISTS payment_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            description TEXT,
            category ENUM('cash', 'digital', 'card', 'bank', 'other') DEFAULT 'other',
            icon VARCHAR(50) DEFAULT 'bi-cash',
            color VARCHAR(20) DEFAULT '#6c757d',
            is_active TINYINT(1) DEFAULT 1,
            requires_reconciliation TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_payment_type_name (name),
            INDEX idx_category (category),
            INDEX idx_is_active (is_active),
            INDEX idx_requires_reconciliation (requires_reconciliation),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Payment types will be created by users as needed

    // Final safeguard: restrict Cashier (role_id=2) to minimal permission set
    try {
        $allowed = [
            'view_dashboard',
            'process_sales',
            'view_customers'
        ];
        $placeholders = "'" . implode("','", $allowed) . "'";

        // Remove any extra permissions accidentally granted to Cashier
        $conn->exec("DELETE rp FROM role_permissions rp
                     LEFT JOIN permissions p ON rp.permission_id = p.id
                     WHERE rp.role_id = 2 AND (p.name IS NULL OR p.name NOT IN ($placeholders))");

        // Ensure the minimal allowed set is present
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                                SELECT 2, id FROM permissions WHERE name IN ($placeholders)");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log('Cashier minimal permission safeguard failed: ' . $e->getMessage());
    }


    // Update bank_transactions table to include payment type
    try {
        $conn->exec("
            ALTER TABLE bank_transactions
            ADD COLUMN payment_type_id INT DEFAULT NULL AFTER amount,
            ADD COLUMN payment_reference VARCHAR(255) DEFAULT NULL AFTER payment_type_id,
            ADD INDEX idx_payment_type_id (payment_type_id),
            ADD FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE SET NULL
        ");
    } catch (PDOException $e) {
        // Columns might already exist, ignore error
    }

    // Update transaction_matches table to include payment type
    try {
        $conn->exec("
            ALTER TABLE transaction_matches
            ADD COLUMN payment_type_id INT DEFAULT NULL AFTER match_amount,
            ADD COLUMN payment_reference VARCHAR(255) DEFAULT NULL AFTER payment_type_id,
            ADD INDEX idx_payment_type_id (payment_type_id),
            ADD FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE SET NULL
        ");
    } catch (PDOException $e) {
        // Columns might already exist, ignore error
    }

    // Create payment type reconciliation mapping table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS payment_type_account_mapping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_type_id INT NOT NULL,
            bank_account_id INT NOT NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE CASCADE,
            FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
            UNIQUE KEY unique_payment_account (payment_type_id, bank_account_id),
            INDEX idx_payment_type_id (payment_type_id),
            INDEX idx_bank_account_id (bank_account_id),
            INDEX idx_is_default (is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create payment types table for reconciliation
    $conn->exec("
        CREATE TABLE IF NOT EXISTS payment_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            description TEXT,
            category ENUM('cash', 'digital', 'card', 'bank', 'other') DEFAULT 'other',
            icon VARCHAR(50) DEFAULT 'bi-cash',
            color VARCHAR(20) DEFAULT '#6c757d',
            is_active TINYINT(1) DEFAULT 1,
            requires_reconciliation TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_payment_type_name (name),
            INDEX idx_category (category),
            INDEX idx_is_active (is_active),
            INDEX idx_requires_reconciliation (requires_reconciliation),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Payment types will be created by users as needed

    // Update bank_transactions table to include payment type
    try {
        $conn->exec("
            ALTER TABLE bank_transactions
            ADD COLUMN payment_type_id INT DEFAULT NULL AFTER amount,
            ADD COLUMN payment_reference VARCHAR(255) DEFAULT NULL AFTER payment_type_id,
            ADD INDEX idx_payment_type_id (payment_type_id),
            ADD FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE SET NULL
        ");
    } catch (PDOException $e) {
        // Columns might already exist, ignore error
    }

    // Update transaction_matches table to include payment type
    try {
        $conn->exec("
            ALTER TABLE transaction_matches
            ADD COLUMN payment_type_id INT DEFAULT NULL AFTER match_amount,
            ADD COLUMN payment_reference VARCHAR(255) DEFAULT NULL AFTER payment_type_id,
            ADD INDEX idx_payment_type_id (payment_type_id),
            ADD FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE SET NULL
        ");
    } catch (PDOException $e) {
        // Columns might already exist, ignore error
    }

    // Create payment type reconciliation mapping table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS payment_type_account_mapping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_type_id INT NOT NULL,
            bank_account_id INT NOT NULL,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (payment_type_id) REFERENCES payment_types(id) ON DELETE CASCADE,
            FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
            UNIQUE KEY unique_payment_account (payment_type_id, bank_account_id),
            INDEX idx_payment_type_id (payment_type_id),
            INDEX idx_bank_account_id (bank_account_id),
            INDEX idx_is_default (is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create register_tills table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS register_tills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            till_name VARCHAR(100) NOT NULL,
            till_code VARCHAR(20) NOT NULL,
            location VARCHAR(100),
            opening_balance DECIMAL(15,2) DEFAULT 0.00,
            current_balance DECIMAL(15,2) DEFAULT 0.00,
            assigned_user_id INT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_till_code (till_code),
            INDEX idx_till_name (till_name),
            INDEX idx_is_active (is_active),
            INDEX idx_assigned_user (assigned_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create cash_drops table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS cash_drops (
            id INT AUTO_INCREMENT PRIMARY KEY,
            till_id INT NOT NULL,
            user_id INT NOT NULL,
            drop_amount DECIMAL(15,2) NOT NULL,
            drop_type VARCHAR(50) DEFAULT 'cashier_sales',
            drop_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notes TEXT,
            is_emergency BOOLEAN DEFAULT FALSE,
            status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
            confirmed_by INT,
            confirmed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_till_id (till_id),
            INDEX idx_user_id (user_id),
            INDEX idx_drop_date (drop_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add drop_type column to existing cash_drops table if it doesn't exist
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM cash_drops LIKE 'drop_type'");
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE cash_drops ADD COLUMN drop_type VARCHAR(50) DEFAULT 'cashier_sales' AFTER drop_amount");
        }
    } catch (PDOException $e) {
        // Column might already exist or table doesn't exist yet
        error_log('drop_type column add to cash_drops table: ' . $e->getMessage());
    }

    // Add is_emergency column to existing cash_drops table if it doesn't exist
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM cash_drops LIKE 'is_emergency'");
        if ($stmt->rowCount() == 0) {
            $conn->exec("ALTER TABLE cash_drops ADD COLUMN is_emergency BOOLEAN DEFAULT FALSE AFTER notes");
        }
    } catch (PDOException $e) {
        // Column might already exist or table doesn't exist yet
        error_log('is_emergency column add to cash_drops table: ' . $e->getMessage());
    }

    // Create till_closings table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS till_closings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            till_id INT NOT NULL,
            user_id INT NOT NULL,
            opening_amount DECIMAL(15,2) DEFAULT 0.00,
            total_sales DECIMAL(15,2) DEFAULT 0.00,
            total_drops DECIMAL(15,2) DEFAULT 0.00,
            expected_balance DECIMAL(15,2) DEFAULT 0.00,
            expected_cash_balance DECIMAL(15,2) DEFAULT 0.00,
            cash_amount DECIMAL(15,2) DEFAULT 0.00,
            voucher_amount DECIMAL(15,2) DEFAULT 0.00,
            loyalty_points DECIMAL(15,2) DEFAULT 0.00,
            other_amount DECIMAL(15,2) DEFAULT 0.00,
            other_description VARCHAR(255),
            actual_counted_amount DECIMAL(15,2) DEFAULT 0.00,
            total_amount DECIMAL(15,2) NOT NULL,
            difference DECIMAL(15,2) DEFAULT 0.00,
            cash_shortage DECIMAL(15,2) DEFAULT 0.00,
            voucher_shortage DECIMAL(15,2) DEFAULT 0.00,
            other_shortage DECIMAL(15,2) DEFAULT 0.00,
            shortage_type ENUM('exact', 'shortage', 'excess', 'cash_shortage', 'voucher_shortage', 'other_shortage') DEFAULT 'exact',
            closing_notes TEXT,
            allow_exceed TINYINT(1) DEFAULT 0,
            closed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_till_id (till_id),
            INDEX idx_user_id (user_id),
            INDEX idx_closed_at (closed_at),
            INDEX idx_shortage_type (shortage_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add new columns to existing till_closings table if they don't exist
    try {
        $stmt = $conn->query("DESCRIBE till_closings");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $new_columns = [
            'opening_amount' => "ALTER TABLE till_closings ADD COLUMN opening_amount DECIMAL(15,2) DEFAULT 0.00 AFTER user_id",
            'total_sales' => "ALTER TABLE till_closings ADD COLUMN total_sales DECIMAL(15,2) DEFAULT 0.00 AFTER opening_amount",
            'total_drops' => "ALTER TABLE till_closings ADD COLUMN total_drops DECIMAL(15,2) DEFAULT 0.00 AFTER total_sales",
            'expected_balance' => "ALTER TABLE till_closings ADD COLUMN expected_balance DECIMAL(15,2) DEFAULT 0.00 AFTER total_drops",
            'expected_cash_balance' => "ALTER TABLE till_closings ADD COLUMN expected_cash_balance DECIMAL(15,2) DEFAULT 0.00 AFTER expected_balance",
            'actual_counted_amount' => "ALTER TABLE till_closings ADD COLUMN actual_counted_amount DECIMAL(15,2) DEFAULT 0.00 AFTER other_description",
            'difference' => "ALTER TABLE till_closings ADD COLUMN difference DECIMAL(15,2) DEFAULT 0.00 AFTER actual_counted_amount",
            'cash_shortage' => "ALTER TABLE till_closings ADD COLUMN cash_shortage DECIMAL(15,2) DEFAULT 0.00 AFTER difference",
            'voucher_shortage' => "ALTER TABLE till_closings ADD COLUMN voucher_shortage DECIMAL(15,2) DEFAULT 0.00 AFTER cash_shortage",
            'other_shortage' => "ALTER TABLE till_closings ADD COLUMN other_shortage DECIMAL(15,2) DEFAULT 0.00 AFTER voucher_shortage",
            'shortage_type' => "ALTER TABLE till_closings ADD COLUMN shortage_type ENUM('exact', 'shortage', 'excess', 'cash_shortage', 'voucher_shortage', 'other_shortage') DEFAULT 'exact' AFTER other_shortage",
        ];

        foreach ($new_columns as $column => $alter_sql) {
            if (!in_array($column, $columns)) {
                $conn->exec($alter_sql);
            }
        }

        // Add index for shortage_type if it doesn't exist
        try {
            $conn->exec("ALTER TABLE till_closings ADD INDEX idx_shortage_type (shortage_type)");
        } catch (PDOException $e) {
            // Index might already exist
        }

    } catch (PDOException $e) {
        error_log('Error updating till_closings table: ' . $e->getMessage());
    }

    // Create pos_settings table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS pos_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
            category VARCHAR(50) DEFAULT 'general',
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_setting_key (setting_key),
            INDEX idx_category (category),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create shift_management table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS shift_management (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            till_id INT,
            shift_start TIMESTAMP NOT NULL,
            shift_end TIMESTAMP NULL,
            opening_balance DECIMAL(15,2) DEFAULT 0.00,
            closing_balance DECIMAL(15,2) DEFAULT 0.00,
            total_sales DECIMAL(15,2) DEFAULT 0.00,
            total_cash_drops DECIMAL(15,2) DEFAULT 0.00,
            status ENUM('active', 'ended', 'cancelled') DEFAULT 'active',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (till_id) REFERENCES register_tills(id) ON DELETE SET NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_till_id (till_id),
            INDEX idx_shift_start (shift_start),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Register tills will be created by users as needed

    // POS settings will be configured by users as needed

    // Create loyalty_points table
    try {
        $conn->exec("
        CREATE TABLE IF NOT EXISTS loyalty_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            points_earned INT DEFAULT 0,
            points_redeemed INT DEFAULT 0,
            points_balance INT DEFAULT 0,
            transaction_type ENUM('earned', 'redeemed', 'expired', 'adjusted') NOT NULL,
            transaction_reference VARCHAR(100),
            description TEXT,
            expiry_date DATE NULL,
            approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
            approved_by INT DEFAULT NULL,
            approved_at TIMESTAMP NULL,
            rejection_reason TEXT NULL,
            source ENUM('purchase', 'manual', 'welcome', 'bonus', 'adjustment') DEFAULT 'manual',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_customer_id (customer_id),
            INDEX idx_transaction_type (transaction_type),
            INDEX idx_created_at (created_at),
            INDEX idx_approval_status (approval_status),
            INDEX idx_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

} catch(PDOException $e) {
    // Store connection error for login page
    $GLOBALS['db_connected'] = false;
    $GLOBALS['db_error'] = $e->getMessage();
}

    // Add new columns to existing loyalty_points table if they don't exist
    try {
        // Check if approval_status column exists
        $result = $conn->query("SHOW COLUMNS FROM loyalty_points LIKE 'approval_status'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved'");
        }

        // Check if approved_by column exists
        $result = $conn->query("SHOW COLUMNS FROM loyalty_points LIKE 'approved_by'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD COLUMN approved_by INT DEFAULT NULL");
        }

        // Check if approved_at column exists
        $result = $conn->query("SHOW COLUMNS FROM loyalty_points LIKE 'approved_at'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD COLUMN approved_at TIMESTAMP NULL");
        }

        // Check if rejection_reason column exists
        $result = $conn->query("SHOW COLUMNS FROM loyalty_points LIKE 'rejection_reason'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD COLUMN rejection_reason TEXT NULL");
        }

        // Check if source column exists
        $result = $conn->query("SHOW COLUMNS FROM loyalty_points LIKE 'source'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD COLUMN source ENUM('purchase', 'manual', 'welcome', 'bonus', 'adjustment') DEFAULT 'manual'");
        }

        // Add foreign key constraint for approved_by if it doesn't exist
        $result = $conn->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'loyalty_points'
            AND COLUMN_NAME = 'approved_by'
            AND CONSTRAINT_NAME != 'PRIMARY'
        ");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD CONSTRAINT fk_loyalty_points_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
        }

        // Add indexes if they don't exist
        $result = $conn->query("SHOW INDEX FROM loyalty_points WHERE Key_name = 'idx_approval_status'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD INDEX idx_approval_status (approval_status)");
        }

        $result = $conn->query("SHOW INDEX FROM loyalty_points WHERE Key_name = 'idx_source'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE loyalty_points ADD INDEX idx_source (source)");
        }

    } catch (PDOException $e) {
        // Log error but don't stop execution
        error_log("Error adding loyalty_points columns: " . $e->getMessage());
    }

    // Add reward_program_active column to customers table if it doesn't exist
    try {
        $result = $conn->query("SHOW COLUMNS FROM customers LIKE 'reward_program_active'");
        if ($result->rowCount() == 0) {
            $conn->exec("ALTER TABLE customers ADD COLUMN reward_program_active TINYINT(1) DEFAULT 1 COMMENT 'Whether customer is enrolled in reward program'");
        }
    } catch (PDOException $e) {
        // Log error but don't stop execution
        error_log("Error adding reward_program_active column: " . $e->getMessage());
    }

    // Update existing loyalty_points records to have default values for new columns
    try {
        // Set approval_status to 'approved' for existing records that don't have it set
        $conn->exec("UPDATE loyalty_points SET approval_status = 'approved' WHERE approval_status IS NULL");

        // Set source to 'manual' for existing records that don't have it set
        $conn->exec("UPDATE loyalty_points SET source = 'manual' WHERE source IS NULL");

        // Set approved_at to created_at for existing approved records
        $conn->exec("UPDATE loyalty_points SET approved_at = created_at WHERE approval_status = 'approved' AND approved_at IS NULL");

    } catch (PDOException $e) {
        // Log error but don't stop execution
        error_log("Error updating existing loyalty_points records: " . $e->getMessage());
    }

    // Create membership_levels table
    try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS membership_levels (
            id INT AUTO_INCREMENT PRIMARY KEY,
            level_name VARCHAR(100) NOT NULL,
            level_description TEXT,
            points_multiplier DECIMAL(5, 2) NOT NULL DEFAULT 1.00,
            minimum_points_required INT DEFAULT 0,
            color_code VARCHAR(7) DEFAULT '#6c757d',
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_level_name (level_name),
            INDEX idx_is_active (is_active),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Create loyalty_rewards table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS loyalty_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reward_name VARCHAR(255) NOT NULL,
            reward_description TEXT,
            points_required INT NOT NULL,
            discount_type ENUM('percentage', 'fixed_amount', 'free_item') NOT NULL,
            discount_value DECIMAL(10, 2) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_points_required (points_required),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");


    // Insert default membership levels
    $default_levels = [
        ['Basic', 'Basic membership level', 1.00, 0, '#6c757d', 1, 1],
        ['Silver', 'Silver membership level', 1.50, 100, '#c0c0c0', 1, 2],
        ['Gold', 'Gold membership level', 2.00, 500, '#ffd700', 1, 3],
        ['Platinum', 'Platinum membership level', 2.50, 1000, '#e5e4e2', 1, 4],
        ['Diamond', 'Diamond membership level', 3.00, 2500, '#b9f2ff', 1, 5]
    ];
    $stmt = $conn->prepare("
        INSERT IGNORE INTO membership_levels (level_name, level_description, points_multiplier, minimum_points_required, color_code, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($default_levels as $level) {
        $stmt->execute($level);
    }

    // Insert default loyalty rewards
    $default_rewards = [
        ['Welcome Bonus', 'Get 100 points for your first purchase', 0, 'fixed_amount', 100.00, 1],
        ['5% Discount', 'Get 5% off your next purchase', 500, 'percentage', 5.00, 1],
        ['10% Discount', 'Get 10% off your next purchase', 1000, 'percentage', 10.00, 1],
        ['20% Discount', 'Get 20% off your next purchase', 2000, 'percentage', 20.00, 1],
        ['Free Shipping', 'Free delivery on your next order', 300, 'fixed_amount', 0.00, 1]
    ];
    $stmt = $conn->prepare("
        INSERT IGNORE INTO loyalty_rewards (reward_name, reward_description, points_required, discount_type, discount_value, is_active)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($default_rewards as $reward) {
        $stmt->execute($reward);
    }

} catch (PDOException $e) {
    error_log("Error setting up membership levels and loyalty rewards: " . $e->getMessage());
}

/**
 * Get active payment methods including loyalty points and cash
 */
if (!function_exists('getPaymentMethods')) {
    function getPaymentMethods($conn) {
    try {
        // Get payment methods from database
        $stmt = $conn->query("
            SELECT * FROM payment_types
            WHERE is_active = 1
            ORDER BY sort_order, display_name
        ");
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add loyalty points as a payment method if not already present
        $hasLoyaltyPoints = false;
        foreach ($payment_methods as $method) {
            if ($method['name'] === 'loyalty_points') {
                $hasLoyaltyPoints = true;
                break;
            }
        }

        if (!$hasLoyaltyPoints) {
            $payment_methods[] = [
                'id' => 999,
                'name' => 'loyalty_points',
                'display_name' => 'Loyalty Points',
                'description' => 'Pay with loyalty points',
                'category' => 'other',
                'icon' => 'bi-gift',
                'color' => '#ffc107',
                'is_active' => 1,
                'requires_reconciliation' => 0,
                'sort_order' => 999
            ];
        }

        // Ensure cash is present
        $hasCash = false;
        foreach ($payment_methods as $method) {
            if ($method['name'] === 'cash') {
                $hasCash = true;
                break;
            }
        }

        if (!$hasCash) {
            array_unshift($payment_methods, [
                'id' => 1,
                'name' => 'cash',
                'display_name' => 'Cash',
                'description' => 'Cash payment',
                'category' => 'cash',
                'icon' => 'bi-cash',
                'color' => '#28a745',
                'is_active' => 1,
                'requires_reconciliation' => 1,
                'sort_order' => 1
            ]);
        }

        return $payment_methods;

    } catch (PDOException $e) {
        error_log("Error getting payment methods: " . $e->getMessage());
        return [];
    }
    }
}

/**
 * Get payment method by name
 */
if (!function_exists('getPaymentMethodByName')) {
    function getPaymentMethodByName($conn, $name) {
        try {
            $stmt = $conn->prepare("
                SELECT * FROM payment_types
                WHERE name = :name AND is_active = 1
            ");
            $stmt->execute([':name' => $name]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting payment method by name: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Add or update payment method
 */
if (!function_exists('savePaymentMethod')) {
    function savePaymentMethod($conn, $data) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO payment_types (name, display_name, description, category, icon, color, is_active, requires_reconciliation, sort_order)
                VALUES (:name, :display_name, :description, :category, :icon, :color, :is_active, :requires_reconciliation, :sort_order)
                ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                description = VALUES(description),
                category = VALUES(category),
                icon = VALUES(icon),
                color = VALUES(color),
                is_active = VALUES(is_active),
                requires_reconciliation = VALUES(requires_reconciliation),
                sort_order = VALUES(sort_order),
                updated_at = CURRENT_TIMESTAMP
            ");

            return $stmt->execute([
                ':name' => $data['name'],
                ':display_name' => $data['display_name'],
                ':description' => $data['description'] ?? '',
                ':category' => $data['category'] ?? 'other',
                ':icon' => $data['icon'] ?? 'bi-cash',
                ':color' => $data['color'] ?? '#6c757d',
                ':is_active' => $data['is_active'] ?? 1,
                ':requires_reconciliation' => $data['requires_reconciliation'] ?? 1,
                ':sort_order' => $data['sort_order'] ?? 0
            ]);

        } catch (PDOException $e) {
            error_log("Error saving payment method: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get count of held transactions for a specific till
 */
if (!function_exists('getHeldTransactionsCount')) {
    function getHeldTransactionsCount($conn, $till_id) {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM held_transactions
                WHERE till_id = ? AND status = 'held'
            ");
            $stmt->execute([$till_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("Error getting held transactions count: " . $e->getMessage());
            return 0;
        }
    }

    // Cash Drop Functions
    function createCashDrop($conn, $data) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO cash_drops (till_id, user_id, drop_amount, drop_type, notes, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $data['till_id'],
                $data['user_id'],
                $data['drop_amount'],
                $data['drop_type'] ?? 'cashier_sales',
                $data['notes'] ?? ''
            ]);
            
            return $conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating cash drop: " . $e->getMessage());
            throw new Exception("Failed to create cash drop: " . $e->getMessage());
        }
    }

    function getCashDrops($conn, $filters = [], $page = 1, $perPage = 20) {
        try {
            $whereConditions = [];
            $params = [];
            
            if (isset($filters['till_id']) && $filters['till_id']) {
                $whereConditions[] = "cd.till_id = ?";
                $params[] = $filters['till_id'];
            }
            
            if (isset($filters['user_id']) && $filters['user_id']) {
                $whereConditions[] = "cd.user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (isset($filters['date_from']) && $filters['date_from']) {
                $whereConditions[] = "DATE(cd.drop_date) >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (isset($filters['date_to']) && $filters['date_to']) {
                $whereConditions[] = "DATE(cd.drop_date) <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (isset($filters['status']) && $filters['status']) {
                $whereConditions[] = "cd.status = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
            
            // Get total count
            $countQuery = "
                SELECT COUNT(*) as total
                FROM cash_drops cd
                LEFT JOIN users u ON cd.user_id = u.id
                LEFT JOIN register_tills rt ON cd.till_id = rt.id
                $whereClause
            ";
            
            $stmt = $conn->prepare($countQuery);
            $stmt->execute($params);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get paginated results
            $offset = ($page - 1) * $perPage;
            $query = "
                SELECT 
                    cd.*,
                    u.username,
                    rt.till_name,
                    rt.till_code
                FROM cash_drops cd
                LEFT JOIN users u ON cd.user_id = u.id
                LEFT JOIN register_tills rt ON cd.till_id = rt.id
                $whereClause
                ORDER BY cd.drop_date DESC
                LIMIT $perPage OFFSET $offset
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $drops = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'drops' => $drops,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ];
        } catch (PDOException $e) {
            error_log("Error getting cash drops: " . $e->getMessage());
            throw new Exception("Failed to get cash drops: " . $e->getMessage());
        }
    }

    function getTotalCashDrops($conn, $till_id, $user_id = null, $date = null) {
        try {
            $whereConditions = ["cd.till_id = ?"];
            $params = [$till_id];
            
            if ($user_id) {
                $whereConditions[] = "cd.user_id = ?";
                $params[] = $user_id;
            }
            
            if ($date) {
                $whereConditions[] = "DATE(cd.drop_date) = ?";
                $params[] = $date;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
            
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(cd.drop_amount), 0) as total_drops
                FROM cash_drops cd
                $whereClause AND cd.status = 'pending'
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return floatval($result['total_drops']);
        } catch (PDOException $e) {
            error_log("Error getting total cash drops: " . $e->getMessage());
            return 0;
        }
    }

    function getTillSalesTotal($conn, $till_id, $date = null) {
        try {
            $whereConditions = ["(s.till_id = ? OR s.till_id IS NULL)"];
            $params = [$till_id];

            if ($date) {
                $whereConditions[] = "DATE(s.created_at) = ?";
                $params[] = $date;
            }

            $whereClause = "WHERE " . implode(" AND ", $whereConditions);

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(s.final_amount), 0) as total_sales
                FROM sales s
                $whereClause
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return floatval($result['total_sales']);
        } catch (PDOException $e) {
            error_log("Error getting total till sales: " . $e->getMessage());
            return 0;
        }
    }

    function getCashierSalesTotal($conn, $till_id, $user_id, $date = null) {
        try {
            $whereConditions = ["(s.till_id = ? OR s.till_id IS NULL)", "s.user_id = ?"];
            $params = [$till_id, $user_id];

            if ($date) {
                $whereConditions[] = "DATE(s.created_at) = ?";
                $params[] = $date;
            }

            $whereClause = "WHERE " . implode(" AND ", $whereConditions);

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(s.final_amount), 0) as total_sales
                FROM sales s
                $whereClause
            ");
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return floatval($result['total_sales']);
        } catch (PDOException $e) {
            error_log("Error getting cashier sales total: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Generate quotation number based on settings
 */
if (!function_exists('generateQuotationNumber')) {
    function generateQuotationNumber($conn, $date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }

        try {
            // Get quotation settings
            $settings = [];
            $stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'quotation_%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            $prefix = $settings['quotation_prefix'] ?? 'QUO';
            $length = (int)($settings['quotation_number_length'] ?? 6);
            $startNumber = (int)($settings['quotation_start_number'] ?? 1);

            // Get the next number based on the highest existing number
            $stmt = $conn->query("SELECT MAX(CAST(SUBSTRING(quotation_number, LENGTH('$prefix') + 1) AS UNSIGNED)) as max_num FROM quotations WHERE quotation_number LIKE '$prefix%'");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $nextNum = max($startNumber, ($row['max_num'] ?? 0) + 1);

            // Pad the number to required length
            $paddedNum = str_pad($nextNum, $length, '0', STR_PAD_LEFT);

            return $prefix . $paddedNum;
        } catch (PDOException $e) {
            error_log("Error generating quotation number: " . $e->getMessage());
            return 'QUO-' . date('Ymd') . '-001';
        }
    }
}

/**
 * Create a new quotation
 */
if (!function_exists('createQuotation')) {
    function createQuotation($conn, $quotationData) {
    try {
        $conn->beginTransaction();

        // Generate quotation number
        $quotationNumber = generateQuotationNumber($conn);

        // Use valid_until from form data or calculate from settings
        $validUntil = $quotationData['valid_until'] ?? null;
        if (!$validUntil) {
            $settings = [];
            $stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'quotation_%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            $validDays = (int)($settings['quotation_valid_days'] ?? 30);
            $validUntil = date('Y-m-d', strtotime("+$validDays days"));
        }

        // Insert quotation
        $stmt = $conn->prepare("
            INSERT INTO quotations (
                quotation_number, customer_id, customer_name, customer_email,
                customer_phone, customer_address, user_id, subtotal, tax_amount,
                final_amount, quotation_status, valid_until,
                notes, terms, created_at
            ) VALUES (
                :quotation_number, :customer_id, :customer_name, :customer_email,
                :customer_phone, :customer_address, :user_id, :subtotal, :tax_amount,
                :final_amount, :quotation_status, :valid_until,
                :notes, :terms, NOW()
            )
        ");

        $stmt->execute([
            ':quotation_number' => $quotationNumber,
            ':customer_id' => $quotationData['customer_id'] ?? null,
            ':customer_name' => $quotationData['customer_name'] ?? '',
            ':customer_email' => $quotationData['customer_email'] ?? '',
            ':customer_phone' => $quotationData['customer_phone'] ?? '',
            ':customer_address' => $quotationData['customer_address'] ?? '',
            ':user_id' => $quotationData['user_id'],
            ':subtotal' => $quotationData['subtotal'] ?? 0,
            ':tax_amount' => $quotationData['tax_amount'] ?? 0,
            ':final_amount' => $quotationData['final_amount'] ?? 0,
            ':quotation_status' => $quotationData['quotation_status'] ?? 'draft',
            ':valid_until' => $validUntil,
            ':notes' => $quotationData['notes'] ?? '',
            ':terms' => $quotationData['terms'] ?? ''
        ]);

        $quotationId = $conn->lastInsertId();

        // Insert quotation items
        if (isset($quotationData['items']) && is_array($quotationData['items'])) {
            $stmt = $conn->prepare("
                INSERT INTO quotation_items (
                    quotation_id, product_id, product_name, product_sku,
                    quantity, unit_price, total_price, description
                ) VALUES (
                    :quotation_id, :product_id, :product_name, :product_sku,
                    :quantity, :unit_price, :total_price, :description
                )
            ");

            foreach ($quotationData['items'] as $item) {
                $stmt->execute([
                    ':quotation_id' => $quotationId,
                    ':product_id' => $item['product_id'] ?? null,
                    ':product_name' => $item['product_name'],
                    ':product_sku' => $item['product_sku'] ?? '',
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':total_price' => $item['total_price'],
                    ':description' => $item['description'] ?? ''
                ]);
            }
        }

        $conn->commit();
        return ['success' => true, 'quotation_id' => $quotationId, 'quotation_number' => $quotationNumber];

    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error creating quotation: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
    }
}

/**
 * Get quotation by ID
 */
if (!function_exists('getQuotation')) {
    function getQuotation($conn, $quotationId) {
        try {
            $stmt = $conn->prepare("
                SELECT q.*, u.username as created_by
                FROM quotations q
                LEFT JOIN users u ON q.user_id = u.id
                WHERE q.id = :quotation_id
            ");
            $stmt->execute([':quotation_id' => $quotationId]);
            $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quotation) {
                return null;
            }

            // Get quotation items
            $stmt = $conn->prepare("
                SELECT * FROM quotation_items
                WHERE quotation_id = :quotation_id
                ORDER BY id
            ");
            $stmt->execute([':quotation_id' => $quotationId]);
            $quotation['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $quotation;

        } catch (PDOException $e) {
            error_log("Error getting quotation: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Update quotation status
 */
if (!function_exists('updateQuotationStatus')) {
    function updateQuotationStatus($conn, $quotationId, $status) {
    try {
        $stmt = $conn->prepare("
            UPDATE quotations
            SET quotation_status = :status, updated_at = NOW()
            WHERE id = :quotation_id
        ");
        return $stmt->execute([
            ':status' => $status,
            ':quotation_id' => $quotationId
        ]);
    } catch (PDOException $e) {
        error_log("Error updating quotation status: " . $e->getMessage());
        return false;
    }
    }
}

/**
 * Create invoices table and invoice_items table
 */
$conn->exec("
    CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(50) NOT NULL UNIQUE,
        sale_id INT DEFAULT NULL COMMENT 'Original sale ID if converted from receipt',
        customer_id INT DEFAULT NULL,
        customer_name VARCHAR(255) NOT NULL,
        customer_phone VARCHAR(20) DEFAULT '',
        customer_address TEXT,
        subtotal DECIMAL(10,2) DEFAULT 0,
        tax_amount DECIMAL(10,2) DEFAULT 0,
        discount_amount DECIMAL(10,2) DEFAULT 0,
        final_amount DECIMAL(10,2) NOT NULL,
        invoice_status ENUM('draft', 'sent', 'paid', 'overdue') DEFAULT 'draft',
        payment_terms VARCHAR(100) DEFAULT 'Due within 30 days',
        payment_method VARCHAR(50) DEFAULT '',
        notes TEXT,
        terms TEXT,
        due_date DATE DEFAULT NULL,
        invoice_date DATE DEFAULT (CURRENT_DATE),
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id),
        INDEX idx_invoice_number (invoice_number),
        INDEX idx_sale_id (sale_id),
        INDEX idx_customer_id (customer_id),
        INDEX idx_invoice_status (invoice_status),
        INDEX idx_due_date (due_date)
    )
");

/**
 * Create invoice_items table
 */
$conn->exec("
    CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        product_id INT DEFAULT NULL,
        product_name VARCHAR(255) NOT NULL,
        product_sku VARCHAR(100) DEFAULT '',
        description TEXT,
        quantity DECIMAL(10,2) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        discount DECIMAL(10,2) DEFAULT 0,
        tax_rate DECIMAL(5,2) DEFAULT 0,
        tax_amount DECIMAL(10,2) DEFAULT 0,
        total_price DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
        INDEX idx_invoice_id (invoice_id),
        INDEX idx_product_id (product_id)
    )
");

/**
 * Generate invoice number
 */
if (!function_exists('generateInvoiceNumber')) {
    function generateInvoiceNumber($conn) {
    try {
        // Get invoice settings
        $settings = [];
        $stmt = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'invoice_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $prefix = $settings['invoice_prefix'] ?? 'INV';
        $length = (int)($settings['invoice_number_length'] ?? 6);
        $startNumber = (int)($settings['invoice_start_number'] ?? 1);

        // Get the next number based on the highest existing number
        $stmt = $conn->query("SELECT MAX(CAST(SUBSTRING(invoice_number, LENGTH('$prefix') + 1) AS UNSIGNED)) as max_num FROM invoices WHERE invoice_number LIKE '$prefix%'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextNum = max($startNumber, ($row['max_num'] ?? 0) + 1);

        // Pad the number to required length
        $paddedNum = str_pad($nextNum, $length, '0', STR_PAD_LEFT);

        return $prefix . $paddedNum;
    } catch (PDOException $e) {
        error_log("Error generating invoice number: " . $e->getMessage());
        return 'INV-' . date('Ymd') . '-001';
    }
    }
}

/**
 * Create invoice from sale/receipt
 */
if (!function_exists('createInvoiceFromSale')) {
    function createInvoiceFromSale($conn, $saleId, $userId) {
    try {
        $conn->beginTransaction();

        // Get sale details
        $stmt = $conn->prepare("
            SELECT s.*, u.username as created_by_name
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.id = :sale_id
        ");
        $stmt->execute([':sale_id' => $saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception('Sale not found');
        }

        // Check if invoice already exists for this sale
        $stmt = $conn->prepare("SELECT id FROM invoices WHERE sale_id = :sale_id");
        $stmt->execute([':sale_id' => $saleId]);
        if ($stmt->fetch()) {
            throw new Exception('Invoice already exists for this sale');
        }

        // Generate invoice number
        $invoiceNumber = generateInvoiceNumber($conn);

        // For receipts, set payment terms as "Paid", due date as sale date, and status as "paid"
        $dueDate = date('Y-m-d', strtotime($sale['sale_date']));
        $paymentTerms = 'Paid';
        $invoiceStatus = 'paid';

        // Insert invoice
        $stmt = $conn->prepare("
            INSERT INTO invoices (
                invoice_number, sale_id, customer_id, customer_name, customer_email,
                customer_phone, customer_address, subtotal, tax_amount, discount_amount,
                final_amount, payment_method, notes, payment_terms, due_date, invoice_status, created_by
            ) VALUES (
                :invoice_number, :sale_id, :customer_id, :customer_name, :customer_email,
                :customer_phone, :customer_address, :subtotal, :tax_amount, :discount_amount,
                :final_amount, :payment_method, :notes, :payment_terms, :due_date, :invoice_status, :created_by
            )
        ");

        $stmt->execute([
            ':invoice_number' => $invoiceNumber,
            ':sale_id' => $saleId,
            ':customer_id' => $sale['customer_id'] ?? null,
            ':customer_name' => $sale['customer_name'] ?: 'Walk-in Customer',
            ':customer_email' => $sale['customer_email'] ?: '',
            ':customer_phone' => $sale['customer_phone'] ?: '',
            ':customer_address' => $sale['customer_address'] ?: '',
            ':subtotal' => $sale['total_amount'] - $sale['tax_amount'] - $sale['discount'],
            ':tax_amount' => $sale['tax_amount'],
            ':discount_amount' => $sale['discount'],
            ':final_amount' => $sale['final_amount'],
            ':payment_method' => $sale['payment_method'],
            ':notes' => $sale['notes'] ?: '',
            ':payment_terms' => $paymentTerms,
            ':due_date' => $dueDate,
            ':invoice_status' => $invoiceStatus,
            ':created_by' => $userId
        ]);

        $invoiceId = $conn->lastInsertId();

        // Get sale items and insert as invoice items
        $stmt = $conn->prepare("
            SELECT si.*, p.sku, p.description
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = :sale_id
        ");
        $stmt->execute([':sale_id' => $saleId]);
        $saleItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Insert invoice items
        $stmt = $conn->prepare("
            INSERT INTO invoice_items (
                invoice_id, product_id, product_name, product_sku, description,
                quantity, unit_price, discount, total_price
            ) VALUES (
                :invoice_id, :product_id, :product_name, :product_sku, :description,
                :quantity, :unit_price, :discount, :total_price
            )
        ");

        foreach ($saleItems as $item) {
            $stmt->execute([
                ':invoice_id' => $invoiceId,
                ':product_id' => $item['product_id'],
                ':product_name' => $item['product_name'],
                ':product_sku' => $item['sku'] ?: '',
                ':description' => $item['description'] ?: '',
                ':quantity' => $item['quantity'],
                ':unit_price' => $item['unit_price'],
                ':discount' => 0, // Calculate discount per item if needed
                ':total_price' => $item['total_price']
            ]);
        }

        $conn->commit();
        return [
            'success' => true,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber
        ];

    } catch (Exception $e) {
        $conn->rollBack();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    }
}

/**
 * Get invoice by ID
 */
if (!function_exists('getInvoice')) {
    function getInvoice($conn, $invoiceId) {
    try {
        $stmt = $conn->prepare("
            SELECT i.*, u.username as created_by_name, c.email as customer_email_from_db
            FROM invoices i
            LEFT JOIN users u ON i.created_by = u.id
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.id = :invoice_id
        ");
        $stmt->execute([':invoice_id' => $invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return null;
        }

        // Get invoice items
        $stmt = $conn->prepare("
            SELECT * FROM invoice_items
            WHERE invoice_id = :invoice_id
            ORDER BY id
        ");
        $stmt->execute([':invoice_id' => $invoiceId]);
        $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $invoice;

    } catch (PDOException $e) {
        error_log("Error getting invoice: " . $e->getMessage());
        return null;
    }
    }
}

/**
 * Get invoices with pagination and filters
 */
if (!function_exists('getInvoices')) {
    function getInvoices($conn, $filters = [], $page = 1, $perPage = 20) {
    try {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'invoice_status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['customer_name'])) {
            $where[] = 'customer_name LIKE :customer_name';
            $params[':customer_name'] = '%' . $filters['customer_name'] . '%';
        }

        if (!empty($filters['invoice_number'])) {
            $where[] = 'invoice_number LIKE :invoice_number';
            $params[':invoice_number'] = '%' . $filters['invoice_number'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'invoice_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'invoice_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM invoices $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get invoices
        $stmt = $conn->prepare("
            SELECT i.*, u.username as created_by_name
            FROM invoices i
            LEFT JOIN users u ON i.created_by = u.id
            $whereClause
            ORDER BY i.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = ceil($total / $perPage);

        return [
            'invoices' => $invoices,
            'total' => $total,
            'pages' => $totalPages,
            'current_page' => $page
        ];

    } catch (PDOException $e) {
        error_log("Error getting invoices: " . $e->getMessage());
        return [
            'invoices' => [],
            'total' => 0,
            'pages' => 0,
            'current_page' => $page
        ];
    }
    }
}

/**
 * Update an existing quotation
 */
if (!function_exists('updateQuotation')) {
    function updateQuotation($conn, $quotationId, $quotationData) {
    try {
        $conn->beginTransaction();

        // Update quotation header
        $stmt = $conn->prepare("
            UPDATE quotations SET
                customer_id = :customer_id,
                customer_name = :customer_name,
                customer_email = :customer_email,
                customer_phone = :customer_phone,
                customer_address = :customer_address,
                subtotal = :subtotal,
                tax_amount = :tax_amount,
                final_amount = :final_amount,
                quotation_status = :quotation_status,
                valid_until = :valid_until,
                notes = :notes,
                terms = :terms,
                updated_at = NOW()
            WHERE id = :quotation_id
        ");

        $stmt->execute([
            ':quotation_id' => $quotationId,
            ':customer_id' => $quotationData['customer_id'] ?? null,
            ':customer_name' => $quotationData['customer_name'] ?? '',
            ':customer_email' => $quotationData['customer_email'] ?? '',
            ':customer_phone' => $quotationData['customer_phone'] ?? '',
            ':customer_address' => $quotationData['customer_address'] ?? '',
            ':subtotal' => $quotationData['subtotal'] ?? 0,
            ':tax_amount' => $quotationData['tax_amount'] ?? 0,
            ':final_amount' => $quotationData['final_amount'] ?? 0,
            ':quotation_status' => $quotationData['quotation_status'] ?? 'draft',
            ':valid_until' => $quotationData['valid_until'],
            ':notes' => $quotationData['notes'] ?? '',
            ':terms' => $quotationData['terms'] ?? ''
        ]);

        // Delete existing items and insert updated ones
        $stmt = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = :quotation_id");
        $stmt->execute([':quotation_id' => $quotationId]);

        // Insert updated quotation items
        if (isset($quotationData['items']) && is_array($quotationData['items'])) {
            $stmt = $conn->prepare("
                INSERT INTO quotation_items (
                    quotation_id, product_id, product_name, product_sku,
                    quantity, unit_price, total_price, description
                ) VALUES (
                    :quotation_id, :product_id, :product_name, :product_sku,
                    :quantity, :unit_price, :total_price, :description
                )
            ");

            foreach ($quotationData['items'] as $item) {
                $stmt->execute([
                    ':quotation_id' => $quotationId,
                    ':product_id' => $item['product_id'] ?? null,
                    ':product_name' => $item['product_name'],
                    ':product_sku' => $item['product_sku'] ?? '',
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['unit_price'],
                    ':total_price' => $item['total_price'],
                    ':description' => $item['description'] ?? ''
                ]);
            }
        }

        $conn->commit();
        return ['success' => true, 'quotation_id' => $quotationId];

    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Error updating quotation: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
    }
}

/**
 * Get quotations with pagination and filters
 */
if (!function_exists('getQuotations')) {
    function getQuotations($conn, $filters = [], $page = 1, $perPage = 20) {
    try {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'quotation_status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['customer_name'])) {
            $where[] = 'customer_name LIKE :customer_name';
            $params[':customer_name'] = '%' . $filters['customer_name'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM quotations $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get quotations
        $stmt = $conn->prepare("
            SELECT q.*, u.username as created_by
            FROM quotations q
            LEFT JOIN users u ON q.user_id = u.id
            $whereClause
            ORDER BY q.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'quotations' => $quotations,
            'total' => $total,
            'pages' => ceil($total / $perPage),
            'current_page' => $page
        ];

    } catch (PDOException $e) {
        error_log("Error getting quotations: " . $e->getMessage());
        return ['quotations' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
    }
}
}

// Re-enable foreign key checks at the end
try {
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (Exception $e) {
    // Ignore if connection doesn't exist
}

} catch (PDOException $e) {
    // Database connection or table creation failed
    $GLOBALS['db_error'] = $e->getMessage();
    $GLOBALS['db_connected'] = false;
    
    // Check if we're accessing from starter.php to avoid redirect loop
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    $isStarterPage = ($currentScript === 'starter.php' || strpos($_SERVER['REQUEST_URI'], 'starter.php') !== false);
    
    if (!$isStarterPage) {
        // Show database error message instead of installer (system already installed)
        showDatabaseErrorMessage();
    }
}

// Function to show database connection error message (for post-installation issues)
if (!function_exists('showDatabaseErrorMessage')) {
function showDatabaseErrorMessage() {
    $errorMessage = $GLOBALS['db_error'] ?? 'Unknown database error';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $currentScript = $_SERVER['SCRIPT_NAME'];

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Error - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); min-height: 100vh; display: flex; align-items: center; }
        .error-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .btn-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); border: none; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(220, 53, 69, 0.3); }
        .error-icon { animation: pulse 2s infinite; }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="error-card p-5 text-center">
                    <div class="mb-4">
                        <i class="bi bi-exclamation-triangle-fill text-danger error-icon" style="font-size: 4rem;"></i>
                    </div>
                    <h1 class="h2 mb-4 text-danger">Database Connection Error</h1>
                    <p class="lead text-muted mb-4">The system encountered a database connection problem and cannot continue.</p>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-bug me-2"></i>
                        <strong>Error Details:</strong><br>
                        <code class="mt-2 d-block">' . htmlspecialchars($errorMessage) . '</code>
                    </div>
                    <div class="d-grid gap-2 mb-4">
                        <button onclick="window.history.back()" class="btn btn-secondary btn-lg">
                            <i class="bi bi-arrow-left me-2"></i>Go Back
                        </button>
                        <button onclick="window.location.reload()" class="btn btn-danger btn-lg">
                            <i class="bi bi-arrow-clockwise me-2"></i>Try Again
                        </button>
                    </div>
                    <div class="mt-4">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            If this problem persists, please contact your system administrator.
                        </small>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="bi bi-gear me-1"></i>
                            Need technical help? Contact: <a href="mailto:support@thiarara.co.ke" class="text-decoration-none">support@thiarara.co.ke</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
        exit();
    }
}

// Function to show user-friendly installer message
if (!function_exists('showInstallerMessage')) {
function showInstallerMessage() {
    // Get the base URL dynamically to work in any folder structure
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $currentScript = $_SERVER['SCRIPT_NAME'];
    $documentRoot = $_SERVER['DOCUMENT_ROOT'];
    $currentDir = dirname($currentScript);
    
    // Find the project root by looking for starter.php
    $projectRoot = '';
    $testPath = $currentDir;
    
    // Start from the current directory and go up until we find starter.php or reach the root
    while ($testPath !== '/' && $testPath !== '') {
        $fullPath = $documentRoot . $testPath . '/starter.php';
        if (file_exists($fullPath)) {
            $projectRoot = $testPath;
            break;
        }
        // Go up one directory
        $testPath = dirname($testPath);
        if ($testPath === '.') $testPath = '';
    }
    
    // Build the installer URL
    if ($projectRoot !== '') {
        // Found starter.php in a parent directory
        $installerUrl = $projectRoot . '/starter.php';
    } else {
        // Fallback: check if starter.php exists in the document root
        $rootStarterPath = $documentRoot . '/starter.php';
        if (file_exists($rootStarterPath)) {
            $installerUrl = '/starter.php';
        } else {
            // Last fallback: assume it's in the same directory as current script
            $installerUrl = dirname($currentScript) . '/starter.php';
        }
    }
    
    // Ensure the URL starts with / for absolute path
    if (substr($installerUrl, 0, 1) !== '/') {
        $installerUrl = '/' . ltrim($installerUrl, '/');
    }
    
    // Clean up any double slashes
    $installerUrl = preg_replace('#/+#', '/', $installerUrl);
    
    // Create the full URL for display purposes
    $fullInstallerUrl = $protocol . $host . $installerUrl;
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System Setup Required</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .setup-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-radius: 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="setup-card p-5 text-center">
                    <div class="mb-4">
                        <i class="bi bi-gear-fill text-primary" style="font-size: 4rem;"></i>
                    </div>
                    <h1 class="h2 mb-4 text-dark">Setup Required</h1>
                    <p class="lead text-muted mb-4">Welcome to the POS System! The system needs to be set up before you can use it.</p>
                    <div class="alert alert-info" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        This is a fresh installation and requires initial configuration.
                    </div>
                    <div class="d-grid gap-2">
                        <a href="' . htmlspecialchars($installerUrl) . '" class="btn btn-primary btn-lg">
                            <i class="bi bi-play-circle me-2"></i>Start Installation
                        </a>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Or visit: <a href="' . htmlspecialchars($installerUrl) . '" class="text-decoration-none">' . htmlspecialchars($fullInstallerUrl) . '</a>
                        </small>
                    </div>
                    <div class="mt-4">
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            Secure setup process - takes just a few minutes
                        </small>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Need help? Contact support: <a href="mailto:support@thiarara.co.ke" class="text-decoration-none">support@thiarara.co.ke</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
}
}

/**
 * Get inventory statistics including total value
 */
if (!function_exists('getInventoryStatistics')) {
    function getInventoryStatistics($conn) {
        try {
            $stats = [];

            // Total Products in Inventory
            $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity > 0");
            $stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Low Stock Products (quantity <= minimum_stock)
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE quantity <= minimum_stock AND quantity > 0");
            $stmt->execute();
            $stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Out of Stock Products
            $stmt = $conn->query("SELECT COUNT(*) as count FROM products WHERE quantity = 0");
            $stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Total Inventory Value (cost price × total quantity for ALL products)
            $stmt = $conn->query("SELECT COALESCE(SUM(quantity * COALESCE(cost_price, 0)), 0) as total FROM products");
            $stats['total_inventory_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Total Retail Value (selling price × total quantity for ALL products)
            $stmt = $conn->query("SELECT COALESCE(SUM(quantity * COALESCE(price, 0)), 0) as total FROM products");
            $stats['total_retail_value'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Total Products Count (including zero quantity)
            $stmt = $conn->query("SELECT COUNT(*) as count FROM products");
            $stats['total_products_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting inventory statistics: " . $e->getMessage());
            return [
                'total_products' => 0,
                'low_stock' => 0,
                'out_of_stock' => 0,
                'total_inventory_value' => 0,
                'total_retail_value' => 0,
                'total_products_count' => 0
            ];
        }
    }
}

/**
 * Get sales statistics for reports
 */
if (!function_exists('getSalesStatistics')) {
    function getSalesStatistics($conn, $start_date = null, $end_date = null) {
        try {
            $stats = [];
            
            // Build date filter
            $date_filter = "";
            if ($start_date && $end_date) {
                $date_filter = "WHERE DATE(created_at) BETWEEN :start_date AND :end_date";
            } elseif ($start_date) {
                $date_filter = "WHERE DATE(created_at) >= :start_date";
            } elseif ($end_date) {
                $date_filter = "WHERE DATE(created_at) <= :end_date";
            }
            
            // Total Sales Count
            $query = "SELECT COUNT(*) as count FROM sales " . $date_filter;
            $stmt = $conn->prepare($query);
            if ($start_date) $stmt->bindParam(':start_date', $start_date);
            if ($end_date) $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $stats['total_sales'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Total Revenue
            $query = "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales " . $date_filter;
            $stmt = $conn->prepare($query);
            if ($start_date) $stmt->bindParam(':start_date', $start_date);
            if ($end_date) $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $stats['total_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Average Sale Amount
            $query = "SELECT COALESCE(AVG(total_amount), 0) as avg FROM sales " . $date_filter;
            $stmt = $conn->prepare($query);
            if ($start_date) $stmt->bindParam(':start_date', $start_date);
            if ($end_date) $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $stats['avg_sale_amount'] = $stmt->fetch(PDO::FETCH_ASSOC)['avg'];
            
            // Unique Customers
            $query = "SELECT COUNT(DISTINCT customer_id) as count FROM sales " . $date_filter;
            $stmt = $conn->prepare($query);
            if ($start_date) $stmt->bindParam(':start_date', $start_date);
            if ($end_date) $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $stats['unique_customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting sales statistics: " . $e->getMessage());
            return [
                'total_sales' => 0,
                'total_revenue' => 0,
                'avg_sale_amount' => 0,
                'unique_customers' => 0
            ];
        }
    }
}

?>
