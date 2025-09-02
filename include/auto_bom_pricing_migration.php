<?php
/**
 * Auto BOM Pricing Migration
 * Creates necessary tables for price history tracking
 */

require_once __DIR__ . '/db.php';

function createAutoBOMPricingTables($conn) {
    try {
        $conn->beginTransaction();

        // Create auto_bom_price_history table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS auto_bom_price_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                selling_unit_id INT NOT NULL,
                old_price DECIMAL(10, 2) NOT NULL,
                new_price DECIMAL(10, 2) NOT NULL,
                change_reason VARCHAR(100) NOT NULL DEFAULT 'manual_update',
                changed_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_selling_unit_id (selling_unit_id),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE,
                FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create auto_bom_pricing_analytics table for performance metrics
        $conn->exec("
            CREATE TABLE IF NOT EXISTS auto_bom_pricing_analytics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                config_id INT NOT NULL,
                selling_unit_id INT NOT NULL,
                date_recorded DATE NOT NULL,
                avg_price DECIMAL(10, 2) NOT NULL,
                min_price DECIMAL(10, 2) NOT NULL,
                max_price DECIMAL(10, 2) NOT NULL,
                margin_percentage DECIMAL(5, 2) NOT NULL DEFAULT 0,
                sales_volume INT NOT NULL DEFAULT 0,
                revenue_generated DECIMAL(12, 2) NOT NULL DEFAULT 0,
                strategy_effectiveness_score DECIMAL(3, 2) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_unit_date (selling_unit_id, date_recorded),
                INDEX idx_config_date (config_id, date_recorded),
                INDEX idx_selling_unit_date (selling_unit_id, date_recorded),
                FOREIGN KEY (config_id) REFERENCES auto_bom_configs(id) ON DELETE CASCADE,
                FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create auto_bom_pricing_alerts table for monitoring price changes
        $conn->exec("
            CREATE TABLE IF NOT EXISTS auto_bom_pricing_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                selling_unit_id INT NOT NULL,
                alert_type ENUM('margin_low', 'price_spike', 'cost_increase', 'strategy_failure') NOT NULL,
                threshold_value DECIMAL(10, 2) NOT NULL,
                current_value DECIMAL(10, 2) NOT NULL,
                alert_message TEXT NOT NULL,
                is_resolved BOOLEAN DEFAULT FALSE,
                resolved_at TIMESTAMP NULL,
                resolved_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_selling_unit_id (selling_unit_id),
                INDEX idx_alert_type (alert_type),
                INDEX idx_is_resolved (is_resolved),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (selling_unit_id) REFERENCES auto_bom_selling_units(id) ON DELETE CASCADE,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add pricing-related columns to existing tables if they don't exist
        try {
            $conn->exec("
                ALTER TABLE auto_bom_selling_units 
                ADD COLUMN last_price_update TIMESTAMP NULL AFTER updated_at,
                ADD COLUMN price_update_frequency ENUM('manual', 'daily', 'weekly', 'monthly') DEFAULT 'manual' AFTER last_price_update,
                ADD COLUMN auto_price_adjustment BOOLEAN DEFAULT FALSE AFTER price_update_frequency
            ");
        } catch (PDOException $e) {
            // Columns might already exist
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }

        // Add indexes for better performance
        try {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_pricing_strategy (pricing_strategy)");
        } catch (PDOException $e) {
            // Index might already exist
        }

        try {
            $conn->exec("ALTER TABLE auto_bom_selling_units ADD INDEX idx_status_priority (status, priority)");
        } catch (PDOException $e) {
            // Index might already exist
        }

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        throw new Exception("Migration failed: " . $e->getMessage());
    }
}

// Function to check if migration is needed
function needsPricingMigration($conn) {
    try {
        // Check if price history table exists
        $stmt = $conn->query("SHOW TABLES LIKE 'auto_bom_price_history'");
        return $stmt->rowCount() === 0;
    } catch (PDOException $e) {
        return true; // Assume migration is needed if we can't check
    }
}

// Auto-run migration if this file is accessed directly
if (basename($_SERVER['PHP_SELF']) === 'auto_bom_pricing_migration.php') {
    try {
        if (needsPricingMigration($conn)) {
            createAutoBOMPricingTables($conn);
            echo "Auto BOM pricing migration completed successfully.\n";
        } else {
            echo "Auto BOM pricing tables already exist.\n";
        }
    } catch (Exception $e) {
        echo "Migration failed: " . $e->getMessage() . "\n";
        http_response_code(500);
    }
}
?>
