<?php
/**
 * Database Setup for Return Management System
 *
 * This script creates the necessary database tables for the product return workflow.
 * Run this script once to set up the return system.
 */

// Include database connection
require_once __DIR__ . '/../include/db.php';

$message = '';
$message_type = 'info';

try {
    // Create returns table
    $sql = "
        CREATE TABLE IF NOT EXISTS returns (
            id INT PRIMARY KEY AUTO_INCREMENT,
            return_number VARCHAR(50) UNIQUE NOT NULL,
            supplier_id INT NOT NULL,
            user_id INT NOT NULL,
            return_reason ENUM('defective', 'wrong_item', 'damaged', 'expired', 'overstock', 'quality', 'other') NOT NULL,
            return_notes TEXT,
            total_items INT NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $conn->exec($sql);
    $message .= "✅ Returns table created successfully.<br>";

    // Create return_items table
    $sql = "
        CREATE TABLE IF NOT EXISTS return_items (
            id INT PRIMARY KEY AUTO_INCREMENT,
            return_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL,
            return_reason VARCHAR(255),
            notes TEXT,
            condition_status ENUM('new', 'used', 'damaged', 'defective') DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,

            INDEX idx_return_id (return_id),
            INDEX idx_product_id (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $conn->exec($sql);
    $message .= "✅ Return items table created successfully.<br>";

    // Create return_attachments table (for uploaded images/documents)
    $sql = "
        CREATE TABLE IF NOT EXISTS return_attachments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $conn->exec($sql);
    $message .= "✅ Return attachments table created successfully.<br>";

    // Create return_status_history table
    $sql = "
        CREATE TABLE IF NOT EXISTS return_status_history (
            id INT PRIMARY KEY AUTO_INCREMENT,
            return_id INT NOT NULL,
            old_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled'),
            new_status ENUM('pending', 'approved', 'shipped', 'received', 'completed', 'cancelled') NOT NULL,
            changed_by INT NOT NULL,
            change_reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,

            INDEX idx_return_id (return_id),
            INDEX idx_changed_by (changed_by),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $conn->exec($sql);
    $message .= "✅ Return status history table created successfully.<br>";

    // Insert sample return reasons into a settings/lookup table (optional)
    $sql = "
        CREATE TABLE IF NOT EXISTS return_reasons (
            id INT PRIMARY KEY AUTO_INCREMENT,
            code VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_code (code),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $conn->exec($sql);

    // Insert default return reasons
    $return_reasons = [
        ['defective', 'Defective Products', 'Products that are damaged or not working properly'],
        ['wrong_item', 'Wrong Items Received', 'Received different products than ordered'],
        ['damaged', 'Damaged in Transit', 'Products damaged during shipping'],
        ['expired', 'Expired Products', 'Products that have passed their expiration date'],
        ['overstock', 'Overstock/Excess Inventory', 'Too much inventory, need to return excess'],
        ['quality', 'Quality Issues', 'Products do not meet quality standards'],
        ['other', 'Other', 'Other reasons not listed above']
    ];

    foreach ($return_reasons as $reason) {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO return_reasons (code, name, description) VALUES (?, ?, ?)
        ");
        $stmt->execute($reason);
    }

    $message .= "✅ Return reasons setup completed.<br>";

    // Insert sample return data for testing (optional)
    try {
        // Only add sample data if the returns table is empty
        $stmt = $conn->query("SELECT COUNT(*) as count FROM returns");
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
            $message .= "ℹ️  No existing returns found. Ready for first return!<br>";
        }
    } catch (Exception $e) {
        // Table might be empty, that's okay
    }

    // Create storage directory for return attachments
    $upload_dir = __DIR__ . '/../storage/returns';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
        $message .= "✅ Return attachments directory created.<br>";
    }

    $message_type = 'success';

} catch (PDOException $e) {
    $message = "❌ Database setup failed: " . $e->getMessage();
    $message_type = 'danger';
    error_log("Return database setup error: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return System Setup - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }

        .setup-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            padding: 2rem;
            max-width: 600px;
            width: 100%;
        }

        .setup-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .setup-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 2rem;
        }

        .feature-list {
            margin: 2rem 0;
        }

        .feature-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .feature-item i {
            color: #28a745;
            margin-right: 1rem;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="setup-card">
            <div class="setup-header">
                <div class="setup-icon">
                    <i class="bi bi-arrow-return-left"></i>
                </div>
                <h2>Return System Setup</h2>
                <p class="text-muted">Setting up database tables for product returns</p>
            </div>

            <div class="alert alert-<?php echo $message_type; ?>" role="alert">
                <h5 class="alert-heading">
                    <?php echo $message_type === 'success' ? '✅ Setup Complete!' : '❌ Setup Failed'; ?>
                </h5>
                <div><?php echo $message; ?></div>
            </div>

            <div class="feature-list">
                <h5>Return System Features:</h5>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Complete Return Workflow</strong><br>
                        <small class="text-muted">Create, approve, ship, and complete returns</small>
                    </div>
                </div>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Product Selection</strong><br>
                        <small class="text-muted">Select products from received orders only</small>
                    </div>
                </div>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Return Reasons</strong><br>
                        <small class="text-muted">Predefined reasons with custom notes</small>
                    </div>
                </div>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Inventory Integration</strong><br>
                        <small class="text-muted">Automatic stock adjustments on return</small>
                    </div>
                </div>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Status Tracking</strong><br>
                        <small class="text-muted">Complete return lifecycle management</small>
                    </div>
                </div>

                <div class="feature-item">
                    <i class="bi bi-check-circle"></i>
                    <div>
                        <strong>Document Management</strong><br>
                        <small class="text-muted">Attach images and documents to returns</small>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <?php if ($message_type === 'success'): ?>
                <a href="inventory.php" class="btn btn-primary me-2">
                    <i class="bi bi-house me-2"></i>Go to Dashboard
                </a>
                <a href="create_return.php" class="btn btn-success">
                    <i class="bi bi-plus-circle me-2"></i>Create First Return
                </a>
                <?php else: ?>
                <button onclick="location.reload()" class="btn btn-warning">
                    <i class="bi bi-arrow-clockwise me-2"></i>Retry Setup
                </button>
                <?php endif; ?>
            </div>

            <div class="mt-4 p-3 bg-light rounded">
                <h6 class="mb-2"><i class="bi bi-info-circle me-2"></i>Database Tables Created:</h6>
                <ul class="mb-0 small">
                    <li><code>returns</code> - Main return records</li>
                    <li><code>return_items</code> - Products in each return</li>
                    <li><code>return_attachments</code> - File attachments</li>
                    <li><code>return_status_history</code> - Status change tracking</li>
                    <li><code>return_reasons</code> - Predefined return reasons</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
