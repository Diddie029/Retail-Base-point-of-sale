<?php
/**
 * Auto BOM Manager Class
 * Core class for managing Auto BOM configurations and operations
 */

// Include required classes with error handling
try {
    require_once __DIR__ . '/PricingStrategy.php';
} catch (Exception $e) {
    // PricingStrategy file not found, will use fallback methods
}

try {
    require_once __DIR__ . '/UnitConverter.php';
} catch (Exception $e) {
    // UnitConverter file not found, will use basic conversions
}

class AutoBOMManager {
    private $conn;
    private $user_id;

    public function __construct($pdo_connection, $user_id = null) {
        $this->conn = $pdo_connection;
        $this->user_id = $user_id;
    }

    /**
     * Create a new Auto BOM configuration
     */
    public function createAutoBOM($config) {
        try {
            $this->conn->beginTransaction();

            // Validate configuration
            $this->validateAutoBOMConfig($config);

            // Insert Auto BOM configuration
            $stmt = $this->conn->prepare("
                INSERT INTO auto_bom_configs (
                    product_id, config_name, product_family_id, base_product_id,
                    base_unit, base_quantity, description, is_active, created_by
                ) VALUES (
                    :product_id, :config_name, :product_family_id, :base_product_id,
                    :base_unit, :base_quantity, :description, :is_active, :created_by
                )
            ");

            $stmt->execute([
                ':product_id' => $config['product_id'],
                ':config_name' => $config['config_name'],
                ':product_family_id' => $config['product_family_id'] ?? null,
                ':base_product_id' => $config['base_product_id'],
                ':base_unit' => $config['base_unit'] ?? 'each',
                ':base_quantity' => $config['base_quantity'] ?? 1,
                ':description' => $config['description'] ?? '',
                ':is_active' => $config['is_active'] ?? 1,
                ':created_by' => $this->user_id
            ]);

            $auto_bom_id = $this->conn->lastInsertId();

            // Create selling units if provided
            if (isset($config['selling_units']) && is_array($config['selling_units'])) {
                foreach ($config['selling_units'] as $unit) {
                    $this->createSellingUnit($auto_bom_id, $unit);
                }
            }

            // Update product to mark as Auto BOM enabled
            $this->updateProductAutoBOMStatus($config['product_id'], true, $config['auto_bom_type'] ?? 'unit_conversion');

            $this->conn->commit();
            return $auto_bom_id;

        } catch (Exception $e) {
            $this->conn->rollBack();
            throw new Exception("Failed to create Auto BOM: " . $e->getMessage());
        }
    }

    /**
     * Create a selling unit for an Auto BOM configuration
     */
    public function createSellingUnit($auto_bom_config_id, $unit_config) {
        // Validate unit configuration
        $this->validateSellingUnitConfig($unit_config);

        $stmt = $this->conn->prepare("
            INSERT INTO auto_bom_selling_units (
                auto_bom_config_id, unit_name, unit_quantity, unit_sku, unit_barcode,
                pricing_strategy, fixed_price, markup_percentage, min_profit_margin,
                market_price, dynamic_base_price, stock_level_threshold, demand_multiplier,
                hybrid_primary_strategy, hybrid_threshold_value, hybrid_fallback_strategy,
                status, priority, max_quantity_per_sale, image_url
            ) VALUES (
                :auto_bom_config_id, :unit_name, :unit_quantity, :unit_sku, :unit_barcode,
                :pricing_strategy, :fixed_price, :markup_percentage, :min_profit_margin,
                :market_price, :dynamic_base_price, :stock_level_threshold, :demand_multiplier,
                :hybrid_primary_strategy, :hybrid_threshold_value, :hybrid_fallback_strategy,
                :status, :priority, :max_quantity_per_sale, :image_url
            )
        ");

        $stmt->execute([
            ':auto_bom_config_id' => $auto_bom_config_id,
            ':unit_name' => $unit_config['unit_name'],
            ':unit_quantity' => $unit_config['unit_quantity'],
            ':unit_sku' => $unit_config['unit_sku'] ?? null,
            ':unit_barcode' => $unit_config['unit_barcode'] ?? null,
            ':pricing_strategy' => $unit_config['pricing_strategy'] ?? 'fixed',
            ':fixed_price' => $unit_config['fixed_price'] ?? null,
            ':markup_percentage' => $unit_config['markup_percentage'] ?? 0,
            ':min_profit_margin' => $unit_config['min_profit_margin'] ?? 0,
            ':market_price' => $unit_config['market_price'] ?? null,
            ':dynamic_base_price' => $unit_config['dynamic_base_price'] ?? null,
            ':stock_level_threshold' => $unit_config['stock_level_threshold'] ?? null,
            ':demand_multiplier' => $unit_config['demand_multiplier'] ?? 1.0,
            ':hybrid_primary_strategy' => $unit_config['hybrid_primary_strategy'] ?? 'fixed',
            ':hybrid_threshold_value' => $unit_config['hybrid_threshold_value'] ?? null,
            ':hybrid_fallback_strategy' => $unit_config['hybrid_fallback_strategy'] ?? 'cost_based',
            ':status' => $unit_config['status'] ?? 'active',
            ':priority' => $unit_config['priority'] ?? 0,
            ':max_quantity_per_sale' => $unit_config['max_quantity_per_sale'] ?? null,
            ':image_url' => $unit_config['image_url'] ?? null
        ]);

        return $this->conn->lastInsertId();
    }

    /**
     * Calculate selling unit price based on strategy
     */
    public function calculateSellingUnitPrice($selling_unit_id, $additional_data = []) {
        // Get selling unit configuration
        $unit_config = $this->getSellingUnitConfig($selling_unit_id);
        if (!$unit_config) {
            throw new Exception("Selling unit not found: $selling_unit_id");
        }

        // Get base product cost
        $base_cost = $this->getBaseProductCost($unit_config['base_product_id']);

        // Try using pricing strategy factory
        try {
            // Create pricing strategy
            $strategy = PricingStrategyFactory::createStrategy($unit_config['pricing_strategy']);
            // Calculate price
            return $strategy->calculatePrice($unit_config, $base_cost, $additional_data);
        } catch (Exception $e) {
            // Fallback to simple calculation if strategy fails
            return $this->calculateFallbackPrice($unit_config, $base_cost);
        }
    }
    
    /**
     * Fallback price calculation method
     */
    private function calculateFallbackPrice($unit_config, $base_cost) {
        $strategy = $unit_config['pricing_strategy'] ?? 'fixed';
        
        switch ($strategy) {
            case 'fixed':
                return (float) ($unit_config['fixed_price'] ?? 0);
                
            case 'cost_based':
                $base_unit_cost = $base_cost / ($unit_config['base_quantity'] ?? 1);
                $unit_cost = $base_unit_cost * ($unit_config['unit_quantity'] ?? 1);
                $markup_percentage = $unit_config['markup_percentage'] ?? 20; // Default 20% markup
                return $unit_cost * (1 + $markup_percentage / 100);
                
            case 'market_based':
                return (float) ($unit_config['market_price'] ?? $unit_config['fixed_price'] ?? 0);
                
            case 'dynamic':
                $base_price = $unit_config['dynamic_base_price'] ?? $unit_config['fixed_price'] ?? $base_cost;
                $demand_multiplier = $unit_config['demand_multiplier'] ?? 1.0;
                return $base_price * $demand_multiplier;
                
            case 'hybrid':
                // For hybrid, fall back to fixed or cost-based
                if (isset($unit_config['fixed_price']) && $unit_config['fixed_price'] > 0) {
                    return (float) $unit_config['fixed_price'];
                } else {
                    // Use cost-based as final fallback
                    $base_unit_cost = $base_cost / ($unit_config['base_quantity'] ?? 1);
                    $unit_cost = $base_unit_cost * ($unit_config['unit_quantity'] ?? 1);
                    $markup_percentage = $unit_config['markup_percentage'] ?? 20;
                    return $unit_cost * (1 + $markup_percentage / 100);
                }
                
            default:
                // Default to fixed price or cost-based calculation
                if (isset($unit_config['fixed_price']) && $unit_config['fixed_price'] > 0) {
                    return (float) $unit_config['fixed_price'];
                } else {
                    $base_unit_cost = $base_cost / ($unit_config['base_quantity'] ?? 1);
                    $unit_cost = $base_unit_cost * ($unit_config['unit_quantity'] ?? 1);
                    return $unit_cost * 1.2; // 20% default markup
                }
        }
    }

    /**
     * Check if there's enough base stock for a selling unit
     */
    public function checkBaseStockAvailability($product_id, $quantity, $selling_unit_id = null) {
        // Get product base configuration
        $product = $this->getProductAutoBOMConfig($product_id);
        if (!$product || !$product['is_auto_bom_enabled']) {
            throw new Exception("Product is not Auto BOM enabled");
        }

        // Get base product stock
        $base_stock = $this->getProductStock($product['id']);

        if ($selling_unit_id) {
            // Convert selling unit quantity to base units
            $unit_config = $this->getSellingUnitConfig($selling_unit_id);
            $base_quantity_needed = $this->convertToBaseUnits($selling_unit_id, $quantity);
        } else {
            $base_quantity_needed = $quantity * ($product['base_quantity'] ?? 1);
        }

        return [
            'available' => $base_stock >= $base_quantity_needed,
            'required' => $base_quantity_needed,
            'available_stock' => $base_stock,
            'shortage' => max(0, $base_quantity_needed - $base_stock)
        ];
    }

    /**
     * Convert selling unit quantity to base units
     */
    public function convertToBaseUnits($selling_unit_id, $quantity) {
        $unit_config = $this->getSellingUnitConfig($selling_unit_id);
        if (!$unit_config) {
            throw new Exception("Selling unit not found: $selling_unit_id");
        }

        // Get Auto BOM configuration for base unit info
        $bom_config = $this->getAutoBOMConfig($unit_config['auto_bom_config_id']);

        // Convert selling unit quantity to base units
        return $quantity * $unit_config['unit_quantity'] / $bom_config['base_quantity'];
    }

    /**
     * Get available selling units for a base product
     */
    public function getAvailableSellingUnits($base_product_id) {
        $stmt = $this->conn->prepare("
            SELECT
                su.*,
                abc.product_id,
                abc.base_product_id,
                abc.base_unit,
                abc.base_quantity,
                p.name as product_name,
                p.sku as product_sku,
                bp.name as base_product_name,
                bp.cost_price as base_cost_price
            FROM auto_bom_selling_units su
            INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
            INNER JOIN products p ON abc.product_id = p.id
            INNER JOIN products bp ON abc.base_product_id = bp.id
            WHERE abc.base_product_id = :base_product_id
            AND abc.is_active = 1
            AND su.status = 'active'
            ORDER BY su.priority DESC, su.unit_name ASC
        ");

        $stmt->execute([':base_product_id' => $base_product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update prices for all selling units in a configuration
     */
    public function updatePricesBasedOnStrategy($config_id) {
        $selling_units = $this->getSellingUnitsByConfig($config_id);

        foreach ($selling_units as $unit) {
            try {
                $old_price = $unit['fixed_price']; // This would be the current price
                $new_price = $this->calculateSellingUnitPrice($unit['id']);

                // Update the selling unit price
                $this->updateSellingUnitPrice($unit['id'], $new_price);

                // Log price change
                $this->logPriceChange($unit['id'], $old_price, $new_price, 'dynamic_pricing');

            } catch (Exception $e) {
                // Log error but continue with other units
                error_log("Failed to update price for unit {$unit['id']}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process a sale for Auto BOM units
     */
    public function processSale($selling_unit_id, $quantity, $sale_price = null) {
        try {
            $this->conn->beginTransaction();

            // Get unit configuration
            $unit_config = $this->getSellingUnitConfig($selling_unit_id);

            // Check stock availability
            $stock_check = $this->checkBaseStockAvailability(
                $unit_config['base_product_id'],
                $quantity,
                $selling_unit_id
            );

            if (!$stock_check['available']) {
                throw new Exception("Insufficient stock. Required: {$stock_check['required']}, Available: {$stock_check['available_stock']}");
            }

            // Calculate sale price if not provided
            if ($sale_price === null) {
                $sale_price = $this->calculateSellingUnitPrice($selling_unit_id);
            }

            // Convert to base units for inventory deduction
            $base_quantity = $this->convertToBaseUnits($selling_unit_id, $quantity);

            // Deduct from base product inventory
            $this->deductInventory($unit_config['base_product_id'], $base_quantity);

            // Log the sale transaction
            $this->logSaleTransaction($selling_unit_id, $quantity, $sale_price, $base_quantity);

            $this->conn->commit();

            return [
                'success' => true,
                'sale_price' => $sale_price,
                'base_quantity_deducted' => $base_quantity
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * Get Auto BOM configuration by ID
     */
    private function getAutoBOMConfig($config_id) {
        $stmt = $this->conn->prepare("
            SELECT * FROM auto_bom_configs WHERE id = :id
        ");
        $stmt->execute([':id' => $config_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get selling unit configuration
     */
    private function getSellingUnitConfig($selling_unit_id) {
        $stmt = $this->conn->prepare("
            SELECT su.*, abc.base_product_id, abc.base_unit, abc.base_quantity
            FROM auto_bom_selling_units su
            INNER JOIN auto_bom_configs abc ON su.auto_bom_config_id = abc.id
            WHERE su.id = :id
        ");
        $stmt->execute([':id' => $selling_unit_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get selling units by configuration ID
     */
    private function getSellingUnitsByConfig($config_id) {
        $stmt = $this->conn->prepare("
            SELECT * FROM auto_bom_selling_units WHERE auto_bom_config_id = :config_id
        ");
        $stmt->execute([':config_id' => $config_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get product Auto BOM configuration
     */
    private function getProductAutoBOMConfig($product_id) {
        $stmt = $this->conn->prepare("
            SELECT * FROM products WHERE id = :id
        ");
        $stmt->execute([':id' => $product_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get product stock
     */
    private function getProductStock($product_id) {
        $stmt = $this->conn->prepare("
            SELECT quantity FROM products WHERE id = :id
        ");
        $stmt->execute([':id' => $product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['quantity'] : 0;
    }

    /**
     * Get base product cost
     */
    private function getBaseProductCost($product_id) {
        $stmt = $this->conn->prepare("
            SELECT cost_price FROM products WHERE id = :id
        ");
        $stmt->execute([':id' => $product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float) $result['cost_price'] : 0;
    }

    /**
     * Update product Auto BOM status
     */
    private function updateProductAutoBOMStatus($product_id, $enabled, $type = null) {
        $stmt = $this->conn->prepare("
            UPDATE products
            SET is_auto_bom_enabled = :enabled,
                auto_bom_type = :type,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':type' => $type,
            ':id' => $product_id
        ]);
    }

    /**
     * Update selling unit price
     */
    private function updateSellingUnitPrice($selling_unit_id, $price) {
        $stmt = $this->conn->prepare("
            UPDATE auto_bom_selling_units
            SET fixed_price = :price, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':price' => $price, ':id' => $selling_unit_id]);
    }

    /**
     * Deduct inventory from base product
     */
    private function deductInventory($product_id, $quantity) {
        $stmt = $this->conn->prepare("
            UPDATE products
            SET quantity = quantity - :quantity, updated_at = NOW()
            WHERE id = :id AND quantity >= :quantity
        ");
        $stmt->execute([
            ':quantity' => $quantity,
            ':id' => $product_id,
            ':quantity' => $quantity
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("Failed to deduct inventory - insufficient stock");
        }
    }

    /**
     * Log price change
     */
    private function logPriceChange($selling_unit_id, $old_price, $new_price, $reason) {
        try {
            // First try to create the table if it doesn't exist
            $this->createPriceHistoryTable();
            
            $stmt = $this->conn->prepare("
                INSERT INTO auto_bom_price_history (
                    selling_unit_id, old_price, new_price, change_reason, changed_by, created_at
                ) VALUES (
                    :selling_unit_id, :old_price, :new_price, :change_reason, :changed_by, NOW()
                )
            ");
            $stmt->execute([
                ':selling_unit_id' => $selling_unit_id,
                ':old_price' => $old_price,
                ':new_price' => $new_price,
                ':change_reason' => $reason,
                ':changed_by' => $this->user_id
            ]);
        } catch (PDOException $e) {
            // If price history logging fails, log to activity instead
            error_log("Failed to log price change to history table: " . $e->getMessage());
            
            // Fallback: log to activity_logs table
            try {
                $stmt = $this->conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, action, details, created_at
                    ) VALUES (
                        :user_id, :action, :details, NOW()
                    )
                ");
                $stmt->execute([
                    ':user_id' => $this->user_id,
                    ':action' => 'price_change',
                    ':details' => json_encode([
                        'selling_unit_id' => $selling_unit_id,
                        'old_price' => $old_price,
                        'new_price' => $new_price,
                        'change_reason' => $reason
                    ])
                ]);
            } catch (PDOException $fallback_error) {
                error_log("Failed to log price change to activity_logs: " . $fallback_error->getMessage());
            }
        }
    }
    
    /**
     * Create price history table if it doesn't exist
     */
    private function createPriceHistoryTable() {
        try {
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS auto_bom_price_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    selling_unit_id INT NOT NULL,
                    old_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    new_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    change_reason VARCHAR(255) DEFAULT 'manual_update',
                    changed_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_selling_unit_id (selling_unit_id),
                    INDEX idx_created_at (created_at),
                    INDEX idx_changed_by (changed_by)
                )
            ");
        } catch (PDOException $e) {
            // Table creation failed, but don't throw error
            error_log("Could not create auto_bom_price_history table: " . $e->getMessage());
        }
    }

    /**
     * Log sale transaction
     */
    private function logSaleTransaction($selling_unit_id, $quantity, $sale_price, $base_quantity) {
        // This would typically insert into a sales_transactions table
        // For now, we'll create a simple log entry
        $stmt = $this->conn->prepare("
            INSERT INTO activity_logs (
                user_id, action, details, created_at
            ) VALUES (
                :user_id, :action, :details, NOW()
            )
        ");
        $stmt->execute([
            ':user_id' => $this->user_id,
            ':action' => 'auto_bom_sale',
            ':details' => json_encode([
                'selling_unit_id' => $selling_unit_id,
                'quantity' => $quantity,
                'sale_price' => $sale_price,
                'base_quantity' => $base_quantity
            ])
        ]);
    }

    /**
     * Validate Auto BOM configuration
     */
    private function validateAutoBOMConfig($config) {
        if (!isset($config['product_id']) || !is_numeric($config['product_id'])) {
            throw new Exception("Valid product_id is required");
        }
        if (!isset($config['config_name']) || empty($config['config_name'])) {
            throw new Exception("Configuration name is required");
        }
        if (!isset($config['base_product_id']) || !is_numeric($config['base_product_id'])) {
            throw new Exception("Valid base_product_id is required");
        }
    }

    /**
     * Validate selling unit configuration
     */
    private function validateSellingUnitConfig($config) {
        if (!isset($config['unit_name']) || empty($config['unit_name'])) {
            throw new Exception("Unit name is required");
        }
        if (!isset($config['unit_quantity']) || !is_numeric($config['unit_quantity']) || $config['unit_quantity'] <= 0) {
            throw new Exception("Valid unit quantity is required");
        }
    }
}
?>
