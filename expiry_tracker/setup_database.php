<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in and has admin permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user permissions
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;

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

// Only allow users with manage_expiry_tracker permission
if (!in_array('manage_expiry_tracker', $permissions)) {
    header("Location: expiry_tracker.php?error=permission_denied");
    exit();
}

$message = '';
$message_type = '';

// Handle database setup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_database'])) {
    try {
        // Check if database connection is working
        if (!$conn) {
            throw new PDOException("Database connection failed");
        }
        
        $conn->beginTransaction();
        
        // Create product_expiry_dates table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS product_expiry_dates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                product_id INT NOT NULL,
                batch_number VARCHAR(100),
                manufacturing_date DATE,
                expiry_date DATE NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                remaining_quantity INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(10,2) DEFAULT 0,
                supplier_id INT,
                location VARCHAR(255),
                status ENUM('active', 'expired', 'disposed', 'returned') DEFAULT 'active',
                expiry_category_id INT,
                alert_days_before INT DEFAULT 30,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_product_id (product_id),
                INDEX idx_expiry_date (expiry_date),
                INDEX idx_status (status),
                INDEX idx_expiry_category_id (expiry_category_id),
                INDEX idx_batch_number (batch_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create expiry_actions table
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

        // Create expiry_alert_settings table
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

        // Create expiry_categories table
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

        $conn->commit();
        
        // Now that the main transaction is committed, try to modify the products table
        try {
            // First check if products table exists
            $stmt = $conn->prepare("SHOW TABLES LIKE 'products'");
            $stmt->execute();
            $products_table_exists = $stmt->fetch();
            
            if ($products_table_exists) {
                $stmt = $conn->prepare("SHOW COLUMNS FROM products LIKE 'expiry_category_id'");
                $stmt->execute();
                $result = $stmt->fetch();

                if (!$result) {
                    $conn->exec("ALTER TABLE products ADD COLUMN expiry_category_id INT DEFAULT NULL AFTER category_id");
                    $conn->exec("ALTER TABLE products ADD FOREIGN KEY (expiry_category_id) REFERENCES expiry_categories(id) ON DELETE SET NULL");
                    $conn->exec("CREATE INDEX idx_expiry_category_id ON products (expiry_category_id)");
                }
            }
        } catch (PDOException $e) {
            // Log the error but continue - this is not critical for the main setup
            error_log("Warning: Could not add expiry_category_id to products table: " . $e->getMessage());
        }

        // Try to add foreign keys if the referenced tables exist
        try {
            // Add foreign key for product_expiry_dates.product_id if products table exists
            $stmt = $conn->prepare("SHOW TABLES LIKE 'products'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $conn->exec("ALTER TABLE product_expiry_dates ADD CONSTRAINT fk_product_expiry_product_id FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE");
            }
            
            // Add foreign key for product_expiry_dates.supplier_id if suppliers table exists
            $stmt = $conn->prepare("SHOW TABLES LIKE 'suppliers'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $conn->exec("ALTER TABLE product_expiry_dates ADD CONSTRAINT fk_product_expiry_supplier_id FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL");
            }
            
            // Add foreign key for product_expiry_dates.expiry_category_id
            $conn->exec("ALTER TABLE product_expiry_dates ADD CONSTRAINT fk_product_expiry_category_id FOREIGN KEY (expiry_category_id) REFERENCES expiry_categories(id) ON DELETE SET NULL");
            
            // Add foreign key for expiry_actions.product_expiry_id
            $conn->exec("ALTER TABLE expiry_actions ADD CONSTRAINT fk_expiry_actions_product_expiry_id FOREIGN KEY (product_expiry_id) REFERENCES product_expiry_dates(id) ON DELETE CASCADE");
            
            // Add foreign key for expiry_actions.user_id if users table exists
            $stmt = $conn->prepare("SHOW TABLES LIKE 'users'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $conn->exec("ALTER TABLE expiry_actions ADD CONSTRAINT fk_expiry_actions_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                $conn->exec("ALTER TABLE expiry_alert_settings ADD CONSTRAINT fk_expiry_alert_settings_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
            }
            
        } catch (PDOException $e) {
            // Log the error but continue - foreign keys are not critical for basic functionality
            error_log("Warning: Could not add some foreign keys: " . $e->getMessage());
        }
        
        $message = "Database setup completed successfully! All expiry tracker tables have been created.";
        $message_type = "success";
        
    } catch (PDOException $e) {
        // Only rollback if there's an active transaction
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $message = "Database setup failed: " . $e->getMessage();
        $message_type = "error";
    }
}

$page_title = "Setup Database";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - POS System</title>
    <link rel="stylesheet" href="../assets/css/expiry_tracker.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-database"></i> <?php echo $page_title; ?></h1>
            <div class="header-actions">
                <a href="expiry_tracker.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Tracker
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="setup-container">
            <div class="setup-card">
                <div class="setup-header">
                    <h3>Database Setup Required</h3>
                    <p>The expiry tracker requires several database tables to function properly. Click the button below to create all necessary tables.</p>
                </div>
                
                <div class="setup-content">
                    <h4>Tables that will be created:</h4>
                    <ul>
                        <li><strong>product_expiry_dates</strong> - Stores product expiry information</li>
                        <li><strong>expiry_actions</strong> - Tracks actions taken on expired items</li>
                        <li><strong>expiry_alert_settings</strong> - User preferences for alerts</li>
                        <li><strong>expiry_categories</strong> - Categories for expiry risk assessment</li>
                    </ul>
                    
                    <div class="setup-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This will create new tables in your database. Make sure you have a backup before proceeding.
                    </div>
                </div>
                
                <div class="setup-actions">
                    <form method="POST">
                        <button type="submit" name="setup_database" class="btn btn-primary">
                            <i class="fas fa-database"></i> Setup Database Tables
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
        .setup-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px 0;
        }
        
        .setup-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .setup-header {
            background: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        
        .setup-header h3 {
            margin: 0 0 15px 0;
            color: #495057;
        }
        
        .setup-header p {
            margin: 0;
            color: #6c757d;
            font-size: 16px;
        }
        
        .setup-content {
            padding: 30px;
        }
        
        .setup-content h4 {
            margin: 0 0 20px 0;
            color: #495057;
        }
        
        .setup-content ul {
            margin: 0 0 25px 0;
            padding-left: 20px;
        }
        
        .setup-content li {
            margin-bottom: 10px;
            color: #495057;
        }
        
        .setup-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .setup-actions {
            padding: 30px;
            text-align: center;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        
        .btn {
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 500;
        }
    </style>
</body>
</html>
