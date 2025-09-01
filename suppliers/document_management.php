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
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    try {
        $stmt = $conn->prepare("
            SELECT p.name
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
        ");
        $stmt->bindParam(':role_id', $role_id);
        $stmt->execute();
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $permissions = ['manage_products', 'process_sales', 'manage_sales'];
    }
}

// Check if user has permission to manage suppliers
if (!hasPermission('manage_products', $permissions)) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    $settings = [
        'company_name' => 'POS System',
        'theme_color' => '#6366f1',
        'sidebar_color' => '#1e293b'
    ];
}

// Create upload directory if it doesn't exist
$upload_dir = __DIR__ . '/../uploads/supplier_documents/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $document_type = sanitizeProductInput($_POST['document_type']);
    $document_name = trim($_POST['document_name']);
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    // Handle file upload
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Validate file type
        $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
        if (!in_array($file_extension, $allowed_extensions)) {
            $_SESSION['error'] = 'Invalid file type. Allowed types: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT';
        } else {
            // Generate unique filename
            $filename = 'supplier_' . $supplier_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO supplier_documents 
                        (supplier_id, document_type, document_name, file_path, expiry_date, uploaded_by) 
                        VALUES (:supplier_id, :document_type, :document_name, :file_path, :expiry_date, :uploaded_by)
                    ");
                    $stmt->execute([
                        ':supplier_id' => $supplier_id,
                        ':document_type' => $document_type,
                        ':document_name' => $document_name,
                        ':file_path' => 'uploads/supplier_documents/' . $filename,
                        ':expiry_date' => $expiry_date,
                        ':uploaded_by' => $user_id
                    ]);
                    
                    $_SESSION['success'] = 'Document uploaded successfully.';
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Failed to save document: ' . $e->getMessage();
                    // Clean up uploaded file
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            } else {
                $_SESSION['error'] = 'Failed to upload file.';
            }
        }
    } else {
        $_SESSION['error'] = 'Please select a file to upload.';
    }
    
    header("Location: document_management.php" . (isset($supplier_id) ? "?supplier_id=$supplier_id" : ""));
    exit();
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $document_id = intval($_POST['document_id']);
    
    try {
        // Get file path before deletion
        $stmt = $conn->prepare("SELECT file_path FROM supplier_documents WHERE id = :id");
        $stmt->execute([':id' => $document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($document) {
            // Delete from database
            $stmt = $conn->prepare("DELETE FROM supplier_documents WHERE id = :id");
            $stmt->execute([':id' => $document_id]);
            
            // Delete physical file
            $full_path = __DIR__ . '/../' . $document['file_path'];
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            
            $_SESSION['success'] = 'Document deleted successfully.';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to delete document: ' . $e->getMessage();
    }
    
    header("Location: document_management.php");
    exit();
}

// Handle document status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $document_id = intval($_POST['document_id']);
    $new_status = sanitizeProductInput($_POST['status']);
    
    try {
        $stmt = $conn->prepare("UPDATE supplier_documents SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $new_status, ':id' => $document_id]);
        
        $_SESSION['success'] = 'Document status updated successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to update document status: ' . $e->getMessage();
    }
    
    header("Location: document_management.php");
    exit();
}

// Get filter parameters
$supplier_filter = $_GET['supplier_id'] ?? '';
$document_type_filter = $_GET['document_type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$expiry_filter = $_GET['expiry'] ?? '';

// Build query conditions
$where_conditions = ['1=1'];
$params = [];

if (!empty($supplier_filter)) {
    $where_conditions[] = 'sd.supplier_id = :supplier_id';
    $params[':supplier_id'] = intval($supplier_filter);
}

if (!empty($document_type_filter)) {
    $where_conditions[] = 'sd.document_type = :document_type';
    $params[':document_type'] = $document_type_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = 'sd.status = :status';
    $params[':status'] = $status_filter;
}

if ($expiry_filter === 'expired') {
    $where_conditions[] = 'sd.expiry_date < CURDATE()';
} elseif ($expiry_filter === 'expiring_soon') {
    $where_conditions[] = 'sd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
}

// Get documents with supplier information
$documents_query = "
    SELECT sd.*, s.name as supplier_name, u.username as uploaded_by_user,
           CASE 
               WHEN sd.expiry_date < CURDATE() THEN 'expired'
               WHEN sd.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
               ELSE 'valid'
           END as expiry_status
    FROM supplier_documents sd
    JOIN suppliers s ON sd.supplier_id = s.id
    JOIN users u ON sd.uploaded_by = u.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY sd.uploaded_at DESC
";

$stmt = $conn->prepare($documents_query);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for dropdown
$suppliers = $conn->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_documents,
        COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired_documents,
        COUNT(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon,
        COUNT(CASE WHEN status = 'valid' THEN 1 END) as valid_documents
    FROM supplier_documents
";
$stats = $conn->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Handle success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Document Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/suppliers.css">
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'suppliers';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Supplier Document Management</h1>
                    <div class="header-subtitle">Manage supplier documents and track expiry dates</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="bi bi-plus"></i>
                        Upload Document
                    </button>
                    <div class="user-info">
                        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Statistics Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--primary-color);">
                                <i class="bi bi-file-earmark"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Total Documents</div>
                        <div class="stat-card-value"><?php echo $stats['total_documents']; ?></div>
                        <div class="stat-card-trend trend-up">
                            <i class="bi bi-arrow-up"></i>
                            <span>All documents</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--success-color);">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Valid Documents</div>
                        <div class="stat-card-value"><?php echo $stats['valid_documents']; ?></div>
                        <div class="stat-card-trend trend-up">
                            <i class="bi bi-arrow-up"></i>
                            <span>Active and valid</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--warning-color);">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Expiring Soon</div>
                        <div class="stat-card-value"><?php echo $stats['expiring_soon']; ?></div>
                        <div class="stat-card-trend trend-neutral">
                            <i class="bi bi-dash"></i>
                            <span>Within 30 days</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--danger-color);">
                                <i class="bi bi-x-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Expired</div>
                        <div class="stat-card-value"><?php echo $stats['expired_documents']; ?></div>
                        <div class="stat-card-trend trend-down">
                            <i class="bi bi-arrow-down"></i>
                            <span>Need renewal</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-funnel me-2"></i>
                        Filter Documents
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="supplier_id" class="form-label">Supplier</label>
                            <select class="form-control" name="supplier_id" id="supplier_id">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="document_type" class="form-label">Document Type</label>
                            <select class="form-control" name="document_type" id="document_type">
                                <option value="">All Types</option>
                                <option value="contract" <?php echo $document_type_filter === 'contract' ? 'selected' : ''; ?>>Contract</option>
                                <option value="tax_certificate" <?php echo $document_type_filter === 'tax_certificate' ? 'selected' : ''; ?>>Tax Certificate</option>
                                <option value="business_license" <?php echo $document_type_filter === 'business_license' ? 'selected' : ''; ?>>Business License</option>
                                <option value="insurance" <?php echo $document_type_filter === 'insurance' ? 'selected' : ''; ?>>Insurance</option>
                                <option value="quality_cert" <?php echo $document_type_filter === 'quality_cert' ? 'selected' : ''; ?>>Quality Certificate</option>
                                <option value="other" <?php echo $document_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-control" name="status" id="status">
                                <option value="">All Status</option>
                                <option value="valid" <?php echo $status_filter === 'valid' ? 'selected' : ''; ?>>Valid</option>
                                <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="pending_renewal" <?php echo $status_filter === 'pending_renewal' ? 'selected' : ''; ?>>Pending Renewal</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="expiry" class="form-label">Expiry</label>
                            <select class="form-control" name="expiry" id="expiry">
                                <option value="">All</option>
                                <option value="expired" <?php echo $expiry_filter === 'expired' ? 'selected' : ''; ?>>Already Expired</option>
                                <option value="expiring_soon" <?php echo $expiry_filter === 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon (30 days)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Filter
                                </button>
                                <a href="document_management.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Documents List -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Document Listing (<?php echo count($documents); ?> documents)</h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportDocuments()">
                            <i class="bi bi-download"></i> Export
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="generateExpiryReport()">
                            <i class="bi bi-file-text"></i> Expiry Report
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($documents)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-file-earmark text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-muted">No documents found</h4>
                        <p class="text-muted">Upload documents to get started.</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-plus"></i> Upload First Document
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Supplier</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Expiry Date</th>
                                    <th>Uploaded By</th>
                                    <th>Upload Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                <tr class="<?php echo $doc['expiry_status'] === 'expired' ? 'table-danger' : ($doc['expiry_status'] === 'expiring_soon' ? 'table-warning' : ''); ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-file-earmark-pdf me-2 text-danger" style="font-size: 1.5rem;"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                                <?php if ($doc['expiry_status'] === 'expired'): ?>
                                                <br><small class="text-danger"><i class="bi bi-exclamation-triangle"></i> EXPIRED</small>
                                                <?php elseif ($doc['expiry_status'] === 'expiring_soon'): ?>
                                                <br><small class="text-warning"><i class="bi bi-clock"></i> Expiring Soon</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['supplier_name']); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucwords(str_replace('_', ' ', $doc['document_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            echo $doc['status'] === 'valid' ? 'success' : 
                                                ($doc['status'] === 'expired' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $doc['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($doc['expiry_date']): ?>
                                            <?php echo date('M d, Y', strtotime($doc['expiry_date'])); ?>
                                            <?php if ($doc['expiry_status'] === 'expired'): ?>
                                                <br><small class="text-danger">(<?php echo floor((time() - strtotime($doc['expiry_date'])) / (60*60*24)); ?> days overdue)</small>
                                            <?php elseif ($doc['expiry_status'] === 'expiring_soon'): ?>
                                                <br><small class="text-warning">(<?php echo floor((strtotime($doc['expiry_date']) - time()) / (60*60*24)); ?> days left)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No expiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['uploaded_by_user']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="View Document">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="../<?php echo htmlspecialchars($doc['file_path']); ?>" download class="btn btn-sm btn-outline-success" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="updateDocumentStatus(<?php echo $doc['id']; ?>, '<?php echo $doc['status']; ?>')" title="Update Status">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                                <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="delete_document" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Supplier Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier_id" class="form-label">Supplier *</label>
                                    <select class="form-control" name="supplier_id" id="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="document_type" class="form-label">Document Type *</label>
                                    <select class="form-control" name="document_type" id="document_type" required>
                                        <option value="">Select Type</option>
                                        <option value="contract">Contract</option>
                                        <option value="tax_certificate">Tax Certificate</option>
                                        <option value="business_license">Business License</option>
                                        <option value="insurance">Insurance</option>
                                        <option value="quality_cert">Quality Certificate</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="document_name" class="form-label">Document Name *</label>
                            <input type="text" class="form-control" name="document_name" id="document_name" required placeholder="Enter document name">
                        </div>
                        
                        <div class="mb-3">
                            <label for="document_file" class="form-label">Document File *</label>
                            <input type="file" class="form-control" name="document_file" id="document_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                            <small class="form-text text-muted">Allowed file types: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT. Max size: 10MB</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="expiry_date" class="form-label">Expiry Date (Optional)</label>
                            <input type="date" class="form-control" name="expiry_date" id="expiry_date">
                            <small class="form-text text-muted">Leave blank if document doesn't expire</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_document" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Upload Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Document Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="document_id" id="statusDocumentId">
                        <div class="mb-3">
                            <label for="statusSelect" class="form-label">Status</label>
                            <select class="form-control" name="status" id="statusSelect" required>
                                <option value="valid">Valid</option>
                                <option value="expired">Expired</option>
                                <option value="pending_renewal">Pending Renewal</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateDocumentStatus(documentId, currentStatus) {
            document.getElementById('statusDocumentId').value = documentId;
            document.getElementById('statusSelect').value = currentStatus;
            
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            modal.show();
        }
        
        function exportDocuments() {
            // Create a simple CSV export
            let csv = 'Document Name,Supplier,Type,Status,Expiry Date,Uploaded By,Upload Date\n';
            
            <?php foreach ($documents as $doc): ?>
            csv += '<?php echo addslashes($doc['document_name']); ?>,';
            csv += '<?php echo addslashes($doc['supplier_name']); ?>,';
            csv += '<?php echo addslashes(ucwords(str_replace('_', ' ', $doc['document_type']))); ?>,';
            csv += '<?php echo addslashes(ucwords(str_replace('_', ' ', $doc['status']))); ?>,';
            csv += '<?php echo $doc['expiry_date'] ? date('Y-m-d', strtotime($doc['expiry_date'])) : 'No expiry'; ?>,';
            csv += '<?php echo addslashes($doc['uploaded_by_user']); ?>,';
            csv += '<?php echo date('Y-m-d', strtotime($doc['uploaded_at'])); ?>\n';
            <?php endforeach; ?>
            
            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'supplier_documents_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        function generateExpiryReport() {
            // Create expiry report CSV
            let csv = 'Document Name,Supplier,Type,Expiry Date,Days Until/Since Expiry,Status\n';
            
            <?php foreach ($documents as $doc): ?>
            <?php if ($doc['expiry_date']): ?>
            csv += '<?php echo addslashes($doc['document_name']); ?>,';
            csv += '<?php echo addslashes($doc['supplier_name']); ?>,';
            csv += '<?php echo addslashes(ucwords(str_replace('_', ' ', $doc['document_type']))); ?>,';
            csv += '<?php echo date('Y-m-d', strtotime($doc['expiry_date'])); ?>,';
            <?php if ($doc['expiry_status'] === 'expired'): ?>
            csv += '<?php echo '-' . floor((time() - strtotime($doc['expiry_date'])) / (60*60*24)); ?>,Expired';
            <?php elseif ($doc['expiry_status'] === 'expiring_soon'): ?>
            csv += '<?php echo floor((strtotime($doc['expiry_date']) - time()) / (60*60*24)); ?>,Expiring Soon';
            <?php else: ?>
            csv += '<?php echo floor((strtotime($doc['expiry_date']) - time()) / (60*60*24)); ?>,Valid';
            <?php endif; ?>
            csv += '\n';
            <?php endif; ?>
            <?php endforeach; ?>
            
            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'document_expiry_report_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        // Auto-select supplier if coming from supplier page
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const supplierId = urlParams.get('supplier_id');
            if (supplierId) {
                const supplierSelect = document.querySelector('#uploadModal select[name="supplier_id"]');
                if (supplierSelect) {
                    supplierSelect.value = supplierId;
                }
            }
        });
        
        // File size validation
        document.getElementById('document_file').addEventListener('change', function() {
            const file = this.files[0];
            if (file && file.size > 10 * 1024 * 1024) { // 10MB limit
                alert('File size must be less than 10MB');
                this.value = '';
            }
        });
    </script>
</body>
</html>
