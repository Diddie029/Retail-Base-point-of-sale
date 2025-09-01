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

// Create tables if they don't exist
try {
    // Supplier workflow states table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_workflow_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            workflow_state ENUM('pending_approval', 'approved', 'on_probation', 'suspended', 'blacklisted') DEFAULT 'pending_approval',
            state_reason TEXT,
            assigned_to INT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (assigned_to) REFERENCES users(id)
        )
    ");

    // Supplier communications table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_communications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            communication_type ENUM('email', 'phone', 'meeting', 'notice', 'warning', 'contract') DEFAULT 'email',
            subject VARCHAR(255),
            message TEXT,
            status ENUM('pending', 'sent', 'delivered', 'failed') DEFAULT 'pending',
            sent_by INT NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            response_required BOOLEAN DEFAULT FALSE,
            response_deadline DATE NULL,
            response_received BOOLEAN DEFAULT FALSE,
            attachments JSON,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (sent_by) REFERENCES users(id)
        )
    ");

    // Supplier documents table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            document_type ENUM('contract', 'tax_certificate', 'business_license', 'insurance', 'quality_cert', 'other') DEFAULT 'other',
            document_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500),
            expiry_date DATE NULL,
            status ENUM('valid', 'expired', 'pending_renewal') DEFAULT 'valid',
            uploaded_by INT NOT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        )
    ");

    // Supplier performance alerts table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supplier_performance_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            alert_type ENUM('performance_drop', 'delivery_delay', 'quality_issue', 'contract_expiry', 'document_expiry') NOT NULL,
            alert_level ENUM('info', 'warning', 'critical') DEFAULT 'warning',
            alert_message TEXT NOT NULL,
            is_resolved BOOLEAN DEFAULT FALSE,
            resolved_by INT NULL,
            resolved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by) REFERENCES users(id)
        )
    ");

} catch (Exception $e) {
    // Tables might already exist or there might be permission issues
    error_log("Error creating workflow tables: " . $e->getMessage());
}

// Handle workflow state changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_workflow_state'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $new_state = sanitizeProductInput($_POST['workflow_state']);
    $state_reason = trim($_POST['state_reason'] ?? '');
    $assigned_to = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;

    try {
        // Insert new workflow state
        $stmt = $conn->prepare("
            INSERT INTO supplier_workflow_states 
            (supplier_id, workflow_state, state_reason, assigned_to, created_by) 
            VALUES (:supplier_id, :workflow_state, :state_reason, :assigned_to, :created_by)
        ");
        $stmt->execute([
            ':supplier_id' => $supplier_id,
            ':workflow_state' => $new_state,
            ':state_reason' => $state_reason,
            ':assigned_to' => $assigned_to,
            ':created_by' => $user_id
        ]);

        // Update supplier status based on workflow state
        if ($new_state === 'approved') {
            $conn->prepare("UPDATE suppliers SET is_active = 1 WHERE id = :id")->execute([':id' => $supplier_id]);
        } elseif (in_array($new_state, ['suspended', 'blacklisted'])) {
            $conn->prepare("UPDATE suppliers SET is_active = 0 WHERE id = :id")->execute([':id' => $supplier_id]);
        }

        $_SESSION['success'] = 'Workflow state updated successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to update workflow state: ' . $e->getMessage();
    }

    header("Location: workflow_management.php");
    exit();
}

// Handle communication logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_communication'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $communication_type = sanitizeProductInput($_POST['communication_type']);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $response_required = isset($_POST['response_required']) ? 1 : 0;
    $response_deadline = !empty($_POST['response_deadline']) ? $_POST['response_deadline'] : null;

    try {
        $stmt = $conn->prepare("
            INSERT INTO supplier_communications 
            (supplier_id, communication_type, subject, message, sent_by, response_required, response_deadline, status) 
            VALUES (:supplier_id, :communication_type, :subject, :message, :sent_by, :response_required, :response_deadline, 'sent')
        ");
        $stmt->execute([
            ':supplier_id' => $supplier_id,
            ':communication_type' => $communication_type,
            ':subject' => $subject,
            ':message' => $message,
            ':sent_by' => $user_id,
            ':response_required' => $response_required,
            ':response_deadline' => $response_deadline
        ]);

        $_SESSION['success'] = 'Communication logged successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to log communication: ' . $e->getMessage();
    }

    header("Location: workflow_management.php");
    exit();
}

// Handle performance alert resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_alert'])) {
    $alert_id = intval($_POST['alert_id']);
    
    try {
        $stmt = $conn->prepare("
            UPDATE supplier_performance_alerts 
            SET is_resolved = 1, resolved_by = :resolved_by, resolved_at = NOW() 
            WHERE id = :alert_id
        ");
        $stmt->execute([
            ':alert_id' => $alert_id,
            ':resolved_by' => $user_id
        ]);

        $_SESSION['success'] = 'Alert resolved successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Failed to resolve alert: ' . $e->getMessage();
    }

    header("Location: workflow_management.php");
    exit();
}

// Get suppliers with workflow information
$suppliers_query = "
    SELECT s.*,
           sws.workflow_state,
           sws.state_reason,
           sws.created_at as state_changed_at,
           u.username as assigned_to_user,
           creator.username as state_created_by,
           COUNT(DISTINCT spa.id) as active_alerts,
           COUNT(DISTINCT sc.id) as total_communications,
           COUNT(DISTINCT sd.id) as total_documents,
           COUNT(DISTINCT CASE WHEN sd.status = 'expired' OR sd.expiry_date < CURDATE() THEN sd.id END) as expired_documents
    FROM suppliers s
    LEFT JOIN (
        SELECT supplier_id, workflow_state, state_reason, assigned_to, created_by, created_at,
               ROW_NUMBER() OVER (PARTITION BY supplier_id ORDER BY created_at DESC) as rn
        FROM supplier_workflow_states
    ) sws ON s.id = sws.supplier_id AND sws.rn = 1
    LEFT JOIN users u ON sws.assigned_to = u.id
    LEFT JOIN users creator ON sws.created_by = creator.id
    LEFT JOIN supplier_performance_alerts spa ON s.id = spa.supplier_id AND spa.is_resolved = 0
    LEFT JOIN supplier_communications sc ON s.id = sc.supplier_id
    LEFT JOIN supplier_documents sd ON s.id = sd.supplier_id
    GROUP BY s.id
    ORDER BY sws.created_at DESC, s.name ASC
";

$suppliers = $conn->query($suppliers_query)->fetchAll(PDO::FETCH_ASSOC);

// Get active alerts
$alerts_query = "
    SELECT spa.*, s.name as supplier_name
    FROM supplier_performance_alerts spa
    JOIN suppliers s ON spa.supplier_id = s.id
    WHERE spa.is_resolved = 0
    ORDER BY spa.created_at DESC
    LIMIT 10
";
$active_alerts = $conn->query($alerts_query)->fetchAll(PDO::FETCH_ASSOC);

// Get pending workflow actions
$pending_actions_query = "
    SELECT sws.*, s.name as supplier_name, u.username as assigned_to_user
    FROM supplier_workflow_states sws
    JOIN suppliers s ON sws.supplier_id = s.id
    LEFT JOIN users u ON sws.assigned_to = u.id
    WHERE sws.workflow_state IN ('pending_approval', 'on_probation')
    AND sws.id IN (
        SELECT MAX(id) FROM supplier_workflow_states GROUP BY supplier_id
    )
    ORDER BY sws.created_at ASC
";
$pending_actions = $conn->query($pending_actions_query)->fetchAll(PDO::FETCH_ASSOC);

// Get users for assignment
$users_query = "SELECT id, username FROM users WHERE is_active = 1 ORDER BY username";
$users = $conn->query($users_query)->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Supplier Workflow Management - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
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
                    <h1>Supplier Workflow Management</h1>
                    <div class="header-subtitle">Manage supplier approval workflows and communications</div>
                </div>
                <div class="header-actions">
                    <a href="suppliers.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left"></i>
                        Back to Suppliers
                    </a>
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

            <!-- Dashboard Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--warning-color);">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Pending Approvals</div>
                        <div class="stat-card-value"><?php echo count($pending_actions); ?></div>
                        <div class="stat-card-trend trend-neutral">
                            <i class="bi bi-dash"></i>
                            <span>Require attention</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--danger-color);">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Active Alerts</div>
                        <div class="stat-card-value"><?php echo count($active_alerts); ?></div>
                        <div class="stat-card-trend trend-down">
                            <i class="bi bi-arrow-down"></i>
                            <span>Need resolution</span>
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
                        <div class="stat-card-title">Approved Suppliers</div>
                        <div class="stat-card-value"><?php 
                            echo count(array_filter($suppliers, function($s) { 
                                return $s['workflow_state'] === 'approved' || $s['workflow_state'] === null; 
                            }));
                        ?></div>
                        <div class="stat-card-trend trend-up">
                            <i class="bi bi-arrow-up"></i>
                            <span>Active and ready</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon" style="background: var(--info-color);">
                                <i class="bi bi-file-earmark"></i>
                            </div>
                        </div>
                        <div class="stat-card-title">Document Issues</div>
                        <div class="stat-card-value"><?php 
                            echo array_sum(array_column($suppliers, 'expired_documents'));
                        ?></div>
                        <div class="stat-card-trend trend-down">
                            <i class="bi bi-arrow-down"></i>
                            <span>Expired documents</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Workflow Tabs -->
            <ul class="nav nav-tabs" id="workflowTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                        <i class="bi bi-clock me-2"></i>Pending Actions (<?php echo count($pending_actions); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#alerts" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-2"></i>Active Alerts (<?php echo count($active_alerts); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="all-suppliers-tab" data-bs-toggle="tab" data-bs-target="#all-suppliers" type="button" role="tab">
                        <i class="bi bi-list me-2"></i>All Suppliers (<?php echo count($suppliers); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="communications-tab" data-bs-toggle="tab" data-bs-target="#communications" type="button" role="tab">
                        <i class="bi bi-chat-dots me-2"></i>Communications
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="workflowTabContent">
                <!-- Pending Actions Tab -->
                <div class="tab-pane fade show active" id="pending" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">Pending Workflow Actions</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pending_actions)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">No pending actions</h5>
                                <p class="text-muted">All suppliers are properly managed.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Supplier</th>
                                            <th>Current State</th>
                                            <th>Reason</th>
                                            <th>Assigned To</th>
                                            <th>Since</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_actions as $action): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($action['supplier_name']); ?></strong></td>
                                            <td>
                                                <span class="badge badge-<?php echo $action['workflow_state'] === 'pending_approval' ? 'warning' : 'info'; ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $action['workflow_state'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($action['state_reason'] ?? 'No reason provided'); ?></td>
                                            <td><?php echo htmlspecialchars($action['assigned_to_user'] ?? 'Unassigned'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($action['created_at'])); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary" onclick="showWorkflowModal(<?php echo $action['supplier_id']; ?>, '<?php echo htmlspecialchars($action['supplier_name']); ?>')">
                                                    <i class="bi bi-gear"></i> Manage
                                                </button>
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

                <!-- Active Alerts Tab -->
                <div class="tab-pane fade" id="alerts" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">Active Performance Alerts</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($active_alerts)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-shield-check text-success" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">No active alerts</h5>
                                <p class="text-muted">All suppliers are performing well.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($active_alerts as $alert): ?>
                            <div class="alert alert-<?php 
                                echo $alert['alert_level'] === 'critical' ? 'danger' : 
                                    ($alert['alert_level'] === 'warning' ? 'warning' : 'info'); 
                            ?> d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($alert['supplier_name']); ?></strong>
                                    <br>
                                    <small><?php echo htmlspecialchars($alert['alert_message']); ?></small>
                                    <br>
                                    <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($alert['created_at'])); ?></small>
                                </div>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                    <button type="submit" name="resolve_alert" class="btn btn-sm btn-success">
                                        <i class="bi bi-check"></i> Resolve
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- All Suppliers Tab -->
                <div class="tab-pane fade" id="all-suppliers" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">All Suppliers - Workflow Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Supplier</th>
                                            <th>Workflow State</th>
                                            <th>Alerts</th>
                                            <th>Documents</th>
                                            <th>Communications</th>
                                            <th>Last Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="product-image-placeholder me-3">
                                                        <i class="bi bi-truck"></i>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($supplier['name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($supplier['contact_person'] ?? 'No contact'); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $state = $supplier['workflow_state'] ?? 'approved';
                                                $state_class = [
                                                    'pending_approval' => 'warning',
                                                    'approved' => 'success',
                                                    'on_probation' => 'info',
                                                    'suspended' => 'danger',
                                                    'blacklisted' => 'dark'
                                                ][$state] ?? 'success';
                                                ?>
                                                <span class="badge badge-<?php echo $state_class; ?>">
                                                    <?php echo ucwords(str_replace('_', ' ', $state)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($supplier['active_alerts'] > 0): ?>
                                                <span class="badge badge-danger"><?php echo $supplier['active_alerts']; ?> alerts</span>
                                                <?php else: ?>
                                                <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge badge-primary"><?php echo $supplier['total_documents']; ?> docs</span>
                                                    <?php if ($supplier['expired_documents'] > 0): ?>
                                                    <span class="badge badge-danger"><?php echo $supplier['expired_documents']; ?> expired</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-info"><?php echo $supplier['total_communications']; ?> messages</span>
                                            </td>
                                            <td>
                                                <?php echo $supplier['state_changed_at'] ? date('M d, Y', strtotime($supplier['state_changed_at'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="showWorkflowModal(<?php echo $supplier['id']; ?>, '<?php echo htmlspecialchars($supplier['name']); ?>')">
                                                        <i class="bi bi-gear"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info" onclick="showCommunicationModal(<?php echo $supplier['id']; ?>, '<?php echo htmlspecialchars($supplier['name']); ?>')">
                                                        <i class="bi bi-chat"></i>
                                                    </button>
                                                    <a href="view.php?id=<?php echo $supplier['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Communications Tab -->
                <div class="tab-pane fade" id="communications" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Communications</h5>
                            <button type="button" class="btn btn-primary btn-sm" onclick="showCommunicationModal(0, 'New Communication')">
                                <i class="bi bi-plus"></i> New Communication
                            </button>
                        </div>
                        <div class="card-body">
                            <?php
                            $recent_communications = $conn->query("
                                SELECT sc.*, s.name as supplier_name, u.username as sent_by_user
                                FROM supplier_communications sc
                                JOIN suppliers s ON sc.supplier_id = s.id
                                JOIN users u ON sc.sent_by = u.id
                                ORDER BY sc.sent_at DESC
                                LIMIT 20
                            ")->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if (empty($recent_communications)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-chat-dots text-muted" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">No communications yet</h5>
                                <p class="text-muted">Start logging supplier communications.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($recent_communications as $comm): ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($comm['subject']); ?></h6>
                                        <p class="mb-1 text-muted"><?php echo htmlspecialchars($comm['message']); ?></p>
                                        <small class="text-muted">
                                            <strong><?php echo htmlspecialchars($comm['supplier_name']); ?></strong> • 
                                            <?php echo ucfirst($comm['communication_type']); ?> • 
                                            <?php echo htmlspecialchars($comm['sent_by_user']); ?> • 
                                            <?php echo date('M d, Y H:i', strtotime($comm['sent_at'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge badge-<?php 
                                        echo $comm['status'] === 'sent' ? 'success' : 
                                            ($comm['status'] === 'failed' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php echo ucfirst($comm['status']); ?>
                                    </span>
                                </div>
                                <?php if ($comm['response_required'] && !$comm['response_received']): ?>
                                <div class="mt-2">
                                    <small class="badge badge-warning">
                                        <i class="bi bi-clock"></i> Response required by <?php echo date('M d, Y', strtotime($comm['response_deadline'])); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Workflow Management Modal -->
    <div class="modal fade" id="workflowModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Supplier Workflow</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="supplier_id" id="workflowSupplierId">
                        
                        <div class="mb-3">
                            <label for="workflowState" class="form-label">Workflow State</label>
                            <select class="form-control" name="workflow_state" id="workflowState" required>
                                <option value="pending_approval">Pending Approval</option>
                                <option value="approved">Approved</option>
                                <option value="on_probation">On Probation</option>
                                <option value="suspended">Suspended</option>
                                <option value="blacklisted">Blacklisted</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stateReason" class="form-label">Reason for State Change</label>
                            <textarea class="form-control" name="state_reason" id="stateReason" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="assignedTo" class="form-label">Assign To</label>
                            <select class="form-control" name="assigned_to" id="assignedTo">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="change_workflow_state" class="btn btn-primary">Update Workflow</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Communication Modal -->
    <div class="modal fade" id="communicationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Communication</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="supplier_id" id="communicationSupplierId">
                        
                        <div class="mb-3">
                            <label for="communicationType" class="form-label">Communication Type</label>
                            <select class="form-control" name="communication_type" id="communicationType" required>
                                <option value="email">Email</option>
                                <option value="phone">Phone Call</option>
                                <option value="meeting">Meeting</option>
                                <option value="notice">Formal Notice</option>
                                <option value="warning">Warning</option>
                                <option value="contract">Contract Discussion</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="communicationSubject" class="form-label">Subject</label>
                            <input type="text" class="form-control" name="subject" id="communicationSubject" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="communicationMessage" class="form-label">Message/Notes</label>
                            <textarea class="form-control" name="message" id="communicationMessage" rows="4" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="response_required" id="responseRequired">
                                    <label class="form-check-label" for="responseRequired">
                                        Response Required
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="responseDeadline" class="form-label">Response Deadline</label>
                                <input type="date" class="form-control" name="response_deadline" id="responseDeadline">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="log_communication" class="btn btn-primary">Log Communication</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showWorkflowModal(supplierId, supplierName) {
            document.getElementById('workflowSupplierId').value = supplierId;
            document.querySelector('#workflowModal .modal-title').textContent = `Manage Workflow - ${supplierName}`;
            
            const modal = new bootstrap.Modal(document.getElementById('workflowModal'));
            modal.show();
        }
        
        function showCommunicationModal(supplierId, supplierName) {
            if (supplierId === 0) {
                // Show supplier selection dropdown for new communication
                document.getElementById('communicationSupplierId').value = '';
                document.querySelector('#communicationModal .modal-title').textContent = 'Log New Communication';
            } else {
                document.getElementById('communicationSupplierId').value = supplierId;
                document.querySelector('#communicationModal .modal-title').textContent = `Log Communication - ${supplierName}`;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('communicationModal'));
            modal.show();
        }
        
        // Auto-generate alerts based on performance data
        function checkPerformanceAlerts() {
            // This would typically be run via a cron job
            console.log('Checking for performance-based alerts...');
        }
        
        // Toggle response deadline field based on response required checkbox
        document.addEventListener('DOMContentLoaded', function() {
            const responseRequired = document.getElementById('responseRequired');
            const responseDeadline = document.getElementById('responseDeadline');
            
            responseRequired.addEventListener('change', function() {
                responseDeadline.disabled = !this.checked;
                responseDeadline.required = this.checked;
                if (!this.checked) {
                    responseDeadline.value = '';
                }
            });
        });
    </script>
</body>
</html>
