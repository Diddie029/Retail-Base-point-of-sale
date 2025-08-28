<?php
/**
 * Backup System Configuration
 * This file allows customization for different environments
 */

return [
    // Custom mysqldump path (leave empty for auto-detection)
    'mysqldump_path' => '',
    
    // Environment-specific settings
    'environment' => 'auto', // auto, local, hosting, custom
    
    // Custom paths for specific hosting providers
    'custom_paths' => [
        // Add your hosting provider's mysqldump path here
        // Example: '/usr/local/mysql/bin/mysqldump'
    ],
    
    // Backup preferences
    'prefer_mysqldump' => true, // Set to false to always use PHP backup
    'backup_timeout' => 300, // Maximum backup time in seconds
    
    // Database connection settings (override defaults if needed)
    'db_settings' => [
        // Leave empty to use settings from db.php
        'host' => '',
        'username' => '',
        'password' => '',
        'database' => ''
    ],
    
    // Hosting provider presets
    'hosting_presets' => [
        'cpanel' => [
            'mysqldump_paths' => [
                '/usr/bin/mysqldump',
                '/usr/local/bin/mysqldump',
                '/usr/local/mysql/bin/mysqldump'
            ]
        ],
        'plesk' => [
            'mysqldump_paths' => [
                '/usr/bin/mysqldump',
                '/usr/local/psa/bin/mysqldump'
            ]
        ],
        'shared_hosting' => [
            'mysqldump_paths' => [
                'mysqldump', // Most shared hosts have it in PATH
                '/usr/bin/mysqldump'
            ]
        ]
    ]
];
?>
