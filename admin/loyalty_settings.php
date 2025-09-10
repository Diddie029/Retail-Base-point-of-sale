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

// Check if user has permission to manage loyalty settings
if (!hasPermission('manage_loyalty', $permissions) && !hasPermission('manage_settings', $permissions)) {
    header("Location: ../dashboard/dashboard.php?error=access_denied");
    exit();
}

// Get system settings for theming
$settings = getSystemSettings($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $settings = [
            'enable_loyalty_program' => isset($_POST['enable_loyalty_program']) ? 1 : 0,
            'loyalty_points_per_currency' => (float)$_POST['loyalty_points_per_currency'],
            'loyalty_minimum_purchase' => (float)$_POST['loyalty_minimum_purchase'],
            'loyalty_points_expiry_days' => (int)$_POST['loyalty_points_expiry_days'],
            'loyalty_auto_level_upgrade' => isset($_POST['loyalty_auto_level_upgrade']) ? 1 : 0
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $conn->prepare("
                UPDATE pos_settings 
                SET setting_value = ? 
                WHERE setting_key = ?
            ");
            $stmt->execute([$value, $key]);
        }
        
        $conn->commit();
        $success_message = "Loyalty settings updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

// Get current settings
$currentSettings = getLoyaltySettings($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Settings - POS System</title>
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
                    <h1 class="h2">
                        <i class="bi bi-gift me-2"></i>Loyalty Program Settings
                    </h1>
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

                <form method="POST">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-gear me-2"></i>General Settings
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="enable_loyalty_program" 
                                                   name="enable_loyalty_program" <?php echo $currentSettings['enable_loyalty_program'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="enable_loyalty_program">
                                                <strong>Enable Loyalty Program</strong>
                                            </label>
                                            <div class="form-text">Allow customers to earn and redeem loyalty points</div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="loyalty_points_per_currency" class="form-label">Points per Currency Unit</label>
                                                <input type="number" class="form-control" id="loyalty_points_per_currency" 
                                                       name="loyalty_points_per_currency" step="0.1" min="0" 
                                                       value="<?php echo $currentSettings['points_per_currency']; ?>">
                                                <div class="form-text">Base points earned per currency unit (e.g., 1 point per $1)</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="loyalty_minimum_purchase" class="form-label">Minimum Purchase Amount</label>
                                                <input type="number" class="form-control" id="loyalty_minimum_purchase" 
                                                       name="loyalty_minimum_purchase" step="0.01" min="0" 
                                                       value="<?php echo $currentSettings['minimum_purchase']; ?>">
                                                <div class="form-text">Minimum purchase amount to earn points</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="loyalty_points_expiry_days" class="form-label">Points Expiry (Days)</label>
                                        <input type="number" class="form-control" id="loyalty_points_expiry_days" 
                                               name="loyalty_points_expiry_days" min="0" 
                                               value="<?php echo $currentSettings['points_expiry_days']; ?>">
                                        <div class="form-text">Days until loyalty points expire (0 = never expire)</div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="loyalty_auto_level_upgrade" 
                                                   name="loyalty_auto_level_upgrade" <?php echo $currentSettings['auto_level_upgrade'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="loyalty_auto_level_upgrade">
                                                <strong>Auto Level Upgrade</strong>
                                            </label>
                                            <div class="form-text">Automatically upgrade customer membership level based on points earned</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-star me-2"></i>Membership Levels
                                    </h5>
                                    <a href="membership_levels.php" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-gear me-1"></i>Manage Levels
                                    </a>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $membershipLevels = getAllMembershipLevels($conn);
                                    if (empty($membershipLevels)):
                                    ?>
                                        <div class="text-center py-3">
                                            <i class="bi bi-star text-muted" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2">No membership levels configured</p>
                                            <a href="membership_levels.php" class="btn btn-primary btn-sm">
                                                <i class="bi bi-plus-circle me-1"></i>Create First Level
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="row">
                                            <?php foreach ($membershipLevels as $level): ?>
                                                <div class="col-md-6 col-lg-4 mb-3">
                                                    <div class="card border-0 shadow-sm">
                                                        <div class="card-body">
                                                            <div class="d-flex align-items-center mb-2">
                                                                <div class="me-2" style="width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($level['color_code']); ?>; border-radius: 50%;"></div>
                                                                <h6 class="mb-0"><?php echo htmlspecialchars($level['level_name']); ?></h6>
                                                            </div>
                                                            <p class="text-muted small mb-2"><?php echo htmlspecialchars($level['level_description']); ?></p>
                                                            <div class="d-flex justify-content-between">
                                                                <span class="badge bg-primary"><?php echo $level['points_multiplier']; ?>x Multiplier</span>
                                                                <small class="text-muted"><?php echo number_format($level['minimum_points_required']); ?> pts</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-info-circle me-2"></i>How It Works
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <h6>Points Calculation:</h6>
                                    <p class="small">Points = Purchase Amount × Base Points × Membership Multiplier</p>
                                    
                                    <h6>Example:</h6>
                                    <ul class="small">
                                        <li>Bronze: $100 × 1.0 × 1.0 = 100 points</li>
                                        <li>Silver: $100 × 1.0 × 1.5 = 150 points</li>
                                        <li>Gold: $100 × 1.0 × 2.0 = 200 points</li>
                                    </ul>
                                    
                                    <h6>Redemption:</h6>
                                    <p class="small">100 points = 1 currency unit (configurable)</p>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-graph-up me-2"></i>Quick Stats
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    // Get some quick stats
                                    $totalCustomers = $conn->query("SELECT COUNT(*) FROM customers WHERE membership_status = 'active'")->fetchColumn();
                                    $totalPoints = $conn->query("SELECT SUM(points_earned) FROM loyalty_points WHERE transaction_type = 'earned'")->fetchColumn() ?: 0;
                                    $totalRedeemed = $conn->query("SELECT SUM(points_redeemed) FROM loyalty_points WHERE transaction_type = 'redeemed'")->fetchColumn() ?: 0;
                                    ?>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <h4 class="text-primary"><?php echo number_format($totalCustomers); ?></h4>
                                            <small class="text-muted">Active Customers</small>
                                        </div>
                                        <div class="col-6">
                                            <h4 class="text-success"><?php echo number_format($totalPoints - $totalRedeemed); ?></h4>
                                            <small class="text-muted">Points in Circulation</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-2"></i>Save Settings
                            </button>
                            <a href="../pos/sale.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to POS
                            </a>
                        </div>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
