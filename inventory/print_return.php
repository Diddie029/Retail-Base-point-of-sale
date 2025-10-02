<?php
session_start();

// Debug session configuration
error_log("Session save path: " . session_save_path());
error_log("Session cookie params: " . print_r(session_get_cookie_params(), true));
error_log("Session status: " . session_status());

// Ensure session is working properly
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log("Session not active, starting new session");
    session_start();
}
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Validate user exists in database
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        // User doesn't exist, log them out
        session_destroy();
        header("Location: ../auth/login.php?error=user_not_found");
        exit();
    }
} catch (PDOException $e) {
    error_log("User validation error: " . $e->getMessage());
    header("Location: ../auth/login.php?error=db_error");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
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

// Check if user has permission to view returns
if (!in_array('manage_products', $permissions) && !in_array('process_sales', $permissions)) {
    header("Location: inventory.php?error=permission_denied");
    exit();
}

// Get return ID from URL
$return_id = isset($_GET['id']) ? trim($_GET['id']) : null;
if (!$return_id) {
    header("Location: view_returns.php?error=invalid_return");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Function to get return data for printing
function getReturnData($conn, $return_id) {
    try {
        // Get return details
        $stmt = $conn->prepare("
            SELECT r.*,
                   s.name as supplier_name, s.contact_person, s.phone, s.email, s.address,
                   u.username as created_by_name,
                   COALESCE(au.username, 'System') as approved_by_name
            FROM returns r
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users au ON r.approved_by = au.id
            WHERE r.id = :return_id
        ");
        $stmt->execute([':return_id' => $return_id]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return) {
            return null;
        }

        // Get return items
        $stmt = $conn->prepare("
            SELECT ri.*,
                   p.name as product_name, p.sku, p.description,
                   c.name as category_name, b.name as brand_name
            FROM return_items ri
            LEFT JOIN products p ON ri.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            WHERE ri.return_id = :return_id
            ORDER BY ri.id ASC
        ");
        $stmt->bindParam(':return_id', $return_id);
        $stmt->execute();
        $return['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $return;
    } catch (PDOException $e) {
        error_log("Error getting return data: " . $e->getMessage());
        return null;
    }
}

// Get return data
$return = getReturnData($conn, $return_id);
if (!$return) {
    header("Location: view_returns.php?error=return_not_found");
    exit();
}

// Auto-trigger print dialog
$auto_print = isset($_GET['auto_print']) ? $_GET['auto_print'] : false;

// Set headers for printing
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return <?php echo htmlspecialchars($return['return_number']); ?> - Print</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }


        body {
            font-family: 'Inter', sans-serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #212529;
            background: white;
            margin: 0;
            padding: 20pt 35mm; /* increased left/right whitespace by +20mm */
        }

        .return-title-full {
            width: 100%;
            text-align: center;
            margin-bottom: 20pt;
            padding: 15pt 0;
            border-bottom: 2px solid #dee2e6;
        }

        .return-title-full h2 {
            font-size: 20pt;
            font-weight: 700;
            margin: 0;
            color: var(--primary-color);
        }

        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30pt;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 20pt;
        }

        .left-section {
            width: 50%;
            display: flex;
            flex-direction: column;
        }

        .company-info {
            padding-right: 20pt;
        }

        .return-info {
            text-align: left;
            padding: 0 20pt;
        }

        .return-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8pt;
            margin-top: 15pt;
        }

        .return-detail-item {
            font-size: 10pt;
            padding: 5pt;
            background: #f8f9fa;
            border-radius: 3pt;
            border: 1px solid #e9ecef;
        }

        .supplier-info {
            flex: 1;
            width: 50%;
            padding-left: 20pt;
            text-align: left; /* keep lines left-aligned within the block */
            margin-left: auto; /* push the block to the right side in flex */
        }

        .return-items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20pt 0;
        }

        .return-items-table th,
        .return-items-table td {
            border: 1px solid #dee2e6;
            padding: 8pt;
            text-align: left;
            font-size: 10pt;
        }

        .return-items-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .return-items-table .text-center {
            text-align: center;
        }

        .return-items-table .text-end {
            text-align: right;
        }

        .return-summary {
            display: flex;
            justify-content: space-between;
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .summary-left {
            flex: 1;
        }

        .summary-right {
            flex: 1;
            text-align: right;
        }

        .summary-item {
            margin-bottom: 5pt;
        }

        .summary-total {
            font-weight: 600;
            font-size: 12pt;
            border-top: 1px solid #dee2e6;
            padding-top: 10pt;
            margin-top: 10pt;
        }

        .notes-section {
            margin: 20pt 0;
            padding: 15pt;
            background: #f8f9fa;
            border-radius: 5pt;
        }

        .footer-info {
            margin-top: 30pt;
            padding-top: 20pt;
            border-top: 1px solid #dee2e6;
            text-align: center;
            font-size: 9pt;
            color: #6c757d;
        }

        .status-badge {
            display: inline-block;
            padding: 3pt 8pt;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            border-radius: 3pt;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Print-specific styles */
        @media print {
            body {
                margin: 0;
                padding: 15pt;
            }

            .no-print {
                display: none !important;
            }

            .page-break {
                page-break-before: always;
            }

            .page-break-after {
                page-break-after: always;
            }
        }

    </style>
</head>
<body>

    <!-- Return Title - Full Width -->
    <div class="return-title-full">
        <h2>Return Receipt #<?php echo htmlspecialchars($return['return_number']); ?></h2>
        <div class="mt-2">
            <span class="status-badge"><?php echo ucfirst($return['status']); ?> Return</span>
        </div>
    </div>

    <!-- Return Header -->
    <div class="print-header">
        <div class="left-section">
            <div class="company-info">
                <h3 style="margin: 0; color: var(--primary-color);"><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></h3>
                <?php if ($settings['company_address']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></p>
                <?php endif; ?>
                <?php if ($settings['company_phone']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><strong>Phone:</strong> <?php echo htmlspecialchars($settings['company_phone']); ?></p>
                <?php endif; ?>
                <?php if ($settings['company_email']): ?>
                <p style="margin: 5pt 0; font-size: 10pt;"><strong>Email:</strong> <?php echo htmlspecialchars($settings['company_email']); ?></p>
                <?php endif; ?>
            </div>

            <div class="return-info">
                <div class="return-details-grid">
                    <div class="return-detail-item">
                        <strong>Return Date:</strong> <?php echo date('M j, Y', strtotime($return['created_at'])); ?>
                    </div>
                    <div class="return-detail-item">
                        <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($return['created_at'])); ?>
                    </div>
                    <div class="return-detail-item">
                        <strong>Return ID:</strong> #<?php echo htmlspecialchars($return['id']); ?>
                    </div>
                    <div class="return-detail-item">
                        <strong>Created By:</strong> <?php echo htmlspecialchars($return['created_by_name'] ?? 'System'); ?>
                    </div>
                    <div class="return-detail-item">
                        <strong>Return Reason:</strong> <?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?>
                    </div>
                    <div class="return-detail-item">
                        <strong>Status:</strong> <?php echo ucfirst($return['status']); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="supplier-info">
            <h4 style="margin: 0; color: #495057;">Supplier Information</h4>
            <p style="margin: 5pt 0; font-size: 11pt; font-weight: 600;"><?php echo htmlspecialchars($return['supplier_name'] ?? 'N/A'); ?></p>
            <?php if ($return['contact_person']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Contact:</strong> <?php echo htmlspecialchars($return['contact_person']); ?></p>
            <?php endif; ?>
            <?php if ($return['phone']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Phone:</strong> <?php echo htmlspecialchars($return['phone']); ?></p>
            <?php endif; ?>
            <?php if ($return['email']): ?>
            <p style="margin: 5pt 0; font-size: 10pt;"><strong>Email:</strong> <?php echo htmlspecialchars($return['email']); ?></p>
            <?php endif; ?>
            <?php if ($return['address']): ?>
            <div style="margin-top: 10pt; padding: 10pt; background: #f8f9fa; border-radius: 3pt;">
                <strong style="font-size: 10pt;">Return Address:</strong><br>
                <span style="font-size: 10pt;"><?php echo nl2br(htmlspecialchars($return['address'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Return Items -->
    <h4 id="returnItemsHeader" style="margin: 20pt 0 10pt 0; color: var(--primary-color);">Return Items</h4>
    <table id="returnItemsTable" class="return-items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Product</th>
                <th style="width: 15%;">SKU</th>
                <th style="width: 10%;" class="text-center">Quantity</th>
                <th style="width: 15%;" class="text-end">Unit Cost</th>
                <th style="width: 20%;" class="text-end">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $counter = 1;
            foreach ($return['items'] as $item):
            ?>
            <tr>
                <td class="text-center"><?php echo $counter++; ?></td>
                <td>
                    <div style="font-weight: 500;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                    <?php if ($item['category_name']): ?>
                    <small style="color: #6c757d;"><?php echo htmlspecialchars($item['category_name']); ?></small>
                    <?php endif; ?>
                    <?php if ($item['return_reason']): ?>
                    <div style="margin-top: 3pt; font-size: 9pt; color: #dc3545;">
                        Reason: <?php echo htmlspecialchars($item['return_reason']); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?></td>
                <td class="text-center"><?php echo $item['quantity']; ?></td>
                <td class="text-end"><?php echo formatCurrency($item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
                <td class="text-end"><?php echo formatCurrency($item['quantity'] * $item['cost_price'], $settings['currency_symbol'] ?? 'KES'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Return Summary -->
    <div id="returnSummary" class="return-summary">
        <div class="summary-left">
            <div class="summary-item">
                <strong>Return Reason:</strong> <?php echo ucfirst(str_replace('_', ' ', $return['return_reason'])); ?>
            </div>
            <div class="summary-item">
                <strong>Total Items:</strong> <?php echo $return['total_items']; ?>
            </div>
            <?php if ($return['shipping_carrier']): ?>
            <div class="summary-item">
                <strong>Shipping Carrier:</strong> <?php echo htmlspecialchars($return['shipping_carrier']); ?>
            </div>
            <?php endif; ?>
            <?php if ($return['tracking_number']): ?>
            <div class="summary-item">
                <strong>Tracking Number:</strong> <?php echo htmlspecialchars($return['tracking_number']); ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="summary-right">
            <div class="summary-item">
                <strong>Subtotal:</strong> <?php echo formatCurrency($return['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
            <div class="summary-total">
                <strong>Total Return Value:</strong> <?php echo formatCurrency($return['total_amount'], $settings['currency_symbol'] ?? 'KES'); ?>
            </div>
        </div>
    </div>

    <!-- Return Notes -->
    <?php if ($return['return_notes']): ?>
    <div class="notes-section">
        <h5 style="margin: 0 0 10pt 0; color: var(--primary-color);">Return Notes</h5>
        <div style="font-size: 10pt; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($return['return_notes'])); ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div id="footerInfo" class="footer-info">
        <p>This is a computer-generated return receipt. No signature required.</p>
        <p>Generated on <?php echo date('M j, Y \a\t g:i A'); ?> by <?php echo htmlspecialchars($username); ?></p>
        <?php if ($return['approved_by_name'] && $return['approved_by_name'] !== 'System'): ?>
        <p>Approved by: <?php echo htmlspecialchars($return['approved_by_name']); ?> on <?php echo $return['approved_at'] ? date('M j, Y', strtotime($return['approved_at'])) : 'N/A'; ?></p>
        <?php endif; ?>
    </div>

</body>
</html>
