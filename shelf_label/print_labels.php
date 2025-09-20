<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
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

// Check if user has permission to manage products
if (!hasPermission('manage_inventory', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=access_denied");
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Default settings if not set
$defaults = [
    'currency_symbol' => 'KES',
    'currency_position' => 'before',
    'currency_decimal_places' => '2'
];

foreach($defaults as $key => $value) {
    if(!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Helper function for currency formatting using admin settings
function formatCurrencyWithSettings($amount, $settings) {
    $symbol = $settings['currency_symbol'] ?? 'KES';
    $position = $settings['currency_position'] ?? 'before';
    $decimals = (int)($settings['currency_decimal_places'] ?? 2);

    $formatted_amount = number_format((float)$amount, $decimals);

    if ($position === 'after') {
        return $formatted_amount . ' ' . $symbol;
    } else {
        return $symbol . ' ' . $formatted_amount;
    }
}

// Get label settings from session or URL
$label_settings = $_SESSION['label_settings'] ?? [];
$is_preview = isset($_GET['preview']);

// Get products and their custom quantities
$product_ids = $label_settings['products'] ?? $_GET['products'] ?? '';
$quantities_param = $_GET['quantities'] ?? '';
$custom_quantities = $label_settings['quantities'] ?? [];

if (!empty($quantities_param)) {
    $custom_quantities = json_decode(urldecode($quantities_param), true) ?? [];
}

if (empty($label_settings) && !$is_preview) {
    header("Location: shelf_labels.php?error=no_label_settings");
    exit();
}

if (empty($product_ids)) {
    header("Location: shelf_labels.php?error=no_products_selected");
    exit();
}

$product_ids_array = explode(',', $product_ids);
$placeholders = str_repeat('?,', count($product_ids_array) - 1) . '?';

$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name, b.name as brand_name,
           CASE
               WHEN p.sale_price IS NOT NULL
                    AND (p.sale_start_date IS NULL OR p.sale_start_date <= NOW())
                    AND (p.sale_end_date IS NULL OR p.sale_end_date >= NOW())
               THEN 1 ELSE 0 END as is_on_sale
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE p.id IN ($placeholders)
    ORDER BY p.name ASC
");
$stmt->execute($product_ids_array);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    header("Location: shelf_labels.php?error=products_not_found");
    exit();
}

// Handle print actions
if ($_POST && isset($_POST['print_labels'])) {
    $print_type = $_POST['print_type'] ?? 'browser';
    
    if ($print_type === 'pdf') {
        generatePDFLabels($products, $label_settings, $settings);
        exit();
    } else {
        // Browser print
        echo "<script>window.print();</script>";
    }
}

// Function to generate PDF labels
function generatePDFLabels($products, $label_settings, $settings) {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Create new PDF instance
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Calculate total labels for selected products only
    $total_labels = 0;
    $selected_products_count = 0;
    foreach ($products as $product) {
        $product_id = $product['id'];
        if (isset($custom_quantities[$product_id])) {
            $quantity = max(1, (int)$custom_quantities[$product_id]);
            $total_labels += $quantity;
            $selected_products_count++;
        }
    }

    // Set document information
    $pdf->SetCreator($settings['company_name'] ?? 'POS System');
    $pdf->SetAuthor($settings['company_name'] ?? 'POS System');
    $pdf->SetTitle('Shelf Labels - ' . $total_labels . ' Labels (' . $selected_products_count . ' Products)');
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetHeaderMargin(0);
    $pdf->SetFooterMargin(0);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Calculate label dimensions based on size - minimum height
    $label_sizes = [
        'small' => ['width' => 40, 'height' => 10],
        'standard' => ['width' => 60, 'height' => 15],
        'large' => ['width' => 80, 'height' => 20]
    ];
    
    $size = $label_settings['size'] ?? 'small'; // Default to minimum size
    $label_width = $label_sizes[$size]['width'];
    $label_height = $label_sizes[$size]['height'];
    
    // Calculate labels per row and column
    $page_width = 190; // A4 width minus margins
    $page_height = 277; // A4 height minus margins
    
    $labels_per_row = floor($page_width / ($label_width + 5));
    $labels_per_col = floor($page_height / ($label_height + 5));
    
    $current_row = 0;
    $current_col = 0;
    
    foreach ($products as $product) {
        $product_id = $product['id'];
        if (isset($custom_quantities[$product_id])) {
            $print_quantity = max(1, (int)$custom_quantities[$product_id]);

            // Print multiple labels for each product based on custom quantity
            for ($label_index = 0; $label_index < $print_quantity; $label_index++) {
            // Check if we need a new page
            if ($current_row >= $labels_per_col) {
                $pdf->AddPage();
                $current_row = 0;
                $current_col = 0;
            }

            // Calculate position
            $x = 10 + ($current_col * ($label_width + 5));
            $y = 10 + ($current_row * ($label_height + 5));

            // Draw label border
            $pdf->Rect($x, $y, $label_width, $label_height, 'D');

            // Add company name
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetXY($x + 2, $y + 2);
            $pdf->Cell($label_width - 4, 6, $settings['company_name'] ?? 'Company Name', 0, 0, 'C');

                        // Add product name
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetXY($x + 2, $y + 6);
            $product_name = strlen($product['name']) > 20 ? substr($product['name'], 0, 20) . '...' : $product['name'];
            $pdf->Cell($label_width - 4, 5, $product_name, 0, 0, 'C');

                        // Add SKU
            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->SetXY($x + 2, $y + 11);
            $pdf->Cell($label_width - 4, 4, 'SKU: ' . ($product['sku'] ?? 'N/A'), 0, 0, 'C');

            // Add category
            $pdf->SetFont('helvetica', 'B', 6);
            $pdf->SetXY($x + 2, $y + 15);
            $pdf->Cell($label_width - 4, 4, 'Cat: ' . ($product['category_name'] ?? 'Uncategorized'), 0, 0, 'C');

            // Add barcode if SKU exists
            if (!empty($product['sku'])) {
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetXY($x + 2, $y + 19);
                $pdf->Cell($label_width - 4, 4, '*' . $product['sku'] . '*', 0, 0, 'C');
            }

            // Add price
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetXY($x + 2, $y + 23);

            if ($product['is_on_sale'] && !empty($product['sale_price'])) {
                // Show original price crossed out
                $original_price = formatCurrencyWithSettings($product['price'] ?? 0, $settings);
                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetTextColor(150, 150, 150); // Gray color
                $pdf->Cell($label_width - 4, 4, $original_price, 0, 0, 'C');

                // Show sale price
                $pdf->SetXY($x + 2, $y + 27);
                $pdf->SetFont('helvetica', 'B', 12);
                $pdf->SetTextColor(220, 38, 38); // Red color
                $sale_price = formatCurrencyWithSettings($product['sale_price'], $settings);
                $pdf->Cell($label_width - 4, 6, $sale_price, 0, 0, 'C');
            } else {
                // Regular price
                $pdf->SetTextColor(0, 0, 0); // Black color
                $price_text = formatCurrencyWithSettings($product['price'] ?? 0, $settings);
                $pdf->Cell($label_width - 4, 6, $price_text, 0, 0, 'C');
            }

            // Add custom text if provided
            if (!empty($label_settings['custom_text'])) {
                $pdf->SetFont('helvetica', '', 5);
                $pdf->SetXY($x + 2, $y + 31);
                $custom_text = strlen($label_settings['custom_text']) > 25 ? substr($label_settings['custom_text'], 0, 25) . '...' : $label_settings['custom_text'];
                $pdf->Cell($label_width - 4, 3, $custom_text, 0, 0, 'C');
            }

            // Add generation date
            $pdf->SetFont('helvetica', '', 5);
            $pdf->SetXY($x + 2, $y + $label_height - 6);
            $pdf->Cell($label_width - 4, 3, 'Generated: ' . date('M d, Y'), 0, 0, 'C');

            // Update position for next label
            $current_col++;
            if ($current_col >= $labels_per_row) {
                $current_col = 0;
                $current_row++;
            }
            }
        }
    }
    
    // Output PDF
    $pdf->Output('shelf_labels_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Shelf Labels - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/shelf_label.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }

        .print-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        .labels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .print-label {
            border: 1px solid #000;
            border-radius: 4px;
            padding: 0.25rem;
            background: white;
            min-height: 45px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            margin-bottom: 0.25rem;
        }

        .print-label.small { 
            min-height: 40px; 
            width: 120px;
        }
        .print-label.standard { 
            min-height: 55px; 
            width: 140px;
        }
        .print-label.large { 
            min-height: 70px; 
            width: 160px;
        }

        .label-header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 0.1rem;
            margin-bottom: 0.15rem;
        }

        .label-header h6 {
            margin: 0;
            font-size: 0.45rem;
            font-weight: 600;
        }

        .label-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }

        .label-product-name {
            font-weight: 800;
            font-size: 0.55rem;
            line-height: 1.1;
            text-align: center;
            margin-bottom: 0.1rem;
        }

        .label-details {
            font-size: 0.45rem;
            text-align: center;
            line-height: 1.1;
            font-weight: 700;
        }

        .label-price {
            font-weight: 900;
            font-size: 0.7rem;
            color: var(--primary-color);
            text-align: center;
            margin: 0.15rem 0;
        }

        .label-footer {
            text-align: center;
            font-size: 0.4rem;
            border-top: 1px solid #000;
            padding-top: 0.1rem;
            margin-top: 0.15rem;
        }

        .barcode-section {
            text-align: center;
            margin: 0.15rem 0;
            padding: 0.15rem 0;
        }

        .barcode-image {
            max-width: 100%;
            height: 30px;
            width: 160px;
            margin-bottom: 0.1rem;
        }

        .barcode-text {
            font-size: 0.45rem;
            font-weight: bold;
            letter-spacing: 0.4px;
        }

        .price-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.1rem;
        }

        .original-price {
            text-decoration: line-through;
            color: #94a3b8;
            font-size: 0.5rem;
            font-weight: normal;
        }

        .sale-price {
            color: #dc2626;
            font-weight: 900;
            font-size: 0.65rem;
        }

        .print-options {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .print-options h5 {
            color: #1e293b;
            margin-bottom: 1rem;
        }

        .print-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .print-buttons .btn {
            min-width: 150px;
        }

        @media print {
            /* Hide everything except labels */
            .no-print,
            .sidebar,
            .header,
            .main-content > header,
            .print-options,
            .print-section h5,
            .print-section p,
            .print-section .row,
            .print-section .col-md-6,
            .print-section ul,
            .print-section h6,
            nav,
            .navbar,
            .btn,
            .alert,
            .breadcrumb,
            .card-header,
            .card-footer {
                display: none !important;
            }
            
            /* Show only print-only content */
            .print-only {
                display: block !important;
            }
            
            /* Show only the labels grid - optimized for compact but readable printing */
            .labels-grid.print-only {
                display: grid !important;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)) !important;
                gap: 0.25rem !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .print-label {
                border: 1px solid #000 !important;
                margin: 1px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                padding: 0.15rem !important;
            }
            
            /* Reset body and main content for print */
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
            }
            
            .content {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .print-section {
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                background: white !important;
            }
            
            /* Ensure labels are properly sized for print - balanced compact size */
            .print-label.small { 
                min-height: 35px !important;
                width: 120px !important;
                font-size: 0.4rem !important;
            }
            .print-label.standard { 
                min-height: 45px !important;
                width: 140px !important;
                font-size: 0.45rem !important;
            }
            .print-label.large { 
                min-height: 55px !important;
                width: 160px !important;
                font-size: 0.5rem !important;
            }
            
            /* Ensure barcodes are visible and scannable */
            .barcode-image {
                width: 150px !important;
                height: 30px !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'inventory';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Print Shelf Labels</h1>
                    <p class="header-subtitle">Print and export shelf labels for selected products</p>
                </div>
                <div class="header-actions">
                    <a href="shelf_labels.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Shelf Labels
                    </a>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Print Options -->
            <div class="print-options no-print">
                <h5><i class="bi bi-printer me-2"></i>Print Options</h5>
                <form method="POST" class="print-buttons">
                    <button type="submit" name="print_labels" value="browser" class="btn btn-primary">
                        <i class="bi bi-printer me-2"></i>Print in Browser
                    </button>
                    <button type="submit" name="print_labels" value="pdf" class="btn btn-success">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Download PDF
                    </button>
                    <a href="shelf_labels.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Selection
                    </a>
                </form>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Browser Print:</strong> Opens the print dialog in your browser<br>
                        <strong>PDF Download:</strong> Downloads a PDF file with all labels<br>
                        <strong>Barcode:</strong> Scan barcode to get SKU information
                    </small>
                </div>
            </div>

            <!-- Labels Display -->
            <?php
            // Calculate total labels to be printed using custom quantities
            $total_labels = 0;
            $selected_products_count = 0;
            foreach ($products as $product) {
                $product_id = $product['id'];
                if (isset($custom_quantities[$product_id])) {
                    $quantity = max(1, (int)$custom_quantities[$product_id]);
                    $total_labels += $quantity;
                    $selected_products_count++;
                }
            }
            ?>
            <div class="print-section">
                <h5 class="no-print"><i class="bi bi-tags me-2"></i>Generated Labels (<?php echo $total_labels; ?> total labels for <?php echo $selected_products_count; ?> selected products)</h5>
                <p class="text-muted no-print">Printing only the selected products with your specified quantities. Labels include product information, pricing, and company details.</p>
                
                <!-- Debug info for selected products and quantities -->
                <div class="no-print">
                    <h6><i class="bi bi-list-check me-2"></i>Selected Products & Quantities:</h6>
                    <div class="row">
                        <?php foreach ($products as $product): 
                            $product_id = $product['id'];
                            if (isset($custom_quantities[$product_id])) {
                                $print_quantity = max(1, (int)$custom_quantities[$product_id]);
                        ?>
                        <div class="col-md-4 mb-2">
                            <div class="card card-body py-2">
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <small class="text-muted">SKU: <?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></small>
                                <span class="badge bg-primary"><?php echo $print_quantity; ?> labels</span>
                            </div>
                        </div>
                        <?php 
                            }
                        endforeach; ?>
                    </div>
                </div>

                <div class="labels-grid print-only">
                    <?php foreach ($products as $product):
                        $product_id = $product['id'];
                        if (isset($custom_quantities[$product_id])) {
                            $print_quantity = max(1, (int)$custom_quantities[$product_id]);

                            for ($i = 0; $i < $print_quantity; $i++): ?>
                    <div class="print-label <?php echo $label_settings['size'] ?? 'small'; ?>">
                        <div class="label-header">
                            <h6><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></h6>
                        </div>

                        <div class="label-content">
                            <div class="label-product-name">
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                            </div>

                            <div class="label-details">
                                <div><strong>SKU:</strong> <strong><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></strong></div>
                                <div><strong>Category:</strong> <strong><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></strong></div>
                            </div>

                            <!-- Barcode Section -->
                            <?php if (!empty($product['sku'])): ?>
                            <div class="barcode-section">
                                <img src="https://barcode.tec-it.com/barcode.ashx?data=<?php echo urlencode($product['sku']); ?>&code=Code128&dpi=150&dataseparator=" alt="Barcode" class="barcode-image">
                                <div class="barcode-text"><small><?php echo htmlspecialchars($product['sku']); ?></small></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="label-price">
                            <?php if ($product['is_on_sale'] && !empty($product['sale_price'])): ?>
                                <div class="price-container">
                                    <span class="original-price"><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></span>
                                    <span class="sale-price"><?php echo formatCurrencyWithSettings($product['sale_price'], $settings); ?></span>
                                </div>
                            <?php else: ?>
                                <strong><?php echo formatCurrencyWithSettings($product['price'] ?? 0, $settings); ?></strong>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($label_settings['custom_text'])): ?>
                        <div class="label-details text-center">
                            <em><?php echo htmlspecialchars($label_settings['custom_text']); ?></em>
                        </div>
                        <?php endif; ?>

                        <div class="label-footer">
                            Generated on <?php echo date('M d, Y'); ?>
                        </div>
                    </div>
                    <?php 
                            endfor;
                        }
                    endforeach; ?>
                </div>
            </div>

            <!-- Print Instructions -->
            <div class="print-section no-print">
                <h5><i class="bi bi-info-circle me-2"></i>Printing Instructions</h5>
                <div class="row">
                    <div class="col-md-6">
                        <h6>For Best Results:</h6>
                        <ul>
                            <li>Use A4 paper size</li>
                            <li>Set margins to minimum (0.5" or less)</li>
                            <li>Disable headers and footers</li>
                            <li>Use landscape orientation for better label layout</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Label Sizes (Optimized Spacing):</h6>
                        <ul>
                            <li><strong>Small:</strong> 1.4" x 0.9" - Compact with good readability</li>
                            <li><strong>Standard:</strong> 1.7" x 1.1" - Balanced size and spacing</li>
                            <li><strong>Large:</strong> 2.1" x 1.4" - Comfortable reading with details</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Auto-print when page loads (if coming from generation)
        <?php if ($is_preview): ?>
        window.onload = function() {
            // Show a message that labels are ready
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle me-2"></i>
                <strong>Labels Generated Successfully!</strong> Your shelf labels are ready for printing.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.content').insertBefore(alertDiv, document.querySelector('.print-options'));
        };
        <?php endif; ?>
    </script>
</body>
</html>
