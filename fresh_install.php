<?php
// Fresh Install Script - Completely resets and reinstalls the POS system
session_start();

// Clear all session data
session_unset();
session_destroy();
session_start();

// Define storage directory
$storage_dir = __DIR__ . '/storage';
$marker_file = $storage_dir . '/installed';

// Remove installation marker if it exists
if (file_exists($marker_file)) {
    unlink($marker_file);
}

// Clear any cached data
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// Redirect to the main installer
header("Location: starter.php?fresh=1");
exit();
?>