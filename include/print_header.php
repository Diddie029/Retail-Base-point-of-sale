<?php
/**
 * Standardized header component for print/preview pages
 * Usage: include this file and call printHeader($title, $breadcrumbs, $back_url, $icon_class)
 */

function printHeader($title, $breadcrumbs = [], $back_url = '', $icon_class = 'bi bi-file-text', $additional_actions = '') {
    global $settings;
    
    // Default breadcrumbs if none provided
    if (empty($breadcrumbs)) {
        $breadcrumbs = [
            ['url' => '../../../dashboard/dashboard.php', 'text' => 'Dashboard'],
            ['url' => '../../index.php', 'text' => 'Finance'],
            ['url' => '../payables.php', 'text' => 'Payables']
        ];
    }
    ?>
    <header class="header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <div class="header-content">
            <div class="header-title">
                <div class="d-flex align-items-center mb-2">
                    <?php if (!empty($back_url)): ?>
                    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-light btn-sm me-3">
                        <i class="bi bi-arrow-left me-1"></i>Back
                    </a>
                    <?php endif; ?>
                    <h1 class="mb-0"><i class="<?php echo htmlspecialchars($icon_class); ?> me-2"></i><?php echo htmlspecialchars($title); ?></h1>
                    <?php if (!empty($additional_actions)): ?>
                        <div class="ms-auto">
                            <?php echo $additional_actions; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb" style="background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 0.375rem;">
                        <?php foreach ($breadcrumbs as $index => $crumb): ?>
                            <?php if ($index === count($breadcrumbs) - 1): ?>
                                <li class="breadcrumb-item active" style="color: white;"><?php echo htmlspecialchars($crumb['text']); ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item">
                                    <a href="<?php echo htmlspecialchars($crumb['url']); ?>" style="color: rgba(255,255,255,0.8);">
                                        <?php echo htmlspecialchars($crumb['text']); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
        </div>
    </header>
    <?php
}

/**
 * Standardized print container styles
 */
function printStyles() {
    ?>
    <style>
        :root {
            --primary-color: <?php echo $GLOBALS['settings']['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $GLOBALS['settings']['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .print-container {
            display: none;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            
            .print-container, .print-container * {
                visibility: visible;
            }
            
            .print-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                display: block !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .print-header {
                border-bottom: 2px solid #000;
                margin-bottom: 20px;
                padding-bottom: 15px;
            }
            
            .print-section {
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            
            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
            }
            
            .print-table th,
            .print-table td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
            }
            
            .print-table th {
                background-color: #f8f9fa;
                font-weight: bold;
            }
            
            .print-total {
                font-size: 1.2em;
                font-weight: bold;
                border-top: 2px solid #000;
                padding-top: 10px;
            }
            
            .print-company-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #000;
                padding-bottom: 20px;
            }
            
            .print-company-header h1 {
                font-size: 2em;
                margin: 0;
                color: #000;
            }
            
            .print-company-header p {
                margin: 5px 0;
                color: #666;
            }
        }
    </style>
    <?php
}

/**
 * Standardized print container header with company info
 */
function printContainerHeader($document_type, $document_number, $document_date = null) {
    global $settings;
    ?>
    <div class="print-container">
        <div class="print-company-header">
            <h1><?php echo htmlspecialchars($settings['company_name'] ?? 'Company Name'); ?></h1>
            <p><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></p>
            <p>Phone: <?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($settings['company_email'] ?? ''); ?></p>
        </div>
        
        <div class="print-header">
            <h2><?php echo htmlspecialchars($document_type); ?> - <?php echo htmlspecialchars($document_number); ?></h2>
            <?php if ($document_date): ?>
                <p><strong>Date:</strong> <?php echo date('F d, Y', strtotime($document_date)); ?></p>
            <?php endif; ?>
        </div>
    <?php
}
?>
