<?php
/**
 * Auto BOM System Database Migration
 * This file contains all database schema changes for the Auto BOM system
 */

require_once __DIR__ . '/db.php';

// Migration class to handle database changes
class AutoBOMMigrator {
    private $conn;

    public function __construct($pdo) {
        $this->conn = $pdo;
    }

    /**
     * Run all migrations
     */
    public function runMigrations() {
        try {
            $this->conn->beginTransaction();

            echo "Starting Auto BOM database migrations...\n";

            $this->addProductColumns();
                    $this->addStatusColumnToCategories();
        $this->createProductFamiliesTable();
        $this->createAutoBOMConfigsTable();
            $this->createAutoBOMSellingUnitsTable();
            $this->createAutoBOMPriceHistoryTable();
            $this->createAutoBOMPermissions();
            $this->createAutoBOMIndexes();

            $this->conn->commit();
            echo "All Auto BOM migrations completed successfully!\n";

        } catch (Exception $e) {
            $this->conn->rollBack();
            echo "Migration failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Add status column to categories table
     */
    private function addStatusColumnToCategories() {
        echo "Adding status column to categories table...\n";

        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM categories LIKE 'status'");
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $sql = "ALTER TABLE categories ADD COLUMN status ENUM('active', 'inactive') DEFAULT 'active'";
                $this->conn->exec($sql);
                echo "Added status column to categories table\n";

                // Update existing categories to be active
                $this->conn->exec("UPDATE categories SET status = 'active' WHERE status IS NULL");
                echo "Set existing categories to active status\n";
            } else {
                echo "Status column already exists in categories table\n";
            }
        } catch (Exception $e) {
            echo "Error adding status column to categories: " . $e->getMessage() . "\n";
        }

        echo "Categories table migration completed.\n";
    }

    /**
     * Add Auto BOM columns to products table
     */
    private function addProductColumns() {
        echo "Adding Auto BOM columns to products table...\n";

        $columns = [
            'is_auto_bom_enabled' => "TINYINT(1) DEFAULT 0",
            'auto_bom_type' => "ENUM('unit_conversion', 'repackaging', 'bulk_selling') DEFAULT NULL",
            'base_unit' => "VARCHAR(50) DEFAULT 'each'",
            'base_quantity' => "DECIMAL(10,3) DEFAULT 1",
            'product_family_id' => "INT DEFAULT NULL"
        ];

        foreach ($columns as $column => $definition) {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM products LIKE ?");
            $stmt->execute([$column]);

            if ($stmt->rowCount() === 0) {
                $sql = "ALTER TABLE products ADD COLUMN `$column` $definition";
                $this->conn->exec($sql);
                echo "Added column: $column\n";
            } else {
                echo "Column $column already exists\n";
            }
        }

        // Add indexes
        $indexes = [
            'idx_is_auto_bom_enabled' => 'is_auto_bom_enabled',
            'idx_product_family_id' => 'product_family_id'
        ];

        foreach ($indexes as $indexName => $columnName) {
            try {
                $sql = "CREATE INDEX `$indexName` ON products(`$columnName`)";
                $this->conn->exec($sql);
                echo "Added index: $indexName\n";
            } catch (Exception $e) {
                // Index might already exist
                echo "Index $indexName already exists or error: " . $e->getMessage() . "\n";
            }
        }

        // Add foreign key constraint (only if product_families table exists)
        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'product_families'");
            if ($stmt->rowCount() > 0) {
                $sql = "ALTER TABLE products ADD CONSTRAINT fk_products_product_family_id FOREIGN KEY (product_family_id) REFERENCES product_families(id) ON DELETE SET NULL";
                $this->conn->exec($sql);
                echo "Added foreign key constraint\n";
            }
        } catch (Exception $e) {
            echo "Foreign key constraint already exists or error: " . $e->getMessage() . "\n";
        }

        echo "Product columns added successfully.\n";
    }

    /**
     * Create product_families table
     */
    private function createProductFamiliesTable() {
        echo "Creating product_families table...\n";

        $sql = "
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
        ";

        $this->conn->exec($sql);
        echo "product_families table created successfully.\n";
    }

    /**
     * Create auto_bom_configs table
     */
    private function createAutoBOMConfigsTable() {
        echo "Creating auto_bom_configs table...\n";

        $sql = "
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
        ";

        $this->conn->exec($sql);
        echo "auto_bom_configs table created successfully.\n";
    }

    /**
     * Create auto_bom_selling_units table
     */
    private function createAutoBOMSellingUnitsTable() {
        echo "Creating auto_bom_selling_units table...\n";

        $sql = "
            CREATE TABLE IF NOT EXISTS auto_bom_selling_units (
                id INT AUTO_INCREMENT PRIMARY KEY,
                auto_bom_config_id INT NOT NULL,
                unit_name VARCHAR(100) NOT NULL,
                unit_quantity DECIMAL(10,3) NOT NULL,
                unit_sku VARCHAR(100) UNIQUE,
                unit_barcode VARCHAR(50) UNIQUE,

                -- Pricing Strategy
                pricing_strategy ENUM('fixed', 'cost_based', 'market_based', 'dynamic', 'hybrid') DEFAULT 'fixed',

                -- Fixed Price Strategy
                fixed_price DECIMAL(10,2) DEFAULT NULL,

                -- Cost-Based Strategy
                markup_percentage DECIMAL(5,2) DEFAULT 0,
                min_profit_margin DECIMAL(5,2) DEFAULT 0,

                -- Market-Based Strategy
                market_price DECIMAL(10,2) DEFAULT NULL,

                -- Dynamic Pricing
                dynamic_base_price DECIMAL(10,2) DEFAULT NULL,
                stock_level_threshold INT DEFAULT NULL,
                demand_multiplier DECIMAL(3,2) DEFAULT 1.0,

                -- Hybrid Settings
                hybrid_primary_strategy ENUM('fixed', 'cost_based', 'market_based') DEFAULT 'fixed',
                hybrid_threshold_value DECIMAL(10,2) DEFAULT NULL,
                hybrid_fallback_strategy ENUM('fixed', 'cost_based', 'market_based') DEFAULT 'cost_based',

                -- General Settings
                status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
                priority INT DEFAULT 0,
                max_quantity_per_sale INT DEFAULT NULL,
                image_url VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (auto_bom_config_id) REFERENCES auto_bom_configs(id) ON DELETE CASCADE,
                INDEX idx_auto_bom_config_id (auto_bom_config_id),
                INDEX idx_unit_sku (unit_sku),
                INDEX idx_unit_barcode (unit_barcode),
                INDEX idx_pricing_strategy (pricing_strategy),
                INDEX idx_status (status),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->conn->exec($sql);
        echo "auto_bom_selling_units table created successfully.\n";
    }

    /**
     * Create auto_bom_price_history table
     */
    private function createAutoBOMPriceHistoryTable() {
        echo "Creating auto_bom_price_history table...\n";

        $sql = "
            CREATE TABLE IF NOT EXISTS auto_bom_price_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                selling_unit_id INT NOT NULL,
                old_price DECIMAL(10,2),
                new_price DECIMAL(10,2),
                change_reason ENUM('manual', 'cost_update', 'dynamic_pricing', 'bulk_update') DEFAULT 'manual',
                changed_by INT NOT NULL,
                change_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE,
                FOREIGN KEY (changed_by) REFERENCES users(id),
                INDEX idx_selling_unit_id (selling_unit_id),
                INDEX idx_changed_by (changed_by),
                INDEX idx_change_date (change_date),
                INDEX idx_change_reason (change_reason)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $this->conn->exec($sql);
        echo "auto_bom_price_history table created successfully.\n";
    }

    /**
     * Create Auto BOM permissions
     */
    private function createAutoBOMPermissions() {
        echo "Creating Auto BOM permissions...\n";

        $permissions = [
            ['name' => 'manage_auto_boms', 'description' => 'Create, edit, and delete Auto BOM configurations'],
            ['name' => 'view_auto_boms', 'description' => 'View Auto BOM configurations and units'],
            ['name' => 'manage_auto_bom_pricing', 'description' => 'Modify pricing strategies and prices for Auto BOM units'],
            ['name' => 'view_auto_bom_pricing', 'description' => 'View pricing configurations for Auto BOM units'],
            ['name' => 'view_auto_bom_reports', 'description' => 'Access Auto BOM analytics and reports']
        ];

        foreach ($permissions as $permission) {
            try {
                $stmt = $this->conn->prepare("
                    INSERT INTO permissions (name, description, created_at)
                    VALUES (:name, :description, NOW())
                    ON DUPLICATE KEY UPDATE description = VALUES(description)
                ");
                $stmt->execute($permission);
                echo "Permission '{$permission['name']}' created or updated\n";
            } catch (Exception $e) {
                echo "Permission '{$permission['name']}' already exists or error: " . $e->getMessage() . "\n";
            }
        }

        echo "Auto BOM permissions created successfully.\n";
    }

    /**
     * Create additional indexes for performance
     */
    private function createAutoBOMIndexes() {
        echo "Creating additional indexes...\n";

        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_products_auto_bom ON products(is_auto_bom_enabled, auto_bom_type, status)",
            "CREATE INDEX IF NOT EXISTS idx_auto_bom_configs_product_family ON auto_bom_configs(product_family_id, is_active)",
            "CREATE INDEX IF NOT EXISTS idx_auto_bom_units_config_status ON auto_bom_selling_units(auto_bom_config_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_auto_bom_price_history_unit_date ON auto_bom_price_history(selling_unit_id, change_date)"
        ];

        foreach ($indexes as $index) {
            $this->conn->exec($index);
        }

        echo "Additional indexes created successfully.\n";
    }

    /**
     * Get migration status
     */
    public function getMigrationStatus() {
        $tables = [
            'product_families',
            'auto_bom_configs',
            'auto_bom_selling_units',
            'auto_bom_price_history'
        ];

        $status = [];

        foreach ($tables as $table) {
            $stmt = $this->conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $status[$table] = $stmt->rowCount() > 0;
        }

        // Check if product columns exist
        $stmt = $this->conn->prepare("SHOW COLUMNS FROM products LIKE 'is_auto_bom_enabled'");
        $stmt->execute();
        $status['product_columns'] = $stmt->rowCount() > 0;

        return $status;
    }
}

// Run migrations if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        $migrator = new AutoBOMMigrator($conn);
        $migrator->runMigrations();

        echo "\nMigration Status:\n";
        $status = $migrator->getMigrationStatus();
        foreach ($status as $component => $exists) {
            echo "✓ $component: " . ($exists ? 'Created' : 'Missing') . "\n";
        }

    } catch (Exception $e) {
        echo "Migration failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
