<?php
/**
 * Unit Converter Class
 * Handles unit conversions for Auto BOM system
 */

class UnitConverter {
    // Unit conversion factors (relative to base units)
    private static $unit_factors = [
        // Weight units
        'kg' => 1000,      // 1 kg = 1000 grams
        'g' => 1,          // Base unit for weight
        'mg' => 0.001,     // 1 mg = 0.001 grams
        'lb' => 453.592,   // 1 lb = 453.592 grams
        'oz' => 28.35,     // 1 oz = 28.35 grams

        // Volume units
        'l' => 1000,       // 1 liter = 1000 ml
        'ml' => 1,         // Base unit for volume
        'gal' => 3785.41,  // 1 gallon = 3785.41 ml
        'qt' => 946.353,   // 1 quart = 946.353 ml
        'pt' => 473.176,   // 1 pint = 473.176 ml
        'fl_oz' => 29.5735, // 1 fluid oz = 29.5735 ml

        // Count units
        'each' => 1,       // Base unit for count
        'dozen' => 12,     // 1 dozen = 12 each
        'pack' => 1,       // Pack can vary, treat as each
        'case' => 1,       // Case can vary, treat as each
        'box' => 1,        // Box can vary, treat as each
        'bottle' => 1,     // Bottle can vary, treat as each
        'can' => 1,        // Can can vary, treat as each

        // Length units
        'm' => 100,        // 1 meter = 100 cm
        'cm' => 1,         // Base unit for length
        'mm' => 0.1,       // 1 mm = 0.1 cm
        'in' => 2.54,      // 1 inch = 2.54 cm
        'ft' => 30.48,     // 1 foot = 30.48 cm
        'yd' => 91.44,     // 1 yard = 91.44 cm
    ];

    // Unit categories for validation
    private static $unit_categories = [
        'weight' => ['kg', 'g', 'mg', 'lb', 'oz'],
        'volume' => ['l', 'ml', 'gal', 'qt', 'pt', 'fl_oz'],
        'count' => ['each', 'dozen', 'pack', 'case', 'box', 'bottle', 'can'],
        'length' => ['m', 'cm', 'mm', 'in', 'ft', 'yd']
    ];

    /**
     * Convert quantity from one unit to another
     *
     * @param float $quantity Quantity to convert
     * @param string $from_unit Source unit
     * @param string $to_unit Target unit
     * @return float Converted quantity
     * @throws Exception If units are incompatible
     */
    public static function convert($quantity, $from_unit, $to_unit) {
        // If units are the same, return original quantity
        if ($from_unit === $to_unit) {
            return $quantity;
        }

        // Validate units exist
        if (!isset(self::$unit_factors[$from_unit])) {
            throw new Exception("Unknown source unit: $from_unit");
        }
        if (!isset(self::$unit_factors[$to_unit])) {
            throw new Exception("Unknown target unit: $to_unit");
        }

        // Check if units are in the same category
        $from_category = self::getUnitCategory($from_unit);
        $to_category = self::getUnitCategory($to_unit);

        if ($from_category !== $to_category) {
            throw new Exception("Cannot convert between different unit categories: $from_category to $to_category");
        }

        // For count-based units, check if they're compatible
        if ($from_category === 'count' && !self::areCountUnitsCompatible($from_unit, $to_unit)) {
            throw new Exception("Incompatible count units: $from_unit to $to_unit");
        }

        // Convert to base unit first, then to target unit
        $base_quantity = $quantity * self::$unit_factors[$from_unit];
        $converted_quantity = $base_quantity / self::$unit_factors[$to_unit];

        return round($converted_quantity, 6); // Round to 6 decimal places for precision
    }

    /**
     * Get the category of a unit
     */
    private static function getUnitCategory($unit) {
        foreach (self::$unit_categories as $category => $units) {
            if (in_array($unit, $units)) {
                return $category;
            }
        }
        return 'unknown';
    }

    /**
     * Check if count units are compatible for conversion
     */
    private static function areCountUnitsCompatible($from_unit, $to_unit) {
        // 'each' can convert to anything, and anything can convert to 'each'
        if ($from_unit === 'each' || $to_unit === 'each') {
            return true;
        }

        // For other count units, they need to be in the same logical group
        // This is a simplified check - in a real system, you'd have more sophisticated logic
        return true; // For now, allow all count conversions
    }

    /**
     * Get all available units
     */
    public static function getAvailableUnits() {
        return array_keys(self::$unit_factors);
    }

    /**
     * Get units by category
     */
    public static function getUnitsByCategory($category = null) {
        if ($category === null) {
            return self::$unit_categories;
        }

        return self::$unit_categories[$category] ?? [];
    }

    /**
     * Validate if a unit is supported
     */
    public static function isValidUnit($unit) {
        return isset(self::$unit_factors[$unit]);
    }

    /**
     * Get the base unit for a category
     */
    public static function getBaseUnit($category) {
        $base_units = [
            'weight' => 'g',
            'volume' => 'ml',
            'count' => 'each',
            'length' => 'cm'
        ];

        return $base_units[$category] ?? null;
    }

    /**
     * Calculate the conversion factor between two units
     */
    public static function getConversionFactor($from_unit, $to_unit) {
        if (!self::isValidUnit($from_unit) || !self::isValidUnit($to_unit)) {
            throw new Exception("Invalid unit(s): $from_unit, $to_unit");
        }

        return self::$unit_factors[$from_unit] / self::$unit_factors[$to_unit];
    }

    /**
     * Add a custom unit conversion factor
     * Useful for custom units like "pack of 6", "case of 24", etc.
     */
    public static function addCustomUnit($unit_name, $factor, $category = 'count') {
        self::$unit_factors[$unit_name] = $factor;
        if (!in_array($unit_name, self::$unit_categories[$category])) {
            self::$unit_categories[$category][] = $unit_name;
        }
    }

    /**
     * Format quantity with unit for display
     */
    public static function formatQuantity($quantity, $unit, $decimals = 2) {
        // Handle null or non-numeric quantities
        if ($quantity === null || !is_numeric($quantity)) {
            $quantity = 0;
        }

        $formatted_quantity = number_format((float) $quantity, $decimals);
        return $formatted_quantity . ' ' . $unit;
    }

    /**
     * Parse quantity string (e.g., "1.5 kg", "500 ml")
     */
    public static function parseQuantityString($quantity_string) {
        $pattern = '/^(\d*\.?\d+)\s*([a-zA-Z]+)$/';
        if (preg_match($pattern, trim($quantity_string), $matches)) {
            $quantity = (float) $matches[1];
            $unit = strtolower($matches[2]);
            return ['quantity' => $quantity, 'unit' => $unit];
        }
        throw new Exception("Invalid quantity format: $quantity_string");
    }

    /**
     * Validate quantity is positive
     */
    public static function validateQuantity($quantity) {
        if (!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("Quantity must be a positive number");
        }
        return (float) $quantity;
    }
}
?>
