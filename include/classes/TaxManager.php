<?php

class TaxManager {
    private $conn;
    private $user_id;

    public function __construct($conn, $user_id = null) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }

    /**
     * Calculate taxes for a sale
     * @param array $items Array of sale items with product_id, quantity, unit_price
     * @param int $customer_id Customer ID for exemption checking
     * @param string $sale_date Sale date for rate validation
     * @return array Tax calculation results
     */
    public function calculateTaxes($items, $customer_id = null, $sale_date = null) {
        if (!$sale_date) {
            $sale_date = date('Y-m-d');
        }

        $taxes = [];
        $total_taxable_amount = 0;
        $total_tax_amount = 0;

        // Check if customer is tax exempt
        $customer_exempt = false;
        if ($customer_id) {
            $customer_exempt = $this->isCustomerTaxExempt($customer_id, $sale_date);
        }

        if ($customer_exempt) {
            return [
                'taxes' => [],
                'total_taxable_amount' => 0,
                'total_tax_amount' => 0,
                'customer_exempt' => true
            ];
        }

        // Group items by tax category
        $category_totals = [];
        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $unit_price = $item['unit_price'];
            $line_total = $quantity * $unit_price;

            // Check if product is tax exempt
            if ($this->isProductTaxExempt($product_id, $sale_date)) {
                continue;
            }

            // Get product tax category
            $tax_category_id = $this->getProductTaxCategory($product_id);
            if (!$tax_category_id) {
                continue;
            }

            if (!isset($category_totals[$tax_category_id])) {
                $category_totals[$tax_category_id] = 0;
            }
            $category_totals[$tax_category_id] += $line_total;
        }

        // Calculate taxes for each category
        foreach ($category_totals as $category_id => $taxable_amount) {
            $category_taxes = $this->calculateCategoryTaxes($category_id, $taxable_amount, $sale_date);
            $taxes = array_merge($taxes, $category_taxes);
            $total_taxable_amount += $taxable_amount;
        }

        // Calculate compound taxes
        $compound_taxes = array_filter($taxes, function($tax) {
            return $tax['is_compound'];
        });

        if (!empty($compound_taxes)) {
            $compound_base = $total_taxable_amount + array_sum(array_column($taxes, 'tax_amount'));
            foreach ($compound_taxes as &$tax) {
                $tax['tax_amount'] = $compound_base * $tax['tax_rate'];
                $tax['taxable_amount'] = $compound_base;
            }
        }

        $total_tax_amount = array_sum(array_column($taxes, 'tax_amount'));

        return [
            'taxes' => $taxes,
            'total_taxable_amount' => $total_taxable_amount,
            'total_tax_amount' => $total_tax_amount,
            'customer_exempt' => false
        ];
    }

    /**
     * Get active tax rates for a category on a specific date
     */
    private function calculateCategoryTaxes($category_id, $taxable_amount, $sale_date) {
        $stmt = $this->conn->prepare("
            SELECT tr.*, tc.name as category_name
            FROM tax_rates tr
            JOIN tax_categories tc ON tr.tax_category_id = tc.id
            WHERE tr.tax_category_id = ? 
            AND tr.is_active = 1
            AND tr.effective_date <= ?
            AND (tr.end_date IS NULL OR tr.end_date >= ?)
            ORDER BY tr.is_compound ASC, tr.effective_date DESC
        ");
        $stmt->execute([$category_id, $sale_date, $sale_date]);
        $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $taxes = [];
        foreach ($rates as $rate) {
            $tax_amount = $taxable_amount * $rate['rate'];
            
            $taxes[] = [
                'tax_rate_id' => $rate['id'],
                'tax_category_name' => $rate['category_name'],
                'tax_name' => $rate['name'],
                'tax_rate' => $rate['rate'],
                'taxable_amount' => $taxable_amount,
                'tax_amount' => $tax_amount,
                'is_compound' => $rate['is_compound']
            ];
        }

        return $taxes;
    }

    /**
     * Check if customer is tax exempt
     */
    public function isCustomerTaxExempt($customer_id, $sale_date = null) {
        if (!$sale_date) {
            $sale_date = date('Y-m-d');
        }

        // Check customer tax exempt flag
        $stmt = $this->conn->prepare("
            SELECT tax_exempt FROM customers 
            WHERE id = ? AND tax_exempt = 1
        ");
        $stmt->execute([$customer_id]);
        if ($stmt->fetch()) {
            return true;
        }

        // Check tax exemptions table
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM tax_exemptions 
            WHERE customer_id = ? 
            AND exemption_type = 'customer'
            AND is_active = 1
            AND effective_date <= ?
            AND (end_date IS NULL OR end_date >= ?)
        ");
        $stmt->execute([$customer_id, $sale_date, $sale_date]);
        
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Check if product is tax exempt
     */
    public function isProductTaxExempt($product_id, $sale_date = null) {
        if (!$sale_date) {
            $sale_date = date('Y-m-d');
        }

        // Check tax exemptions table for product
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM tax_exemptions 
            WHERE product_id = ? 
            AND exemption_type = 'product'
            AND is_active = 1
            AND effective_date <= ?
            AND (end_date IS NULL OR end_date >= ?)
        ");
        $stmt->execute([$product_id, $sale_date, $sale_date]);
        
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get product tax category
     */
    public function getProductTaxCategory($product_id) {
        $stmt = $this->conn->prepare("
            SELECT tax_category_id FROM products WHERE id = ?
        ");
        $stmt->execute([$product_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['tax_category_id'] : null;
    }

    /**
     * Save tax details for a sale
     */
    public function saveSaleTaxes($sale_id, $taxes) {
        // Delete existing tax records for this sale
        $stmt = $this->conn->prepare("DELETE FROM sale_taxes WHERE sale_id = ?");
        $stmt->execute([$sale_id]);

        // Insert new tax records
        if (!empty($taxes)) {
            $stmt = $this->conn->prepare("
                INSERT INTO sale_taxes (sale_id, tax_rate_id, tax_category_name, tax_name, tax_rate, taxable_amount, tax_amount, is_compound)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($taxes as $tax) {
                $stmt->execute([
                    $sale_id,
                    $tax['tax_rate_id'],
                    $tax['tax_category_name'],
                    $tax['tax_name'],
                    $tax['tax_rate'],
                    $tax['taxable_amount'],
                    $tax['tax_amount'],
                    $tax['is_compound']
                ]);
            }
        }
    }

    /**
     * Get tax categories for dropdown
     */
    public function getTaxCategories() {
        $stmt = $this->conn->query("
            SELECT id, name, description 
            FROM tax_categories 
            WHERE is_active = 1 
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get active tax rates for a category
     */
    public function getActiveTaxRates($category_id, $sale_date = null) {
        if (!$sale_date) {
            $sale_date = date('Y-m-d');
        }

        $stmt = $this->conn->prepare("
            SELECT tr.*, tc.name as category_name
            FROM tax_rates tr
            JOIN tax_categories tc ON tr.tax_category_id = tc.id
            WHERE tr.tax_category_id = ? 
            AND tr.is_active = 1
            AND tr.effective_date <= ?
            AND (tr.end_date IS NULL OR tr.end_date >= ?)
            ORDER BY tr.is_compound ASC, tr.effective_date DESC
        ");
        $stmt->execute([$category_id, $sale_date, $sale_date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create tax exemption
     */
    public function createTaxExemption($data) {
        $stmt = $this->conn->prepare("
            INSERT INTO tax_exemptions (
                customer_id, product_id, tax_category_id, exemption_type, 
                exemption_reason, certificate_number, effective_date, end_date, 
                is_active, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $data['customer_id'] ?? null,
            $data['product_id'] ?? null,
            $data['tax_category_id'] ?? null,
            $data['exemption_type'],
            $data['exemption_reason'] ?? null,
            $data['certificate_number'] ?? null,
            $data['effective_date'],
            $data['end_date'] ?? null,
            $data['is_active'] ?? 1,
            $this->user_id
        ]);
    }

    /**
     * Get tax reports
     */
    public function getTaxReport($start_date, $end_date, $category_id = null) {
        $where_conditions = ["s.sale_date BETWEEN ? AND ?"];
        $params = [$start_date, $end_date];

        if ($category_id) {
            $where_conditions[] = "st.tax_rate_id IN (SELECT id FROM tax_rates WHERE tax_category_id = ?)";
            $params[] = $category_id;
        }

        $sql = "
            SELECT 
                st.tax_category_name,
                st.tax_name,
                st.tax_rate,
                COUNT(DISTINCT st.sale_id) as sale_count,
                SUM(st.taxable_amount) as total_taxable_amount,
                SUM(st.tax_amount) as total_tax_amount
            FROM sale_taxes st
            JOIN sales s ON st.sale_id = s.id
            WHERE " . implode(' AND ', $where_conditions) . "
            GROUP BY st.tax_category_name, st.tax_name, st.tax_rate
            ORDER BY st.tax_category_name, st.tax_name
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
