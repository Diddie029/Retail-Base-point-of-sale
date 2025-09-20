<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get attachment ID from URL
$attachment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$attachment_id) {
    header("Location: inventory.php?error=invalid_attachment");
    exit();
}

try {
    // Get attachment details
    $stmt = $conn->prepare("
        SELECT iia.*, io.order_number, s.name as supplier_name
        FROM inventory_invoice_attachments iia
        LEFT JOIN inventory_orders io ON iia.order_id = io.id
        LEFT JOIN suppliers s ON io.supplier_id = s.id
        WHERE iia.id = ?
    ");
    $stmt->execute([$attachment_id]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        header("Location: inventory.php?error=attachment_not_found");
        exit();
    }

    // Check if file exists
    $file_path = __DIR__ . '/../' . $attachment['file_path'];
    if (!file_exists($file_path)) {
        header("Location: inventory.php?error=file_not_found");
        exit();
    }

    // Set headers for download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $attachment['original_name'] . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    // Clear output buffer
    ob_clean();
    flush();

    // Output file
    readfile($file_path);
    exit();

} catch (PDOException $e) {
    error_log("Error downloading attachment: " . $e->getMessage());
    header("Location: inventory.php?error=download_failed");
    exit();
}
?>
