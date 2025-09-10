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

// Check if user has permission to manage membership levels
if (!hasPermission('manage_loyalty', $permissions) && !hasPermission('manage_settings', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=access_denied");
    exit();
}

// Get system settings for theming
$settings = getSystemSettings($conn);

// Get centralized loyalty settings from Sales Dashboard
$loyaltySettings = getLoyaltySettings($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'create') {
            $levelData = [
                'level_name' => trim($_POST['level_name']),
                'level_description' => trim($_POST['level_description']),
                'points_multiplier' => (float)$_POST['points_multiplier'],
                'minimum_points_required' => (int)$_POST['minimum_points_required'],
                'color_code' => $_POST['color_code'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'sort_order' => (int)$_POST['sort_order']
            ];
            
            if (createMembershipLevel($conn, $levelData)) {
                $success_message = "Membership level created successfully!";
            } else {
                throw new Exception("Failed to create membership level");
            }
        } elseif ($action === 'update') {
            $levelId = (int)$_POST['level_id'];
            $levelData = [
                'level_name' => trim($_POST['level_name']),
                'level_description' => trim($_POST['level_description']),
                'points_multiplier' => (float)$_POST['points_multiplier'],
                'minimum_points_required' => (int)$_POST['minimum_points_required'],
                'color_code' => $_POST['color_code'],
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'sort_order' => (int)$_POST['sort_order']
            ];
            
            if (updateMembershipLevel($conn, $levelId, $levelData)) {
                $success_message = "Membership level updated successfully!";
            } else {
                throw new Exception("Failed to update membership level");
            }
        } elseif ($action === 'delete') {
            $levelId = (int)$_POST['level_id'];
            
            if (deleteMembershipLevel($conn, $levelId)) {
                $success_message = "Membership level deleted successfully!";
            } else {
                throw new Exception("Failed to delete membership level");
            }
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = $e->getMessage();
    }
}

// Get all membership levels
$membershipLevels = getAllMembershipLevels($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Levels - POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            background-color: #f8f9fa;
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <?php include '../include/navmenu.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">
                            <i class="bi bi-star me-2"></i>Membership Levels
                        </h1>
                        <p class="text-muted mb-0">Manage customer membership tiers integrated with <a href="../sales/loyalty-points.php" class="text-decoration-none">Sales Dashboard loyalty settings</a></p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#levelModal">
                        <i class="bi bi-plus-circle me-2"></i>Add New Level
                    </button>
                </div>

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

                <!-- Loyalty Settings Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-gear me-2"></i>Loyalty Program Settings
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Program Status:</span>
                                    <span class="badge <?php echo $loyaltySettings['enable_loyalty_program'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $loyaltySettings['enable_loyalty_program'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Points per Currency:</span>
                                    <span><?php echo $loyaltySettings['loyalty_points_per_currency'] ?? '1.0'; ?> points</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Minimum Purchase:</span>
                                    <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($loyaltySettings['loyalty_minimum_purchase'] ?? 0, 2); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Points Expiry:</span>
                                    <span><?php echo $loyaltySettings['loyalty_points_expiry_days'] ?? '365'; ?> days</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-semibold">Auto Level Upgrade:</span>
                                    <span class="badge <?php echo $loyaltySettings['loyalty_auto_level_upgrade'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $loyaltySettings['loyalty_auto_level_upgrade'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold">Points to Currency Rate:</span>
                                    <span>1:<?php echo $loyaltySettings['points_to_currency_rate'] ?? '100'; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="../sales/loyalty-points.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-gear me-1"></i>Manage Loyalty Settings
                            </a>
                            <small class="text-muted ms-2">Configure loyalty program settings in the Sales Dashboard</small>
                        </div>
                    </div>
                </div>

                <!-- Membership Levels Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list me-2"></i>Current Membership Levels
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($membershipLevels)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-star text-muted" style="font-size: 3rem;"></i>
                                <h5 class="text-muted mt-3">No membership levels found</h5>
                                <p class="text-muted">Create your first membership level to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Level</th>
                                            <th>Description</th>
                                            <th>Multiplier</th>
                                            <th>Min Points</th>
                                            <th>Color</th>
                                            <th>Order</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($membershipLevels as $level): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-2" style="width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($level['color_code']); ?>; border-radius: 50%;"></div>
                                                        <strong><?php echo htmlspecialchars($level['level_name']); ?></strong>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($level['level_description']); ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $level['points_multiplier']; ?>x</span>
                                                </td>
                                                <td><?php echo number_format($level['minimum_points_required']); ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-2" style="width: 30px; height: 20px; background-color: <?php echo htmlspecialchars($level['color_code']); ?>; border: 1px solid #ddd; border-radius: 4px;"></div>
                                                        <small><?php echo htmlspecialchars($level['color_code']); ?></small>
                                                    </div>
                                                </td>
                                                <td><?php echo $level['sort_order']; ?></td>
                                                <td>
                                                    <?php if ($level['is_active']): ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-primary" onclick="editLevel(<?php echo htmlspecialchars(json_encode($level)); ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" onclick="deleteLevel(<?php echo $level['id']; ?>, '<?php echo htmlspecialchars($level['level_name']); ?>')">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
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
        </div>
    </div>

    <!-- Level Modal -->
    <div class="modal fade" id="levelModal" tabindex="-1" aria-labelledby="levelModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="levelForm">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="level_id" id="levelId">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="levelModalLabel">
                            <i class="bi bi-star me-2"></i>Add New Membership Level
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="level_name" class="form-label">Level Name *</label>
                                    <input type="text" class="form-control" id="level_name" name="level_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="color_code" class="form-label">Color</label>
                                    <input type="color" class="form-control" id="color_code" name="color_code" value="#6c757d">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="level_description" class="form-label">Description</label>
                            <textarea class="form-control" id="level_description" name="level_description" rows="2"></textarea>
                        </div>
                        
                        <!-- Loyalty Settings Reference -->
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Loyalty Settings Reference:</strong> 
                            Current rate: <strong><?php echo $loyaltySettings['loyalty_points_per_currency'] ?? '1.0'; ?> points per <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?>1</strong> | 
                            Minimum purchase: <strong><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?><?php echo number_format($loyaltySettings['loyalty_minimum_purchase'] ?? 0, 2); ?></strong>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="points_multiplier" class="form-label">Points Multiplier *</label>
                                    <input type="number" class="form-control" id="points_multiplier" name="points_multiplier" 
                                           step="0.1" min="0.1" value="1.0" required>
                                    <div class="form-text">e.g., 1.5 = 1.5x points (based on loyalty settings)</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="minimum_points_required" class="form-label">Minimum Points Required</label>
                                    <input type="number" class="form-control" id="minimum_points_required" name="minimum_points_required" 
                                           min="0" value="0">
                                    <div class="form-text">Points needed to reach this level</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="sort_order" class="form-label">Sort Order</label>
                                    <input type="number" class="form-control" id="sort_order" name="sort_order" 
                                           min="0" value="0">
                                    <div class="form-text">Lower numbers appear first</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-2"></i>Save Level
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the membership level <strong id="deleteLevelName"></strong>?</p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="level_id" id="deleteLevelId">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editLevel(level) {
            document.getElementById('formAction').value = 'update';
            document.getElementById('levelId').value = level.id;
            document.getElementById('level_name').value = level.level_name;
            document.getElementById('level_description').value = level.level_description;
            document.getElementById('points_multiplier').value = level.points_multiplier;
            document.getElementById('minimum_points_required').value = level.minimum_points_required;
            document.getElementById('color_code').value = level.color_code;
            document.getElementById('sort_order').value = level.sort_order;
            document.getElementById('is_active').checked = level.is_active == 1;
            
            document.getElementById('levelModalLabel').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Membership Level';
            
            new bootstrap.Modal(document.getElementById('levelModal')).show();
        }
        
        function deleteLevel(levelId, levelName) {
            document.getElementById('deleteLevelId').value = levelId;
            document.getElementById('deleteLevelName').textContent = levelName;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Reset form when modal is hidden
        document.getElementById('levelModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('levelForm').reset();
            document.getElementById('formAction').value = 'create';
            document.getElementById('levelId').value = '';
            document.getElementById('levelModalLabel').innerHTML = '<i class="bi bi-star me-2"></i>Add New Membership Level';
        });
    </script>
</body>
</html>
