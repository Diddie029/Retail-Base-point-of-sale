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
$user_role = $_SESSION['role'] ?? $_SESSION['role_name'] ?? 'User';
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

// Get system settings for navmenu display
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Check permissions
if (!hasPermission('manage_expense_departments', $permissions)) {
    header('Location: index.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_department') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $manager_name = trim($_POST['manager_name']);
        $budget_limit = floatval($_POST['budget_limit']);
        $sort_order = intval($_POST['sort_order']);
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Department name is required";
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO expense_departments (name, description, manager_name, budget_limit, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $manager_name, $budget_limit, $sort_order]);
                
                $_SESSION['success_message'] = "Department added successfully";
                header('Location: departments.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error adding department: " . $e->getMessage();
            }
        }
    } elseif ($action == 'edit_department') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $manager_name = trim($_POST['manager_name']);
        $budget_limit = floatval($_POST['budget_limit']);
        $sort_order = intval($_POST['sort_order']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            $_SESSION['error_message'] = "Department name is required";
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE expense_departments 
                    SET name = ?, description = ?, manager_name = ?, budget_limit = ?, 
                        sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $manager_name, $budget_limit, $sort_order, $is_active, $id]);
                
                $_SESSION['success_message'] = "Department updated successfully";
                header('Location: departments.php');
                exit();
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error updating department: " . $e->getMessage();
            }
        }
    } elseif ($action == 'delete_department') {
        $id = $_POST['id'];
        
        try {
            // Check if department is used in expenses
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE department_id = ?");
            $stmt->execute([$id]);
            $expense_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($expense_count > 0) {
                $_SESSION['error_message'] = "Cannot delete department that is used in expenses.";
            } else {
                $stmt = $conn->prepare("DELETE FROM expense_departments WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['success_message'] = "Department deleted successfully";
            }
            header('Location: departments.php');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting department: " . $e->getMessage();
        }
    } elseif ($action == 'bulk_delete') {
        $department_ids = $_POST['department_ids'] ?? [];
        
        if (empty($department_ids)) {
            $_SESSION['error_message'] = "No departments selected for deletion";
        } else {
            try {
                $conn->beginTransaction();
                
                $deleted_count = 0;
                $skipped_count = 0;
                $skipped_names = [];
                
                foreach ($department_ids as $id) {
                    // Check if department is used in expenses
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM expenses WHERE department_id = ?");
                    $stmt->execute([$id]);
                    $expense_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    // Get department name for feedback
                    $stmt = $conn->prepare("SELECT name FROM expense_departments WHERE id = ?");
                    $stmt->execute([$id]);
                    $department_name = $stmt->fetchColumn();
                    
                    if ($expense_count > 0) {
                        $skipped_count++;
                        $skipped_names[] = $department_name;
                    } else {
                        $stmt = $conn->prepare("DELETE FROM expense_departments WHERE id = ?");
                        $stmt->execute([$id]);
                        $deleted_count++;
                    }
                }
                
                $conn->commit();
                
                $message = "";
                if ($deleted_count > 0) {
                    $message .= "Successfully deleted {$deleted_count} departments.";
                }
                if ($skipped_count > 0) {
                    $message .= ($message ? " " : "") . "Skipped {$skipped_count} departments (" . implode(', ', array_slice($skipped_names, 0, 3)) . ($skipped_count > 3 ? '...' : '') . ") because they are used in expenses.";
                }
                
                if ($deleted_count > 0) {
                    $_SESSION['success_message'] = $message;
                } else {
                    $_SESSION['error_message'] = $message ?: "No departments could be deleted.";
                }
                
            } catch (PDOException $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Error during bulk delete: " . $e->getMessage();
            }
        }
        header('Location: departments.php');
        exit();
    } elseif ($action == 'bulk_activate') {
        $department_ids = $_POST['department_ids'] ?? [];
        
        if (empty($department_ids)) {
            $_SESSION['error_message'] = "No departments selected for activation";
        } else {
            try {
                $placeholders = str_repeat('?,', count($department_ids) - 1) . '?';
                $stmt = $conn->prepare("UPDATE expense_departments SET is_active = 1 WHERE id IN ({$placeholders})");
                $stmt->execute($department_ids);
                
                $_SESSION['success_message'] = "Successfully activated " . count($department_ids) . " departments.";
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error during bulk activation: " . $e->getMessage();
            }
        }
        header('Location: departments.php');
        exit();
    } elseif ($action == 'bulk_deactivate') {
        $department_ids = $_POST['department_ids'] ?? [];
        
        if (empty($department_ids)) {
            $_SESSION['error_message'] = "No departments selected for deactivation";
        } else {
            try {
                $placeholders = str_repeat('?,', count($department_ids) - 1) . '?';
                $stmt = $conn->prepare("UPDATE expense_departments SET is_active = 0 WHERE id IN ({$placeholders})");
                $stmt->execute($department_ids);
                
                $_SESSION['success_message'] = "Successfully deactivated " . count($department_ids) . " departments.";
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Error during bulk deactivation: " . $e->getMessage();
            }
        }
        header('Location: departments.php');
        exit();
    }
}

// Get departments with expense count and budget usage
$departments = $conn->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM expenses WHERE department_id = d.id) as expense_count,
           (SELECT COALESCE(SUM(total_amount), 0) FROM expenses WHERE department_id = d.id) as total_spent
    FROM expense_departments d
    ORDER BY d.sort_order, d.name
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Departments - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .budget-progress {
            min-width: 100px;
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-building"></i> Expense Departments</h1>
                    <p class="header-subtitle">Manage and organize your expense departments</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="bi bi-plus-circle"></i> Add Department
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Expenses
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">

                <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['success_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['error_message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); endif; ?>

                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list-ul"></i> Departments List</h5>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleDepartmentSelection()">
                                    <i class="bi bi-check-all"></i> Select All
                                </button>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" disabled id="bulkActionsBtn">
                                        <i class="bi bi-gear"></i> Bulk Actions
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item text-danger" href="#" onclick="bulkDeleteDepartments()"><i class="bi bi-trash"></i> Delete Selected</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkActivateDepartments()"><i class="bi bi-check-circle"></i> Activate Selected</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkDeactivateDepartments()"><i class="bi bi-x-circle"></i> Deactivate Selected</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="#" onclick="bulkExportDepartments()"><i class="bi bi-download"></i> Export Selected</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($departments)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-building fs-1 text-muted"></i>
                            <h5 class="mt-3">No departments found</h5>
                            <p class="text-muted">Create your first expense department to get started.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                                <i class="bi bi-plus-circle"></i> Add Department
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" class="form-check-input" id="selectAllDepartments" onchange="toggleAllDepartments()"></th>
                                        <th>Name</th>
                                        <th>Manager</th>
                                        <th>Budget Limit</th>
                                        <th>Budget Usage</th>
                                        <th>Expenses</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departments as $department): ?>
                                    <tr class="department-row" 
                                        data-department-id="<?= $department['id'] ?>" 
                                        data-department-name="<?= htmlspecialchars($department['name']) ?>"
                                        data-has-expenses="<?= $department['expense_count'] ?>">
                                        <td>
                                            <input type="checkbox" class="form-check-input department-checkbox" 
                                                   value="<?= $department['id'] ?>" 
                                                   onchange="updateBulkActions()"
                                                   <?= $department['expense_count'] > 0 ? 'data-has-dependencies="true"' : '' ?>>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?= htmlspecialchars($department['name']) ?></strong>
                                                <?php if ($department['description']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($department['description']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($department['manager_name'] ?: 'Not assigned') ?>
                                        </td>
                                        <td>
                                            <?php if ($department['budget_limit'] > 0): ?>
                                                <strong>KES <?= number_format($department['budget_limit'], 2) ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">No limit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $spent = $department['total_spent'];
                                            $budget = $department['budget_limit'];
                                            if ($budget > 0):
                                                $percentage = ($spent / $budget) * 100;
                                                $progressClass = $percentage >= 90 ? 'danger' : ($percentage >= 75 ? 'warning' : 'success');
                                            ?>
                                            <div class="budget-progress">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small>KES <?= number_format($spent, 0) ?></small>
                                                    <small><?= number_format($percentage, 1) ?>%</small>
                                                </div>
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar bg-<?= $progressClass ?>" 
                                                         style="width: <?= min($percentage, 100) ?>%"></div>
                                                </div>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">KES <?= number_format($spent, 0) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark"><?= number_format($department['expense_count']) ?></span>
                                            <small class="text-muted">expenses</small>
                                        </td>
                                        <td>
                                            <?php if ($department['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        onclick="editDepartment(<?= htmlspecialchars(json_encode($department)) ?>)" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($department['expense_count'] == 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="deleteDepartment(<?= $department['id'] ?>, '<?= htmlspecialchars($department['name']) ?>')" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <?php endif; ?>
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
        </main>
    </div>

    <!-- Add Department Modal -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_department">
                        
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Manager Name</label>
                            <input type="text" class="form-control" name="manager_name">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Budget Limit (KES)</label>
                                <input type="number" class="form-control" name="budget_limit" min="0" step="0.01" value="0">
                                <small class="form-text text-muted">Set to 0 for no limit</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" value="0" min="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div class="modal fade" id="editDepartmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_department">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Manager Name</label>
                            <input type="text" class="form-control" name="manager_name" id="edit_manager_name">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Budget Limit (KES)</label>
                                <input type="number" class="form-control" name="budget_limit" id="edit_budget_limit" min="0" step="0.01">
                                <small class="form-text text-muted">Set to 0 for no limit</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Sort Order</label>
                                <input type="number" class="form-control" name="sort_order" id="edit_sort_order" min="0">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Department Modal -->
    <div class="modal fade" id="deleteDepartmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_department">
                        <input type="hidden" name="id" id="delete_id">
                        
                        <p>Are you sure you want to delete the department "<strong id="delete_name"></strong>"?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Confirmation Modal -->
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkActionTitle">Bulk Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="bulkAction">
                        <div id="departmentIdsContainer"></div>
                        
                        <p id="bulkActionMessage">Are you sure you want to perform this action?</p>
                        <div id="bulkActionDetails"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="bulkActionConfirmBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editDepartment(department) {
            document.getElementById('edit_id').value = department.id;
            document.getElementById('edit_name').value = department.name;
            document.getElementById('edit_description').value = department.description || '';
            document.getElementById('edit_manager_name').value = department.manager_name || '';
            document.getElementById('edit_budget_limit').value = department.budget_limit;
            document.getElementById('edit_sort_order').value = department.sort_order;
            document.getElementById('edit_is_active').checked = department.is_active == 1;
            
            new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
        }
        
        function deleteDepartment(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            
            new bootstrap.Modal(document.getElementById('deleteDepartmentModal')).show();
        }
        
        // Bulk Actions Functions
        function toggleAllDepartments() {
            const selectAll = document.getElementById('selectAllDepartments');
            const checkboxes = document.querySelectorAll('.department-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateBulkActions();
        }

        function toggleDepartmentSelection() {
            const selectAll = document.getElementById('selectAllDepartments');
            selectAll.checked = !selectAll.checked;
            toggleAllDepartments();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.department-checkbox:checked');
            const bulkActionsBtn = document.getElementById('bulkActionsBtn');
            const selectAllBtn = document.getElementById('selectAllDepartments');
            
            if (checkboxes.length > 0) {
                bulkActionsBtn.disabled = false;
                bulkActionsBtn.innerHTML = `<i class="bi bi-gear"></i> Bulk Actions (${checkboxes.length})`;
            } else {
                bulkActionsBtn.disabled = true;
                bulkActionsBtn.innerHTML = '<i class="bi bi-gear"></i> Bulk Actions';
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.department-checkbox');
            if (checkboxes.length === 0) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = false;
            } else if (checkboxes.length === allCheckboxes.length) {
                selectAllBtn.indeterminate = false;
                selectAllBtn.checked = true;
            } else {
                selectAllBtn.indeterminate = true;
            }
        }
        
        function getSelectedDepartments() {
            const checkboxes = document.querySelectorAll('.department-checkbox:checked');
            return Array.from(checkboxes).map(cb => ({
                id: cb.value,
                name: cb.closest('tr').dataset.departmentName,
                hasExpenses: cb.closest('tr').dataset.hasExpenses > 0,
                hasDependencies: cb.hasAttribute('data-has-dependencies')
            }));
        }
        
        function bulkDeleteDepartments() {
            const selectedDepartments = getSelectedDepartments();
            if (selectedDepartments.length === 0) {
                alert('Please select at least one department to delete.');
                return;
            }
            
            const deletableDepartments = selectedDepartments.filter(dept => !dept.hasDependencies);
            const nonDeletableDepartments = selectedDepartments.filter(dept => dept.hasDependencies);
            
            let message = `Delete ${selectedDepartments.length} selected departments?`;
            let details = '';
            
            if (deletableDepartments.length > 0) {
                details += `<div class="alert alert-success"><strong>Can be deleted (${deletableDepartments.length}):</strong><br>`;
                details += deletableDepartments.slice(0, 3).map(dept => dept.name).join(', ');
                if (deletableDepartments.length > 3) details += ` and ${deletableDepartments.length - 3} more`;
                details += '</div>';
            }
            
            if (nonDeletableDepartments.length > 0) {
                details += `<div class="alert alert-warning"><strong>Will be skipped (${nonDeletableDepartments.length}):</strong><br>`;
                details += nonDeletableDepartments.slice(0, 3).map(dept => dept.name).join(', ');
                if (nonDeletableDepartments.length > 3) details += ` and ${nonDeletableDepartments.length - 3} more`;
                details += '<br><small>These departments are used in expenses.</small></div>';
            }
            
            showBulkActionModal('bulk_delete', 'Delete Departments', message, details, selectedDepartments, 'danger');
        }
        
        function bulkActivateDepartments() {
            const selectedDepartments = getSelectedDepartments();
            if (selectedDepartments.length === 0) {
                alert('Please select at least one department to activate.');
                return;
            }
            
            const message = `Activate ${selectedDepartments.length} selected departments?`;
            const details = `<div class="alert alert-info">Departments: ${selectedDepartments.slice(0, 5).map(dept => dept.name).join(', ')}${selectedDepartments.length > 5 ? ` and ${selectedDepartments.length - 5} more` : ''}</div>`;
            
            showBulkActionModal('bulk_activate', 'Activate Departments', message, details, selectedDepartments, 'success');
        }
        
        function bulkDeactivateDepartments() {
            const selectedDepartments = getSelectedDepartments();
            if (selectedDepartments.length === 0) {
                alert('Please select at least one department to deactivate.');
                return;
            }
            
            const message = `Deactivate ${selectedDepartments.length} selected departments?`;
            const details = `<div class="alert alert-warning">Departments: ${selectedDepartments.slice(0, 5).map(dept => dept.name).join(', ')}${selectedDepartments.length > 5 ? ` and ${selectedDepartments.length - 5} more` : ''}</div>`;
            
            showBulkActionModal('bulk_deactivate', 'Deactivate Departments', message, details, selectedDepartments, 'warning');
        }
        
        function bulkExportDepartments() {
            const selectedDepartments = getSelectedDepartments();
            if (selectedDepartments.length === 0) {
                alert('Please select at least one department to export.');
                return;
            }

            // Create CSV content
            let csvContent = "Department Name,Manager,Budget Limit,Total Spent,Expense Count,Status\n";
            
            selectedDepartments.forEach(department => {
                const row = document.querySelector(`tr[data-department-id="${department.id}"]`);
                const manager = row.querySelector('td:nth-child(3)').textContent.trim();
                const budget = row.querySelector('td:nth-child(4)').textContent.trim();
                const spent = row.querySelector('td:nth-child(5)').textContent.trim().replace(/[^\d,.-]/g, '');
                const expenseCount = row.querySelector('td:nth-child(6)').textContent.trim().match(/\d+/)[0];
                const status = row.querySelector('td:nth-child(7)').textContent.trim();
                
                csvContent += `"${department.name}","${manager}","${budget}","${spent}","${expenseCount}","${status}"\n`;
            });

            // Download CSV
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `departments_export_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        function showBulkActionModal(action, title, message, details, departments, btnClass = 'primary') {
            document.getElementById('bulkAction').value = action;
            document.getElementById('bulkActionTitle').textContent = title;
            document.getElementById('bulkActionMessage').textContent = message;
            document.getElementById('bulkActionDetails').innerHTML = details;
            
            // Clear previous department IDs
            const container = document.getElementById('departmentIdsContainer');
            container.innerHTML = '';
            
            // Add hidden inputs for department IDs
            departments.forEach(department => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'department_ids[]';
                input.value = department.id;
                container.appendChild(input);
            });
            
            // Set button class
            const confirmBtn = document.getElementById('bulkActionConfirmBtn');
            confirmBtn.className = `btn btn-${btnClass}`;
            
            // Show modal
            new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
        }
    </script>
</body>
</html>
