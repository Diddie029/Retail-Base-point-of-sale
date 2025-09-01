<?php
// Quick fix script for bulk operations layouts
$files = [
    'products/bulk_pricing.php',
    'products/bulk_status.php'
];

$css_to_add = '
        /* Main content layout */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background-color: #f8fafc;
        }
        .content {
            padding: 2rem;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }';

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Fix navigation includes
        $content = str_replace(
            ["<?php include '../layouts/navbar.php'; ?>", "<?php include '../layouts/sidebar.php'; ?>"],
            ["", ""],
            $content
        );
        
        // Fix layout structure  
        $content = str_replace(
            [
                '<div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
            </div>

            <!-- Main Content -->
            <div class="col-md-9">',
                '    <div class="container-fluid py-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
            </div>

            <!-- Main Content -->
            <div class="col-md-9">'
            ],
            [
                '    <!-- Sidebar -->
    <?php
    $current_page = \'bulk_operations\';
    include __DIR__ . \'/../include/navmenu.php\';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content">
            <div class="container-fluid py-4">',
                '    <!-- Sidebar -->
    <?php
    $current_page = \'bulk_operations\';
    include __DIR__ . \'/../include/navmenu.php\';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content">
            <div class="container-fluid py-4">'
            ],
            $content
        );
        
        // Add CSS
        if (strpos($content, 'margin-left: 250px') === false) {
            $content = str_replace('</style>', $css_to_add . '
    </style>', $content);
        }
        
        // Fix closing divs
        $content = str_replace('</body>', '            </div>
        </div>
    </div>
</body>', $content);
        
        file_put_contents($file, $content);
        echo "Fixed: $file\n";
    }
}

echo "All bulk operation files have been updated!\n";
?>
