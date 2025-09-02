<?php
/**
 * Pricing Strategy Interface and Implementations
 * Handles different pricing strategies for Auto BOM selling units
 */

interface PricingStrategyInterface {
    /**
     * Calculate the selling price based on the strategy
     *
     * @param array $unit_config Selling unit configuration
     * @param float $base_cost Base product cost
     * @param array $additional_data Additional data for calculations
     * @return float Calculated price
     */
    public function calculatePrice($unit_config, $base_cost, $additional_data = []);
}

/**
 * Fixed Price Strategy
 * Uses a fixed price regardless of base cost
 */
class FixedPriceStrategy implements PricingStrategyInterface {
    public function calculatePrice($unit_config, $base_cost, $additional_data = []) {
        if (!isset($unit_config['fixed_price']) || $unit_config['fixed_price'] === null) {
            throw new Exception('Fixed price not set for this selling unit');
        }
        return (float) $unit_config['fixed_price'];
    }
}

/**
 * Cost-Based Pricing Strategy
 * Calculates price based on base cost with markup
 */
class CostBasedStrategy implements PricingStrategyInterface {
    public function calculatePrice($unit_config, $base_cost, $additional_data = []) {
        $base_unit_cost = $base_cost / $unit_config['base_quantity'];
        $unit_cost = $base_unit_cost * $unit_config['unit_quantity'];

        $markup_percentage = $unit_config['markup_percentage'] ?? 0;
        $markup = $unit_cost * ($markup_percentage / 100);

        $calculated_price = $unit_cost + $markup;

        // Apply minimum profit margin if set
        $min_profit_margin = $unit_config['min_profit_margin'] ?? 0;
        if ($min_profit_margin > 0) {
            $min_price = $unit_cost * (1 + $min_profit_margin / 100);
            $calculated_price = max($calculated_price, $min_price);
        }

        return round($calculated_price, 2);
    }
}

/**
 * Market-Based Pricing Strategy
 * Uses market price as the base
 */
class MarketBasedStrategy implements PricingStrategyInterface {
    public function calculatePrice($unit_config, $base_cost, $additional_data = []) {
        if (!isset($unit_config['market_price']) || $unit_config['market_price'] === null) {
            throw new Exception('Market price not set for this selling unit');
        }

        $market_price = (float) $unit_config['market_price'];

        // Apply any market adjustments if provided
        $market_adjustment = $additional_data['market_adjustment'] ?? 0;
        $adjusted_price = $market_price * (1 + $market_adjustment / 100);

        return round($adjusted_price, 2);
    }
}

/**
 * Dynamic Pricing Strategy
 * Adjusts price based on stock levels and demand
 */
class DynamicPricingStrategy implements PricingStrategyInterface {
    public function calculatePrice($unit_config, $base_cost, $additional_data = []) {
        $base_price = $unit_config['dynamic_base_price'] ?? $base_cost;

        // Get stock level information
        $current_stock = $additional_data['current_stock'] ?? 0;
        $stock_threshold = $unit_config['stock_level_threshold'] ?? 10;
        $demand_multiplier = $unit_config['demand_multiplier'] ?? 1.0;

        // Calculate stock-based adjustment
        $stock_ratio = min($current_stock / $stock_threshold, 2.0); // Cap at 200%
        $stock_adjustment = (2.0 - $stock_ratio) * 0.1; // 10% max adjustment

        // Apply demand multiplier
        $final_price = $base_price * $demand_multiplier;

        // Apply stock adjustment
        if ($current_stock < $stock_threshold) {
            $final_price *= (1 + $stock_adjustment);
        } else {
            $final_price *= (1 - $stock_adjustment * 0.5); // Reduced adjustment for high stock
        }

        return round($final_price, 2);
    }
}

/**
 * Hybrid Pricing Strategy
 * Combines multiple strategies with fallback logic
 */
class HybridPricingStrategy implements PricingStrategyInterface {
    private $strategies = [];

    public function __construct() {
        $this->strategies = [
            'fixed' => new FixedPriceStrategy(),
            'cost_based' => new CostBasedStrategy(),
            'market_based' => new MarketBasedStrategy()
        ];
    }

    public function calculatePrice($unit_config, $base_cost, $additional_data = []) {
        $primary_strategy = $unit_config['hybrid_primary_strategy'] ?? 'fixed';
        $threshold_value = $unit_config['hybrid_threshold_value'] ?? null;
        $fallback_strategy = $unit_config['hybrid_fallback_strategy'] ?? 'cost_based';

        try {
            // Try primary strategy first
            if (isset($this->strategies[$primary_strategy])) {
                $price = $this->strategies[$primary_strategy]->calculatePrice($unit_config, $base_cost, $additional_data);

                // Check if price meets threshold requirements
                if ($threshold_value !== null) {
                    $comparison_value = $additional_data['comparison_value'] ?? $base_cost;

                    // If primary strategy price is too low/high compared to threshold, use fallback
                    if (($primary_strategy === 'cost_based' && $price < $threshold_value) ||
                        ($primary_strategy === 'market_based' && $price > $threshold_value)) {

                        if (isset($this->strategies[$fallback_strategy])) {
                            $price = $this->strategies[$fallback_strategy]->calculatePrice($unit_config, $base_cost, $additional_data);
                        }
                    }
                }

                return $price;
            }
        } catch (Exception $e) {
            // If primary strategy fails, try fallback
            if (isset($this->strategies[$fallback_strategy])) {
                return $this->strategies[$fallback_strategy]->calculatePrice($unit_config, $base_cost, $additional_data);
            }
        }

        // Final fallback to cost-based pricing
        return $this->strategies['cost_based']->calculatePrice($unit_config, $base_cost, $additional_data);
    }
}

/**
 * Pricing Strategy Factory
 * Creates the appropriate pricing strategy based on configuration
 */
class PricingStrategyFactory {
    public static function createStrategy($strategy_type) {
        switch ($strategy_type) {
            case 'fixed':
                return new FixedPriceStrategy();
            case 'cost_based':
                return new CostBasedStrategy();
            case 'market_based':
                return new MarketBasedStrategy();
            case 'dynamic':
                return new DynamicPricingStrategy();
            case 'hybrid':
                return new HybridPricingStrategy();
            default:
                throw new Exception("Unknown pricing strategy: $strategy_type");
        }
    }

    /**
     * Get all available strategy types
     */
    public static function getAvailableStrategies() {
        return [
            'fixed' => 'Fixed Price',
            'cost_based' => 'Cost-Based (Markup)',
            'market_based' => 'Market-Based',
            'dynamic' => 'Dynamic Pricing',
            'hybrid' => 'Hybrid Strategy'
        ];
    }

    /**
     * Validate strategy configuration
     */
    public static function validateStrategyConfig($strategy_type, $config) {
        switch ($strategy_type) {
            case 'fixed':
                return isset($config['fixed_price']) && is_numeric($config['fixed_price']);

            case 'cost_based':
                return isset($config['markup_percentage']) && is_numeric($config['markup_percentage']);

            case 'market_based':
                return isset($config['market_price']) && is_numeric($config['market_price']);

            case 'dynamic':
                return isset($config['dynamic_base_price']) && is_numeric($config['dynamic_base_price']);

            case 'hybrid':
                return isset($config['hybrid_primary_strategy']) &&
                       isset($config['hybrid_fallback_strategy']);

            default:
                return false;
        }
    }
}
?>
