<?php
session_start();
require_once '../include/db.php';
require_once '../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

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

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get budget settings
$budget_settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM budget_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $budget_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $budget_settings = [
        'budget_alert_threshold_warning' => '75',
        'budget_alert_threshold_critical' => '90',
        'default_currency' => 'KES'
    ];
}

// Handle template operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_template') {
        try {
            $template_name = trim($_POST['template_name']);
            $description = trim($_POST['description'] ?? '');
            $budget_type = $_POST['budget_type'];
            $template_items = $_POST['template_items'] ?? [];
            
            // Create budget template
            $stmt = $conn->prepare("
                INSERT INTO budget_templates (name, description, budget_type, created_by, is_active) 
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$template_name, $description, $budget_type, $user_id]);
            
            $template_id = $conn->lastInsertId();
            
            // Create template items
            foreach ($template_items as $item) {
                if (!empty($item['name']) && !empty($item['amount'])) {
                    $stmt = $conn->prepare("
                        INSERT INTO budget_template_items (template_id, category_id, name, description, budgeted_amount, percentage) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $template_id, 
                        $item['category_id'], 
                        $item['name'], 
                        $item['description'] ?? '',
                        (float)$item['amount'],
                        (float)$item['percentage'] ?? 0
                    ]);
                }
            }
            
            $success_message = "Budget template created successfully!";
        } catch (Exception $e) {
            $error_message = "Error creating template: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'use_template') {
        try {
            $template_id = $_POST['template_id'];
            $budget_name = trim($_POST['budget_name']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $total_amount = (float)$_POST['total_amount'];
            
            // Get template details
            $stmt = $conn->prepare("
                SELECT * FROM budget_templates WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$template_id]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template) {
                // Create budget from template
                $stmt = $conn->prepare("
                    INSERT INTO budgets (name, description, budget_type, start_date, end_date, total_budget_amount, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$budget_name, $template['description'], $template['budget_type'], $start_date, $end_date, $total_amount, $user_id]);
                
                $budget_id = $conn->lastInsertId();
                
                // Get template items and create budget items
                $stmt = $conn->prepare("
                    SELECT * FROM budget_template_items WHERE template_id = ?
                ");
                $stmt->execute([$template_id]);
                $template_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($template_items as $item) {
                    $item_amount = $item['budgeted_amount'];
                    if ($item['percentage'] > 0) {
                        $item_amount = ($total_amount * $item['percentage']) / 100;
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO budget_items (budget_id, category_id, name, description, budgeted_amount) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$budget_id, $item['category_id'], $item['name'], $item['description'], $item_amount]);
                }
                
                $success_message = "Budget created from template successfully!";
            }
        } catch (Exception $e) {
            $error_message = "Error creating budget from template: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'delete_template') {
        try {
            $template_id = $_POST['template_id'];
            
            // Soft delete template
            $stmt = $conn->prepare("
                UPDATE budget_templates SET is_active = 0 WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([$template_id, $user_id]);
            
            $success_message = "Template deleted successfully!";
        } catch (Exception $e) {
            $error_message = "Error deleting template: " . $e->getMessage();
        }
    }
}

// Get budget templates
$templates = [];
try {
    $stmt = $conn->prepare("
        SELECT bt.*, u.username as created_by_name,
               COUNT(bti.id) as items_count,
               COALESCE(SUM(bti.budgeted_amount), 0) as total_amount
        FROM budget_templates bt
        LEFT JOIN users u ON bt.created_by = u.id
        LEFT JOIN budget_template_items bti ON bt.id = bti.template_id
        WHERE bt.is_active = 1
        GROUP BY bt.id
        ORDER BY bt.created_at DESC
    ");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error
}

// Get budget categories
$budget_categories = [];
try {
    $stmt = $conn->query("SELECT * FROM budget_categories WHERE is_active = TRUE ORDER BY name");
    $budget_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Categories table doesn't exist
}

// Get template details for modal
$template_details = [];
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    try {
        $stmt = $conn->prepare("
            SELECT bt.*, u.username as created_by_name
            FROM budget_templates bt
            LEFT JOIN users u ON bt.created_by = u.id
            WHERE bt.id = ? AND bt.is_active = 1
        ");
        $stmt->execute([$_GET['view']]);
        $template_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template_details) {
            $stmt = $conn->prepare("
                SELECT bti.*, bc.name as category_name
                FROM budget_template_items bti
                LEFT JOIN budget_categories bc ON bti.category_id = bc.id
                WHERE bti.template_id = ?
                ORDER BY bti.budgeted_amount DESC
            ");
            $stmt->execute([$_GET['view']]);
            $template_details['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Handle error
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Templates - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="budget.php">Budget Management</a></li>
                            <li class="breadcrumb-item active">Budget Templates</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-file-earmark-text"></i> Budget Templates</h1>
                    <p class="header-subtitle">Create and manage reusable budget templates</p>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                                    <i class="bi bi-plus-circle me-1"></i> Create Template
                                </button>
                                <a href="budget.php" class="btn btn-outline-primary">
                                    <i class="bi bi-arrow-left me-1"></i> Back to Budgets
                                </a>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="exportTemplates()">
                                    <i class="bi bi-download me-1"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Templates Grid -->
                <div class="row">
                    <?php if (empty($templates)): ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="bi bi-file-earmark-text fs-1 text-muted mb-3"></i>
                            <h5 class="text-muted">No Budget Templates</h5>
                            <p class="text-muted mb-4">Create your first budget template to streamline budget creation</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                                <i class="bi bi-plus-circle me-1"></i> Create Template
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($templates as $template): ?>
                    <div class="col-xl-4 col-lg-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><?php echo htmlspecialchars($template['name']); ?></h6>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="?view=<?php echo $template['id']; ?>" data-bs-toggle="modal" data-bs-target="#viewTemplateModal">
                                            <i class="bi bi-eye me-2"></i>View Details
                                        </a></li>
                                        <li><a class="dropdown-item" href="#" onclick="useTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['name']); ?>')">
                                            <i class="bi bi-plus-circle me-2"></i>Use Template
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteTemplate(<?php echo $template['id']; ?>)">
                                            <i class="bi bi-trash me-2"></i>Delete
                                        </a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body">
                                <p class="card-text text-muted mb-3">
                                    <?php echo htmlspecialchars(substr($template['description'], 0, 100)); ?>
                                    <?php echo strlen($template['description']) > 100 ? '...' : ''; ?>
                                </p>
                                
                                <div class="row text-center mb-3">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h6 class="text-primary mb-1"><?php echo $template['items_count']; ?></h6>
                                            <small class="text-muted">Items</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h6 class="text-success mb-1"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($template['total_amount'], 0); ?></h6>
                                        <small class="text-muted">Total</small>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-<?php echo $template['budget_type'] === 'monthly' ? 'primary' : ($template['budget_type'] === 'quarterly' ? 'info' : 'success'); ?>">
                                        <?php echo ucfirst($template['budget_type']); ?>
                                    </span>
                                    <small class="text-muted">
                                        by <?php echo htmlspecialchars($template['created_by_name']); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button class="btn btn-primary w-100" onclick="useTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['name']); ?>')">
                                    <i class="bi bi-plus-circle me-1"></i> Use This Template
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Create Template Modal -->
                <div class="modal fade" id="createTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_template">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create Budget Template</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="template_name" class="form-label">Template Name *</label>
                                            <input type="text" class="form-control" id="template_name" name="template_name" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="budget_type" class="form-label">Budget Type *</label>
                                            <select class="form-select" id="budget_type" name="budget_type" required>
                                                <option value="monthly">Monthly</option>
                                                <option value="quarterly">Quarterly</option>
                                                <option value="yearly">Yearly</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="2" placeholder="Describe this template..."></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label mb-0">Template Items</label>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addTemplateItem()">
                                                <i class="bi bi-plus"></i> Add Item
                                            </button>
                                        </div>
                                        <div id="template-items-container">
                                            <!-- Template items will be added dynamically -->
                                        </div>
                                        <small class="text-muted">Add budget items that will be included in this template</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Create Template</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Use Template Modal -->
                <div class="modal fade" id="useTemplateModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="use_template">
                                <input type="hidden" name="template_id" id="use_template_id">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Create Budget from Template</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="budget_name" class="form-label">Budget Name *</label>
                                        <input type="text" class="form-control" id="budget_name" name="budget_name" required>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="start_date" class="form-label">Start Date *</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="end_date" class="form-label">End Date *</label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-t'); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="total_amount" class="form-label">Total Budget Amount *</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                            <input type="number" class="form-control" id="total_amount" name="total_amount" step="0.01" min="0" required>
                                        </div>
                                        <small class="text-muted">Template items will be scaled proportionally to this amount</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Create Budget</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- View Template Modal -->
                <div class="modal fade" id="viewTemplateModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Template Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php if (!empty($template_details)): ?>
                                <div class="mb-3">
                                    <h6><?php echo htmlspecialchars($template_details['name']); ?></h6>
                                    <p class="text-muted"><?php echo htmlspecialchars($template_details['description']); ?></p>
                                    <div class="d-flex justify-content-between">
                                        <span class="badge bg-primary"><?php echo ucfirst($template_details['budget_type']); ?></span>
                                        <small class="text-muted">Created by <?php echo htmlspecialchars($template_details['created_by_name']); ?></small>
                                    </div>
                                </div>
                                
                                <h6>Template Items</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Item Name</th>
                                                <th>Category</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-center">Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($template_details['items'] as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                                <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                                                <td class="text-end"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?> <?php echo number_format($item['budgeted_amount'], 2); ?></td>
                                                <td class="text-center"><?php echo $item['percentage'] > 0 ? number_format($item['percentage'], 1) . '%' : 'Fixed'; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if (!empty($template_details)): ?>
                                <button type="button" class="btn btn-primary" onclick="useTemplate(<?php echo $template_details['id']; ?>, '<?php echo htmlspecialchars($template_details['name']); ?>')">
                                    <i class="bi bi-plus-circle me-1"></i> Use This Template
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCounter = 0;
        
        function addTemplateItem() {
            itemCounter++;
            const container = document.getElementById('template-items-container');
            const itemHtml = `
                <div class="card mb-2 template-item" id="item-${itemCounter}">
                    <div class="card-body p-3">
                        <div class="row align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select class="form-select form-select-sm" name="template_items[${itemCounter}][category_id]" required>
                                    <option value="">Select category...</option>
                                    <?php foreach ($budget_categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Item Name</label>
                                <input type="text" class="form-control form-control-sm" name="template_items[${itemCounter}][name]" placeholder="e.g., Office Supplies" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Amount</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?php echo htmlspecialchars($budget_settings['default_currency'] ?? 'KES'); ?></span>
                                    <input type="number" class="form-control" name="template_items[${itemCounter}][amount]" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Percentage</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control" name="template_items[${itemCounter}][percentage]" step="0.1" min="0" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeTemplateItem(${itemCounter})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control form-control-sm" name="template_items[${itemCounter}][description]" rows="1" placeholder="Optional description..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);
        }
        
        function removeTemplateItem(id) {
            document.getElementById(`item-${id}`).remove();
        }
        
        function useTemplate(templateId, templateName) {
            document.getElementById('use_template_id').value = templateId;
            document.getElementById('budget_name').value = templateName + ' - ' + new Date().toLocaleDateString();
            new bootstrap.Modal(document.getElementById('useTemplateModal')).show();
        }
        
        function deleteTemplate(templateId) {
            if (confirm('Are you sure you want to delete this template? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_template">
                    <input type="hidden" name="template_id" value="${templateId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function exportTemplates() {
            let csv = 'Budget Templates Export\n';
            csv += 'Generated: <?php echo date('Y-m-d H:i:s'); ?>\n\n';
            
            csv += 'TEMPLATE DETAILS\n';
            csv += 'Name,Type,Description,Items Count,Total Amount,Created By,Created Date\n';
            <?php foreach ($templates as $template): ?>
            csv += '<?php echo addslashes($template['name']); ?>,<?php echo $template['budget_type']; ?>,<?php echo addslashes($template['description']); ?>,<?php echo $template['items_count']; ?>,<?php echo $template['total_amount']; ?>,<?php echo addslashes($template['created_by_name']); ?>,<?php echo $template['created_at']; ?>\n';
            <?php endforeach; ?>
            
            // Download
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', 'budget-templates-export-<?php echo date('Y-m-d'); ?>.csv');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        
        // Add first template item by default when modal opens
        document.getElementById('createTemplateModal')?.addEventListener('shown.bs.modal', function() {
            const container = document.getElementById('template-items-container');
            if (container.children.length === 0) {
                addTemplateItem();
            }
        });
    </script>
</body>
</html>
