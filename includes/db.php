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
            name VARCHAR(100) NOT NULL,
            category_id INT NOT NULL,
            price DECIMAL(10, 2) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            barcode VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
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
    
    // Add role_id to users table if it doesn't exist
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'role_id'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if (!$result) {
        $conn->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL");
        $conn->exec("ALTER TABLE users ADD FOREIGN KEY (role_id) REFERENCES roles(id)");
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
        ['backup_security_enabled', '1'],
        ['backup_require_password', '1'],
        ['enable_sound', '1'],
        ['default_payment_method', 'cash'],
        ['allow_negative_stock', '0'],
        ['barcode_type', 'CODE128']
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
    
} catch(PDOException $e) {
    // Store connection error for login page
    $GLOBALS['db_connected'] = false;
    $GLOBALS['db_error'] = $e->getMessage();
}
?>