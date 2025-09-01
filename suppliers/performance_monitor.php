<?php
// Performance monitoring script - run this via cron job to generate automated alerts
require_once __DIR__ . '/../include/db.php';

// Generate sample performance alerts based on supplier data
function generatePerformanceAlerts($conn) {
    try {
        // Get all active suppliers
        $suppliers = $conn->query("SELECT id, name FROM suppliers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($suppliers as $supplier) {
            // Example: Check if supplier has products but no recent performance metrics
            $has_products = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE supplier_id = ?");
            $has_products->execute([$supplier['id']]);
            $product_count = $has_products->fetch()['count'];
            
            if ($product_count > 0) {
                // Generate random performance issue for demonstration
                $alert_types = [
                    'performance_drop' => 'Performance score has dropped below acceptable threshold',
                    'delivery_delay' => 'Delivery delays reported by multiple customers',
                    'quality_issue' => 'Quality concerns raised in recent orders',
                    'document_expiry' => 'Important documents are expiring soon'
                ];
                
                // 10% chance of generating an alert for any supplier
                if (rand(1, 10) === 1) {
                    $alert_type = array_rand($alert_types);
                    $alert_message = $alert_types[$alert_type];
                    
                    // Check if alert already exists
                    $existing_alert = $conn->prepare("
                        SELECT id FROM supplier_performance_alerts 
                        WHERE supplier_id = ? AND alert_type = ? AND is_resolved = 0
                    ");
                    $existing_alert->execute([$supplier['id'], $alert_type]);
                    
                    if (!$existing_alert->fetch()) {
                        // Create new alert
                        $stmt = $conn->prepare("
                            INSERT INTO supplier_performance_alerts 
                            (supplier_id, alert_type, alert_level, alert_message) 
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        $alert_level = rand(1, 3) === 3 ? 'critical' : (rand(1, 2) === 2 ? 'warning' : 'info');
                        $stmt->execute([$supplier['id'], $alert_type, $alert_level, $alert_message]);
                        
                        echo "Generated {$alert_level} alert for {$supplier['name']}: {$alert_message}\n";
                    }
                }
            }
        }
        
        // Check for expired documents
        $expired_docs = $conn->query("
            SELECT sd.*, s.name as supplier_name 
            FROM supplier_documents sd
            JOIN suppliers s ON sd.supplier_id = s.id 
            WHERE sd.expiry_date < CURDATE() AND sd.status != 'expired'
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($expired_docs as $doc) {
            // Update document status
            $conn->prepare("UPDATE supplier_documents SET status = 'expired' WHERE id = ?")->execute([$doc['id']]);
            
            // Check if alert already exists
            $existing_alert = $conn->prepare("
                SELECT id FROM supplier_performance_alerts 
                WHERE supplier_id = ? AND alert_type = 'document_expiry' AND is_resolved = 0
            ");
            $existing_alert->execute([$doc['supplier_id']]);
            
            if (!$existing_alert->fetch()) {
                // Create document expiry alert
                $stmt = $conn->prepare("
                    INSERT INTO supplier_performance_alerts 
                    (supplier_id, alert_type, alert_level, alert_message) 
                    VALUES (?, 'document_expiry', 'warning', ?)
                ");
                $message = "Document '{$doc['document_name']}' has expired and needs renewal";
                $stmt->execute([$doc['supplier_id'], $message]);
                
                echo "Generated document expiry alert for {$doc['supplier_name']}: {$message}\n";
            }
        }
        
        echo "Performance monitoring completed successfully.\n";
        
    } catch (Exception $e) {
        echo "Error running performance monitoring: " . $e->getMessage() . "\n";
    }
}

// Run if called directly (not included)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "Running supplier performance monitoring...\n";
    generatePerformanceAlerts($conn);
}
?>
